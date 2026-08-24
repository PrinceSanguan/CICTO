<?php

namespace App\Console\Commands;

use App\Actions\Documents\StoreDocumentFile;
use App\Models\Document;
use App\Models\DocumentFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Attaches a real file to every document that has none, on whatever disk the
 * `documents` disk currently points at.
 *
 * `cicto:demo-data` creates the practice documents the client's checklist walks
 * through, but it creates them with no attachment -- RegisterDocument takes the
 * upload as an optional argument and the seeder does not pass one. That is fine
 * on a laptop, where the checklist steps that matter are routing and
 * visibility. It is not fine on the deployed host, where "open the document"
 * and "download it again after a deploy" are the two things that prove object
 * storage is actually wired up. Without a file there is nothing to click.
 *
 * WHY THIS IS NOT A DatabaseSeeder CLASS. A seeder runs under `db:seed`, and
 * `db:seed` is exactly what a deploy hook calls. This writes objects to a
 * bucket and creates immutable document versions; doing that on every deploy
 * would grow a new version of every document forever. It is a command, run on
 * purpose, that says what it did -- the same reasoning DemoDataCommand records
 * for staying out of DatabaseSeeder.
 *
 * WHY IT GOES THROUGH StoreDocumentFile RATHER THAN Storage::put(). The
 * document_files row carries a sha256 of the bytes, and §21 signature
 * verification re-hashes the file and compares. Writing the object directly and
 * inserting a row by hand means inventing that checksum; get it wrong and
 * `cicto:verify-signatures` reports tampering on a file nobody touched. Going
 * through the action that real uploads use means the version number, the
 * checksum, the disk and the path are all produced the one way the application
 * knows how to produce them.
 *
 * Safe to re-run. A document that already has a live (non-purged) file is
 * skipped, so this does not pile up versions the way a seeder in the deploy
 * hook would.
 */
class SeedStorageCommand extends Command
{
    protected $signature = 'cicto:seed-storage
                            {--force : Required in production, because this writes billable objects}
                            {--dry-run : Report what would be written without writing anything}
                            {--limit=0 : Stop after this many documents (0 means no limit)}';

    protected $description = 'Attach a sample PDF to every document that has no file, on the configured documents disk';

    public function handle(StoreDocumentFile $storeDocumentFile): int
    {
        $driver = (string) config('filesystems.disks.documents.driver');
        $dryRun = (bool) $this->option('dry-run');

        $this->components->info('Seeding the documents disk');

        $this->table(['Setting', 'Value'], [
            ['Disk driver', $driver],
            ['Bucket', $driver === 's3'
                ? (string) config('filesystems.disks.documents.bucket')
                : storage_path('app/documents')],
            ['Environment', app()->environment()],
            ['Mode', $dryRun ? 'DRY RUN (nothing written)' : 'WRITING'],
        ]);

        /*
         * The warning that matters most on this host, and the reason the whole
         * bucket exists. A local documents disk on a container platform is
         * emptied by the next deploy while the rows keep pointing at the
         * missing bytes -- so seeding onto it produces files that look fine
         * today and are gone tomorrow, with no error in between.
         */
        if ($driver === 'local' && ! app()->environment('local', 'testing')) {
            $this->components->warn(
                'The documents disk is LOCAL on a non-local environment. Anything written now '.
                'is destroyed by the next deploy. Set CICTO_DOCUMENTS_DRIVER=s3 first.'
            );
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->probeDisk()) {
            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');

        $query = Document::query()
            ->whereDoesntHave('files', fn ($files) => $files->whereNull('purged_at'))
            ->with(['documentType', 'originatingOffice', 'creator'])
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->components->info('Every document already has a file. Nothing to do.');

            return self::SUCCESS;
        }

        $attached = 0;
        $failed = 0;

        foreach ($documents as $document) {
            if ($dryRun) {
                $this->line(sprintf('  would attach  %s  %s', $document->control_number, $document->title));
                $attached++;

                continue;
            }

            try {
                $file = $this->attach($document, $storeDocumentFile);

                $this->line(sprintf(
                    '  <info>attached</info>  %s  %s  (%s)',
                    $document->control_number,
                    $document->title,
                    $file->path,
                ));

                $attached++;
            } catch (Throwable $e) {
                $this->line(sprintf(
                    '  <error>failed</error>    %s  %s',
                    $document->control_number,
                    mb_substr($e->getMessage(), 0, 90),
                ));

                $failed++;
            }
        }

        $this->newLine();
        $this->table(['Result', 'Count'], [
            ['Documents without a file', (string) $documents->count()],
            [$dryRun ? 'Would attach' : 'Attached', (string) $attached],
            ['Failed', (string) $failed],
        ]);

        if ($failed > 0) {
            $this->components->error('Some documents could not be given a file. See the lines above.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->components->info('Dry run only. Re-run without --dry-run to write.');
        }

        return self::SUCCESS;
    }

    /**
     * Prove the disk can be written to and read back before seeding onto it.
     *
     * The same reasoning as the SMTP handshake in cicto:host-check: a wrong
     * bucket name, a revoked access key and a missing CICTO_DOCUMENTS_DRIVER
     * all leave the config looking correct. Finding out on document one is
     * cheap; finding out after a partial run means reasoning about which
     * documents got a version and which did not.
     */
    private function probeDisk(): bool
    {
        $probe = 'seed-storage-probe-'.bin2hex(random_bytes(4));

        try {
            $disk = Storage::disk('documents');
            $disk->put($probe, 'ok');
            $readBack = $disk->get($probe);
            $disk->delete($probe);

            if ($readBack !== 'ok') {
                $this->components->error('The documents disk accepted a write but read back something else.');

                return false;
            }
        } catch (Throwable $e) {
            $this->components->error('The documents disk is not writable: '.mb_substr($e->getMessage(), 0, 120));

            return false;
        }

        $this->components->info('Disk probe OK (wrote, read back and deleted a test object).');

        return true;
    }

    /**
     * Render a one-page PDF for this document and store it as version 1.
     *
     * Attributed to the document's own creator, which is what a real upload
     * would have recorded. No fallback account is needed: documents.created_by_id
     * is NOT NULL and constrained restrictOnDelete, so the creator cannot have
     * been deleted out from under the row.
     */
    private function attach(
        Document $document,
        StoreDocumentFile $storeDocumentFile,
    ): DocumentFile {
        $pdf = Pdf::loadView('documents.seed-placeholder', [
            'document' => $document,
            'office' => $document->originatingOffice->name,
            'type' => $document->documentType->name,
            'priority' => $document->priority->label(),
            'generatedAt' => now()->toDayDateTimeString(),
        ])->output();

        /*
         * A real temp file, because UploadedFile hashes and sizes it from disk
         * and StoreDocumentFile calls hash_file() on getRealPath(). An in-memory
         * stream would have no real path to give it.
         */
        $tmp = tempnam(sys_get_temp_dir(), 'cicto-seed-').'.pdf';
        file_put_contents($tmp, $pdf);

        try {
            $upload = new UploadedFile(
                $tmp,
                $this->filenameFor($document),
                'application/pdf',
                null,
                true, // test mode: this was not moved here by a real HTTP upload
            );

            return $storeDocumentFile->handle($document, $upload, $document->creator);
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
    }

    /** A readable original_name, since that is what the download response uses. */
    private function filenameFor(Document $document): string
    {
        $slug = str($document->title)->slug()->limit(60, '')->value();

        return ($slug === '' ? 'document' : $slug).'-'.$document->control_number.'.pdf';
    }
}
