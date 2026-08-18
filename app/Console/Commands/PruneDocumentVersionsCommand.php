<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Models\DocumentFile;
use App\Support\Deadlines;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Feature #10's other half: storage that does not grow forever.
 *
 * At 150 documents a day with one revision each, document_files reaches roughly
 * 105 GB in year one -- and on shared cPanel the INODE cap (200k-500k files,
 * counting vendor/ and node_modules/) bites well before the disk quota does,
 * with a baffling symptom: sessions failing to write.
 *
 * What it deletes: the BYTES of intermediate versions of finished documents.
 * The metadata row stays, with purged_at set, so the version history never
 * develops holes and a download returns an honest 410 rather than a 404.
 *
 * What it never deletes: version 1 (the original submission) and the current
 * version. Those are the two a records office is actually asked for.
 *
 * Ships DISABLED and --dry-run by default. Turning it on is a decision for the
 * client (question B6), not for whoever deploys next.
 */
class PruneDocumentVersionsCommand extends Command
{
    protected $signature = 'cicto:prune-versions
                            {--force : Actually delete. Without this the command only reports}
                            {--days= : Override the retention window}';

    protected $description = 'Purge the bytes of intermediate file versions for long-finished documents';

    public function handle(): int
    {
        $config = config('cicto.retention.versions');
        $force = (bool) $this->option('force');

        /*
         * `--days=` with nothing after it, or `--days=soon`, both cast to 0 --
         * a cutoff of "now", which selects every finished document ever. The
         * retention window is three years now (the client's floor), so an
         * operator who wants to see what a shorter window would reclaim is far
         * more likely to reach for this flag than they were at 180 days.
         * Refusing beats a silent full sweep.
         */
        $override = $this->option('days');

        if ($override !== null && filter_var($override, FILTER_VALIDATE_INT) === false) {
            $this->error('--days must be a whole number of days.');

            return self::FAILURE;
        }

        $days = (int) ($override ?? $config['after_days']);

        if ($days < 1) {
            $this->error('--days must be at least 1. A window of 0 would purge every finished document.');

            return self::FAILURE;
        }

        if (! $config['enabled']) {
            $this->warn('Version pruning is disabled (CICTO_PRUNE_VERSIONS_ENABLED=false).');
            $this->line('Enable it only once a retention policy is agreed in writing and an off-site backup exists.');

            if ($force) {
                $this->error('Refusing to --force while disabled.');

                return self::FAILURE;
            }

            // Fall through and report what WOULD go, so an operator can see the
            // scale of it before deciding whether to enable the policy.
            $force = false;
        }

        $cutoff = Deadlines::now()->subDays($days);

        $candidates = DocumentFile::query()
            ->whereHas('document', fn ($q) => $q
                ->whereIn('status', DocumentStatus::terminalValues())
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $cutoff))
            ->withCount('signatures')
            ->with('document')
            ->get()
            ->groupBy('document_id')
            ->flatMap(function ($files) {
                // "First" and "current" are decided against EVERY version of
                // the document, including signed and already-purged ones.
                //
                // Filtering those out first and then slicing meant the slice
                // ran over a reduced list: with v1 signed, v2 became element 0
                // and was protected as though it were the original submission,
                // so a three-version document reclaimed nothing at all.
                $sorted = $files->sortBy('version')->values();

                return $sorted
                    ->slice(1, max(0, $sorted->count() - 2))
                    // NEVER purge a version somebody signed.
                    //
                    // The signature would survive the purge and keep reporting
                    // "valid", because it compares the checksum recorded at
                    // signing time and that column is untouched. The result is
                    // a Signature Certificate attesting to bytes the system
                    // deliberately deleted -- worse than a mismatch, because it
                    // looks fine.
                    ->reject(fn (DocumentFile $file) => $file->signatures_count > 0)
                    // Already reclaimed on an earlier run.
                    ->reject(fn (DocumentFile $file) => $file->purged_at !== null);
            });

        if ($candidates->isEmpty()) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        $bytes = $candidates->sum('size_bytes');

        $this->line(sprintf(
            '%s%d intermediate version(s) across %d document(s), %s.',
            $force ? '' : '[dry run] ',
            $candidates->count(),
            $candidates->pluck('document_id')->unique()->count(),
            $this->humanBytes((int) $bytes),
        ));

        if (! $force) {
            foreach ($candidates->take(10) as $file) {
                $this->line("  would purge {$file->document->control_number} v{$file->version} ({$file->original_name})");
            }

            return self::SUCCESS;
        }

        $purged = 0;

        foreach ($candidates as $file) {
            try {
                Storage::disk($file->disk)->delete($file->path);
                // The row survives. Only the bytes go.
                $file->forceFill(['purged_at' => Deadlines::now()])->save();
                $purged++;
            } catch (\Throwable $e) {
                $this->error("  failed on file #{$file->id}: {$e->getMessage()}");
            }
        }

        $this->info("Purged {$purged} version(s), reclaimed ".$this->humanBytes((int) $bytes).'.');

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => round($bytes / 1_073_741_824, 2).' GB',
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
