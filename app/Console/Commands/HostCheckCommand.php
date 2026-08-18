<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The pre-deployment probe from docs/handover/DEPLOYMENT.md.
 *
 * Every risk this project carries is a hosting assumption: HTTPS for the camera
 * scanner, cron for deadlines and backups, proc_open for pg_dump, SMTP for
 * password resets. They are cheap to check and expensive to discover on
 * go-live day, so this checks them all in one pass and says plainly which are
 * missing and what happens as a result.
 *
 * Exits 0 even when things are missing: this is a report, not a gate. A host
 * with no cron can still be deployed to -- somebody just has to know.
 */
class HostCheckCommand extends Command
{
    protected $signature = 'cicto:host-check';

    protected $description = 'Report what this host can and cannot do, before deploying to it';

    public function handle(BackupService $backups): int
    {
        $this->line('');
        $this->info('CICTO host capability check');
        $this->line(str_repeat('=', 56));

        $rows = [
            ...$this->php(),
            ...$this->database(),
            ...$this->storage(),
            ...$this->mail(),
            ...$this->scanning(),
            ...$this->backups($backups),
        ];

        $this->table(['Check', 'Result', 'Consequence if missing'], $rows);

        $missing = collect($rows)->filter(fn (array $row) => str_starts_with($row[1], 'NO'))->count();

        $this->line('');

        if ($missing === 0) {
            $this->info('Everything this deployment needs is available.');
        } else {
            $this->warn("{$missing} capability/capabilities missing. Each one above says what it costs.");
            $this->line('None of them prevents deployment. All of them need saying out loud first.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function php(): array
    {
        $version = PHP_VERSION;
        $procOpen = function_exists('proc_open')
            && ! in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);

        return [
            ['PHP version', 'OK '.$version, 'Requires 8.3+'],
            [
                'proc_open',
                $procOpen ? 'OK' : 'NO',
                'pg_dump/mysqldump unavailable; the slower PHP dumper takes over',
            ],
            [
                'ZipArchive',
                class_exists(\ZipArchive::class) ? 'OK' : 'NO',
                'Excel export unavailable; CSV still works',
            ],
            [
                'GD or Imagick',
                extension_loaded('gd') || extension_loaded('imagick') ? 'OK' : 'NO',
                'Drawn signature images cannot be processed',
            ],
            [
                'upload_max_filesize',
                (string) ini_get('upload_max_filesize'),
                'Uploads larger than this are cut off before the app sees them',
            ],
            [
                'post_max_size',
                (string) ini_get('post_max_size'),
                'Must exceed upload_max_filesize or uploads fail silently',
            ],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function database(): array
    {
        try {
            $driver = DB::connection()->getDriverName();
            DB::connection()->getPdo();

            return [['Database', 'OK '.$driver, 'The application cannot run without it']];
        } catch (Throwable $e) {
            return [['Database', 'NO', 'Cannot connect: '.mb_substr($e->getMessage(), 0, 60)]];
        }
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function storage(): array
    {
        $rows = [];

        foreach (['documents', 'backups'] as $disk) {
            $driver = (string) config("filesystems.disks.{$disk}.driver");

            try {
                $probe = 'host-check-'.bin2hex(random_bytes(4));
                Storage::disk($disk)->put($probe, 'ok');
                $readable = Storage::disk($disk)->get($probe) === 'ok';
                Storage::disk($disk)->delete($probe);

                $rows[] = [
                    "Disk: {$disk}",
                    ($readable ? 'OK' : 'NO').' '.$driver,
                    'Uploads or backups cannot be written',
                ];
            } catch (Throwable $e) {
                $rows[] = [
                    "Disk: {$disk}",
                    'NO '.$driver,
                    mb_substr($e->getMessage(), 0, 60),
                ];
            }
        }

        /*
         * The one storage failure that reports OK and still loses everything.
         *
         * A local disk is correct on a VPS and fatal on a container platform,
         * where the filesystem is reset by every deploy and each replica has
         * its own. The write probe above passes either way -- it writes and
         * reads back inside one request -- so nothing else in this command
         * would ever notice. Name it here, because this is the command the
         * runbook tells an operator to run before committing to a host.
         */
        if (config('filesystems.disks.documents.driver') === 'local') {
            $rows[] = [
                'Documents durable?',
                'CHECK',
                'Local disk: fine on a VPS, TOTAL LOSS on Laravel Cloud or any container host',
            ];
        }

        return $rows;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function mail(): array
    {
        $mailer = (string) config('mail.default');
        $usable = ! in_array($mailer, ['log', 'array'], true);

        return [[
            'Outgoing mail',
            $usable ? 'OK '.$mailer : 'NO ('.$mailer.')',
            'Password reset, deadline notices and support tickets do not send',
        ]];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function scanning(): array
    {
        $base = (string) config('cicto.scan_base_url');
        $https = str_starts_with($base, 'https://');

        return [[
            'Scan URL is HTTPS',
            $base === '' ? 'NO (unset)' : ($https ? 'OK' : 'NO ('.$base.')'),
            'Camera scanning is blocked by browsers; USB scanners still work',
        ]];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function backups(BackupService $backups): array
    {
        $capabilities = $backups->capabilities();

        return [
            [
                'Backup driver',
                'OK '.$capabilities['driver'],
                $capabilities['includes_schema']
                    ? 'Dump includes schema; restore is a single load'
                    : 'Data-only dump: run `php artisan migrate` BEFORE loading it',
            ],
            [
                'Restore drilled',
                $capabilities['has_ever_restored'] ? 'OK' : 'NO',
                'Backups are an untested hypothesis until one has been restored',
            ],
        ];
    }
}
