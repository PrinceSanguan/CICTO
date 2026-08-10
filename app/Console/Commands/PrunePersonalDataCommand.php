<?php

namespace App\Console\Commands;

use App\Models\DocumentScan;
use App\Models\SecurityEvent;
use App\Support\Deadlines;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * RA 10173 (Data Privacy Act) retention.
 *
 * document_scans and security_events both store ip_address and user_agent,
 * which are personal information. Keeping them forever is not defensible, and
 * "we never got round to deleting it" is not a retention policy.
 *
 * Like the version pruner, this ships DISABLED and reports by default. The
 * retention period is stated on the privacy notice, so changing it here without
 * changing that page makes the notice a lie.
 */
class PrunePersonalDataCommand extends Command
{
    protected $signature = 'cicto:prune-personal-data {--force : Actually delete}';

    protected $description = 'Delete scan and security-event records past their stated retention period';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $total = 0;

        $total += $this->prune(
            'document scans',
            (bool) config('cicto.scans.pruning_enabled'),
            (int) config('cicto.scans.retention_days', 180),
            fn (int $days) => DocumentScan::query()
                ->where('scanned_at', '<', Deadlines::now()->subDays($days)),
            $force,
        );

        $total += $this->prune(
            'security events',
            (bool) config('cicto.retention.security_events.enabled'),
            (int) config('cicto.retention.security_events.after_days', 365),
            fn (int $days) => SecurityEvent::query()
                ->where('created_at', '<', Deadlines::now()->subDays($days)),
            $force,
        );

        $this->newLine();
        $this->info($force ? "Deleted {$total} record(s)." : "{$total} record(s) are past retention.");

        return self::SUCCESS;
    }

    /**
     * @param  callable(int): Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function prune(string $label, bool $enabled, int $days, callable $query, bool $force): int
    {
        $count = $query($days)->count();

        if (! $enabled) {
            $this->warn(sprintf(
                '%s: pruning disabled — %d record(s) older than %d days would be deleted.',
                $label,
                $count,
                $days,
            ));

            return $count;
        }

        if (! $force) {
            $this->line(sprintf('[dry run] %s: %d record(s) older than %d days.', $label, $count, $days));

            return $count;
        }

        // chunked delete: a single DELETE over a year of rows locks the table
        // for long enough to time out a request on shared hosting.
        $deleted = 0;

        while (($batch = $query($days)->limit(1000)->delete()) > 0) {
            $deleted += $batch;
        }

        $this->line(sprintf('%s: deleted %d record(s).', $label, $deleted));

        return $deleted;
    }
}
