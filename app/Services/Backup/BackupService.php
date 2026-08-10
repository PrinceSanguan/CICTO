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
        ];
    }

    public function run(?User $triggeredBy = null): BackupRun
    {
        $dumper = $this->dumper();
        $disk = (string) config('cicto.backup.disk');
        $stamp = Deadlines::now()->format('Ymd-His');

        $extension = $dumper->includesSchema() ? 'sql' : 'sql.gz';

        // Second resolution is not enough on its own: a scheduled run and a
        // manual one can land in the same second, and the loser's row would
        // then describe bytes it did not write. A short random suffix makes the
        // name unique without making it unreadable.
        $suffix = mb_strtolower(mb_substr((string) Str::ulid(), -6));
        $relative = "cicto-{$stamp}-{$suffix}.{$extension}";

        $run = BackupRun::create([
            'kind' => 'database',
            'status' => BackupStatus::Running->value,
            'driver' => $dumper->name(),
            'disk' => $disk,
            'path' => $relative,
            'last_migration' => $this->lastMigration(),
            'started_at' => Deadlines::now(),
            'triggered_by_id' => $triggeredBy?->id,
        ]);

        // Ensure the directory exists, then dump to the real filesystem path --
        // a dumper writes with fopen/proc_open, not through Flysystem.
        Storage::disk($disk)->makeDirectory('/');
        $absolute = Storage::disk($disk)->path($relative);

        try {
            $bytes = $dumper->dump($absolute);

            if ($bytes <= 0) {
                throw new RuntimeException('The dump produced an empty file.');
            }

            $checksum = hash_file('sha256', $absolute);
        } catch (Throwable $e) {
            // A failed run must survive as a record. A backup system that goes
            // quiet when it breaks is worse than none, because everyone assumes
            // it is working.
            $run->forceFill([
                'status' => BackupStatus::Failed->value,
                'finished_at' => Deadlines::now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            if (Storage::disk($disk)->exists($relative)) {
                Storage::disk($disk)->delete($relative);
            }

            SecurityEvent::log(
                SecurityEventType::BackupFailed,
                sprintf('Backup FAILED (%s): %s', $dumper->name(), mb_substr($e->getMessage(), 0, 150)),
                $triggeredBy,
                $relative,
            );

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
