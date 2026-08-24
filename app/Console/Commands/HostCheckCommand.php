<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Support\OutgoingMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
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
         *
         * BOTH disks, not just documents. This check covered `documents` alone
         * for long enough to matter: a deployed environment ran with backups on
         * the container's own disk while this table printed "Disk: backups | OK
         * local" beside it, so every archive was destroyed by the next deploy
         * and the one command written to say so said nothing.
         */
        foreach (['documents' => 'Documents', 'backups' => 'Backups'] as $disk => $label) {
            if (config("filesystems.disks.{$disk}.driver") === 'local') {
                $rows[] = [
                    "{$label} durable?",
                    'CHECK',
                    'Local disk: fine on a VPS, TOTAL LOSS on Laravel Cloud or any container host',
                ];
            }
        }

        return $rows;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function mail(): array
    {
        $usable = OutgoingMail::isConfigured();

        $rows = [[
            'Outgoing mail',
            $usable ? 'OK '.OutgoingMail::mailer() : 'NO ('.OutgoingMail::mailer().')',
            'No reset links, deadline notices or tickets send; a Super Admin sets passwords by hand instead',
        ]];

        /*
         * The second half of a working mail setup, and the half that is missed.
         *
         * Client question B3 points deployments at an external service, Google
         * SMTP by name. Gmail refuses to send as an address that is not the
         * account it authenticated -- so a host with MAIL_USERNAME and
         * MAIL_PASSWORD filled in and MAIL_FROM_ADDRESS left at the framework's
         * placeholder connects, authenticates, and then has every message
         * rejected at send time. The row above says OK throughout.
         *
         * Only asked once there is a transport to ask it about; on the shipping
         * default it is not a finding, it is just the placeholder sitting where
         * nothing reads it.
         */
        if ($usable) {
            $from = OutgoingMail::fromAddress();

            $rows[] = [
                'Mail From address',
                OutgoingMail::fromAddressIsPlaceholder()
                    ? 'NO ('.($from === '' ? 'unset' : $from).')'
                    : 'OK '.$from,
                'Google SMTP and most providers reject a From that is not the authenticated account',
            ];

            $rows[] = $this->smtpHandshake();
        }

        return $rows;
    }

    /**
     * Actually open the connection and authenticate.
     *
     * Everything above this reads config and reports what it finds, which is
     * the whole problem: a revoked App Password, a typo in it, a host that
     * blocks outbound 587, and 2-Step Verification switched back off all leave
     * the config looking perfect. This command exists to be run before
     * deploying, and answering "yes, mail works" from settings alone is exactly
     * the kind of confident wrong answer it is meant to catch.
     *
     * Nothing is sent. The dialogue stops after AUTH, so this costs no quota
     * and lands in nobody's inbox.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function smtpHandshake(): array
    {
        $consequence = 'Config can look perfect while the password is wrong, expired, or port 587 is blocked';

        $mailer = (string) config('mail.default');
        $transport = config('mail.mailers.'.$mailer.'.transport');

        // Only smtp has a handshake to make. A future ses/postmark deployment
        // is an API key, not a socket, and would need its own probe.
        if ($transport !== 'smtp') {
            return ['SMTP handshake', 'SKIP (transport '.(is_string($transport) ? $transport : 'unknown').')', $consequence];
        }

        try {
            /*
             * Built directly rather than through EsmtpTransportFactory, which
             * declares TransportInterface -- and that interface has no start(),
             * stop() or setLocalDomain(). The concrete class is what this needs
             * and what it should therefore ask for.
             *
             * The third argument is IMPLICIT tls, not "use encryption": false
             * still upgrades with STARTTLS when the server advertises it, which
             * is the 587 path. Only MAIL_SCHEME=smtps on 465 wants true.
             */
            $connection = new EsmtpTransport(
                (string) config('mail.mailers.'.$mailer.'.host'),
                (int) config('mail.mailers.'.$mailer.'.port'),
                ((string) config('mail.mailers.'.$mailer.'.scheme')) === 'smtps',
            );

            $username = (string) config('mail.mailers.'.$mailer.'.username');

            $connection->setUsername($username);
            $connection->setPassword((string) config('mail.mailers.'.$mailer.'.password'));
            $connection->setLocalDomain((string) config('mail.mailers.'.$mailer.'.local_domain'));

            /*
             * The same ceiling the application sends under. Without it this
             * inherits the stream default and a host that silently drops
             * outbound 587 -- the common cloud-provider posture, and precisely
             * what this row exists to catch -- hangs the whole command instead
             * of reporting the fault.
             */
            $stream = $connection->getStream();

            // Narrowed because setTimeout() is SocketStream's, not
            // AbstractStream's -- the other implementation is ProcessStream,
            // which sendmail uses and which has no socket to bound.
            if ($stream instanceof SocketStream) {
                $stream->setTimeout((float) config('mail.mailers.'.$mailer.'.timeout', 15));
            }

            // start() runs connect + EHLO + STARTTLS + AUTH, and stops there.
            $connection->start();
            $connection->stop();

            /*
             * Symfony's handleAuth() returns immediately on an empty username,
             * so a connection that authenticated NOTHING completes cleanly and
             * would otherwise be reported as proof the credentials work. On
             * Gmail that configuration cannot send at all.
             */
            if ($username === '') {
                return ['SMTP handshake', 'NO (connected, but MAIL_USERNAME is unset so nothing authenticated)', $consequence];
            }

            return ['SMTP handshake', 'OK authenticated', $consequence];
        } catch (Throwable $e) {
            /*
             * Deliberately truncated. Gmail's rejection quotes the dialogue
             * back, and this output gets pasted into handover notes and
             * screenshots -- the first line names the fault without reprinting
             * the credential.
             */
            $first = trim(strtok($e->getMessage(), "\n") ?: 'failed');

            return ['SMTP handshake', 'NO ('.mb_strimwidth($first, 0, 60, '...').')', $consequence];
        }
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
