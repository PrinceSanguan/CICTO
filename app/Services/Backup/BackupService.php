<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Enums\SecurityEventType;
use App\Models\BackupRun;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Deadlines;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * §22 Backup and Recovery.
 *
 * First-party rather than spatie/laravel-backup, because that package's core
 * value is the shell-out dumper this host may not permit, plus destinations
 * this contract cannot afford. What it would leave behind is a package-shaped
 * failure that someone has to debug through package docs at 21:00.
 *
 * Every run is recorded whether it succeeds or fails -- listing files on a disk
 * cannot tell you that last night's backup did not happen.
 */
class BackupService
{
    public function __construct(
        private readonly ShellDumper $shell,
        private readonly PhpDumper $php,
    ) {}

    /**
     * Which dumper this host can actually use.
     *
     * config 'auto' probes; 'shell'/'php' force a choice, and forcing shell on
     * a host that cannot run it fails loudly rather than silently degrading.
     */
    public function dumper(): Dumper
    {
        $configured = (string) config('cicto.backup.driver', 'auto');

        if ($configured === 'php') {
            return $this->php;
        }

        if ($configured === 'shell') {
            if (! $this->shell->isSupported()) {
                throw new RuntimeException(
                    'CICTO_BACKUP_DRIVER=shell but proc_open or the database client binary is unavailable on this host.',
                );
            }

            return $this->shell;
        }

        return $this->shell->isSupported() ? $this->shell : $this->php;
    }

    /**
     * @return array<string, mixed> a snapshot of what this host can do
     */
    public function capabilities(): array
    {
        return [
            'proc_open' => function_exists('proc_open'),
            'zip' => class_exists(\ZipArchive::class),
            'shell_dumper' => $this->shell->isSupported(),
            'driver' => $this->dumper()->name(),
            'includes_schema' => $this->dumper()->includesSchema(),
            'disk' => (string) config('cicto.backup.disk'),
            'has_ever_restored' => BackupRun::hasEverBeenRestored(),
            'includes_files' => $this->canArchiveFiles(),
        ];
    }

    public function run(?User $triggeredBy = null): BackupRun
    {
        // The row is created FIRST, before anything that can fail.
        //
        // dumper() throws when CICTO_BACKUP_DRIVER=shell on a host that cannot
        // shell out, and makeDirectory() throws on an unwritable disk. Both used
        // to happen before any backup_runs row existed, so the run vanished
        // entirely while the console still printed that the failure had been
        // recorded -- the exact silence a backup system must never produce.
        $run = BackupRun::create([
            'kind' => 'database',
            'status' => BackupStatus::Running->value,
            'driver' => 'pending',
            'disk' => (string) config('cicto.backup.disk'),
            'path' => null,
            'last_migration' => $this->lastMigration(),
            'started_at' => Deadlines::now(),
            'triggered_by_id' => $triggeredBy?->id,
        ]);

        try {
            return $this->perform($run, $triggeredBy);
        } catch (Throwable $e) {
            if ($run->status === BackupStatus::Running) {
                $this->recordFailure($run, $e, $triggeredBy);
            }

            throw $e;
        }
    }

    private function perform(BackupRun $run, ?User $triggeredBy): BackupRun
    {
        $dumper = $this->dumper();
        $disk = (string) config('cicto.backup.disk');
        $stamp = Deadlines::now()->format('Ymd-His');

        $extension = $dumper->includesSchema() ? 'sql' : 'sql.gz';

        // A database-only backup is not a backup of this system. Every
        // document_files row points at bytes on the documents disk, and every
        // signature is a hash OF those bytes -- restore the database alone and
        // the register describes files that no longer exist while the nightly
        // sweep reports the entire signed record set as tampered.
        $withFiles = $this->canArchiveFiles();

        // Second resolution is not enough on its own: a scheduled run and a
        // manual one can land in the same second, and the loser's row would
        // then describe bytes it did not write. A short random suffix makes the
        // name unique without making it unreadable.
        $suffix = mb_strtolower(mb_substr((string) Str::ulid(), -6));
        $relative = "cicto-{$stamp}-{$suffix}.{$extension}";

        $run->forceFill([
            'kind' => $withFiles ? 'full' : 'database',
            'driver' => $dumper->name(),
            'disk' => $disk,
            'path' => $relative,
        ])->save();

        // Ensure the directory exists, then dump to the real filesystem path --
        // a dumper writes with fopen/proc_open, not through Flysystem.
        Storage::disk($disk)->makeDirectory('/');
        $absolute = Storage::disk($disk)->path($relative);

        try {
            $bytes = $dumper->dump($absolute);

            if ($bytes <= 0) {
                throw new RuntimeException('The dump produced an empty file.');
            }

            if ($withFiles) {
                // Fold the dump and the uploaded documents into one archive, so
                // the two can never be separated in transit or restored out of
                // step with each other.
                $archive = "cicto-{$stamp}-{$suffix}.zip";
                $bytes = $this->archive($absolute, Storage::disk($disk)->path($archive));

                @unlink($absolute);
                $relative = $archive;
                $run->forceFill(['path' => $relative])->save();
                $absolute = Storage::disk($disk)->path($relative);
            }

            $checksum = hash_file('sha256', $absolute);
        } catch (Throwable $e) {
            $this->recordFailure($run, $e, $triggeredBy);

            throw $e;
        }

        // Everything past this point operates on a backup that EXISTS and is
        // verified. It is deliberately outside the catch: an audit-log write or
        // a prune failing is not a reason to mark a good backup Failed and
        // delete the only copy of last night's data.
        $run->forceFill([
            'status' => BackupStatus::Completed->value,
            'size_bytes' => $bytes,
            'checksum_sha256' => $checksum === false ? null : $checksum,
            'finished_at' => Deadlines::now(),
        ])->save();

        SecurityEvent::log(
            SecurityEventType::BackupCompleted,
            sprintf('Backup completed (%s, %s).', $dumper->name(), $run->humanSize() ?? '0 B'),
            $triggeredBy,
            $relative,
        );

        $this->pruneOldRuns();

        return $run->refresh();
    }

    /**
     * Record that a restore was actually performed.
     *
     * §22 says Backup AND Recovery, and until this has been called once the
     * backups are an untested hypothesis. The UI keeps saying so.
     */
    public function recordRestore(BackupRun $run, User $by, ?string $notes = null): BackupRun
    {
        $run->forceFill([
            'restored_at' => Deadlines::now(),
            'restore_notes' => $notes,
        ])->save();

        SecurityEvent::log(
            SecurityEventType::BackupRestored,
            sprintf('Restore drill recorded against backup #%d by %s.', $run->id, $by->name),
            $by,
            (string) $run->path,
        );

        return $run;
    }

    /**
     * Mark a run failed and clean up after it.
     *
     * A failed run must survive as a record. A backup system that goes quiet
     * when it breaks is worse than none, because everyone assumes it works.
     */
    private function recordFailure(BackupRun $run, Throwable $e, ?User $triggeredBy): void
    {
        $run->forceFill([
            'status' => BackupStatus::Failed->value,
            'finished_at' => Deadlines::now(),
            'error' => mb_substr($e->getMessage(), 0, 2000),
        ])->save();

        $disk = (string) ($run->disk ?? config('cicto.backup.disk'));

        try {
            if ($run->path !== null && Storage::disk($disk)->exists($run->path)) {
                Storage::disk($disk)->delete($run->path);
            }
        } catch (Throwable) {
            // A disk we cannot reach is why we are here. Do not mask the
            // original failure with a second one.
        }

        SecurityEvent::log(
            SecurityEventType::BackupFailed,
            sprintf('Backup FAILED: %s', mb_substr($e->getMessage(), 0, 150)),
            $triggeredBy,
            $run->path,
        );
    }

    /**
     * Whether uploaded documents can be folded into the backup.
     *
     * Needs ZipArchive and a local documents disk. Shared hosting sometimes
     * lacks the extension, and a remote disk cannot be walked cheaply -- in
     * either case the backup falls back to database-only and says so, rather
     * than pretending the files are covered.
     */
    public function canArchiveFiles(): bool
    {
        if (! class_exists(\ZipArchive::class)) {
            return false;
        }

        // A remote disk cannot be walked cheaply, so file coverage is only
        // claimed for a local one.
        return config('filesystems.disks.documents.driver') === 'local';
    }

    /**
     * Build a single archive holding the SQL dump and every uploaded document.
     *
     * Written to a temporary name and renamed on success, so an interrupted run
     * never leaves a half-written archive where a restore would find it and
     * trust it.
     *
     * @return int bytes of the finished archive
     */
    private function archive(string $dump, string $target): int
    {
        $zip = new \ZipArchive;
        $temporary = $target.'.part';

        if ($zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create the backup archive at {$target}.");
        }

        $zip->addFile($dump, 'database/'.basename($dump));

        // Read from the DISK, not from config: anything that rebinds the disk
        // at runtime -- a test fake, a runtime reconfiguration -- leaves the
        // config value pointing at a directory nothing is written to, and the
        // archive would silently contain no documents at all.
        $root = rtrim(Storage::disk('documents')->path(''), '/');

        if (is_dir($root)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $zip->addFile(
                        $file->getPathname(),
                        'documents/'.ltrim(substr($file->getPathname(), strlen($root)), '/'),
                    );
                }
            }
        }

        if (! $zip->close()) {
            @unlink($temporary);

            throw new RuntimeException('The backup archive could not be finalised; the disk may be full.');
        }

        if (! rename($temporary, $target)) {
            @unlink($temporary);

            throw new RuntimeException('The backup archive could not be moved into place.');
        }

        clearstatcache(true, $target);

        return (int) filesize($target);
    }

    /**
     * Keeps the archive directory from growing without bound.
     *
     * Each old run is deleted from the disk IT was written to, recorded on the
     * row -- not from whatever CICTO_BACKUP_DISK happens to say today. Using
     * the current disk means that after the setting changes, old files are
     * orphaned forever while their rows report them as pruned, and a same-named
     * file on the new disk gets deleted instead.
     */
    private function pruneOldRuns(): void
    {
        $keep = max(1, (int) config('cicto.backup.keep_runs', 14));

        BackupRun::query()
            ->successful()
            ->orderByDesc('started_at')
            ->skip($keep)
            ->take(100)
            ->get()
            ->each(function (BackupRun $old): void {
                $disk = $old->disk ?? (string) config('cicto.backup.disk');

                try {
                    if ($old->path !== null && Storage::disk($disk)->exists($old->path)) {
                        Storage::disk($disk)->delete($old->path);
                    }
                } catch (Throwable) {
                    // A disk that no longer exists in config should not take
                    // down the backup that just succeeded. Leave the row's path
                    // intact so the file is still findable by hand.
                    return;
                }

                // The row stays. History of what ran is the point.
                $old->forceFill(['path' => null])->save();
            });
    }

    private function lastMigration(): ?string
    {
        try {
            return DB::table('migrations')->orderByDesc('id')->value('migration');
        } catch (Throwable) {
            return null;
        }
    }
}
