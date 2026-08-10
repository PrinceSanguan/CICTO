<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * §22. Run this from cron:
 *
 *   * * * * * cd /path/to/cicto && php artisan schedule:run >> /dev/null 2>&1
 *
 * If the host has no cron, "automated regular backups" is NOT delivered and no
 * code change can deliver it -- see the decision tree in
 * docs/implementation/phase-3-trust-and-toolchain.md. It degrades to a person
 * clicking Run Backup Now, and the client has to be told that in writing.
 */
class BackupRunCommand extends Command
{
    protected $signature = 'cicto:backup {--probe : Report host capabilities without backing up}';

    protected $description = 'Back up the database and record the run';

    public function handle(BackupService $backups): int
    {
        if ($this->option('probe')) {
            foreach ($backups->capabilities() as $key => $value) {
                $this->line(sprintf(
                    '  %-18s %s',
                    $key,
                    is_bool($value) ? ($value ? 'yes' : 'NO') : (string) $value,
                ));
            }

            if (! $backups->capabilities()['has_ever_restored']) {
                $this->newLine();
                $this->warn('No restore has ever been recorded. Until one is, these backups are untested.');
            }

            return self::SUCCESS;
        }

        try {
            $run = $backups->run();
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            $this->line('The failure was recorded in backup_runs.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup complete: %s (%s, %s via %s dumper).',
            $run->path,
            $run->humanSize(),
            $run->checksum_sha256 === null ? 'no checksum' : 'sha256 '.substr($run->checksum_sha256, 0, 12),
            $run->driver,
        ));

        if ($run->driver === 'php') {
            $this->warn('This dump contains DATA ONLY. Restore with `php artisan migrate` first, then load the file.');
        }

        return self::SUCCESS;
    }
}
