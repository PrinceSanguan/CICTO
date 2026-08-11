<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Actions\Documents\StoreDocumentFile;
use App\Enums\BackupStatus;
use App\Enums\DocumentPriority;
use App\Enums\SecurityEventType;
use App\Models\AppSetting;
use App\Models\BackupRun;
use App\Models\DocumentScan;
use App\Models\SecurityEvent;
use App\Services\Backup\BackupService;
use App\Services\Backup\PhpDumper;
use App\Services\Backup\ShellDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §21 Security and Encryption, §22 Backup and Recovery.
 */
class BackupAndSecurityTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    // ------------------------------------------------------------ §22 backup

    public function test_a_backup_records_a_run_with_a_checksum(): void
    {
        Storage::fake('backups');

        $run = app(BackupService::class)->run($this->superAdmin());

        $this->assertSame(BackupStatus::Completed, $run->status);
        $this->assertNotNull($run->checksum_sha256);
        $this->assertSame(64, mb_strlen($run->checksum_sha256));
        $this->assertGreaterThan(0, $run->size_bytes);

        // A data-only dump is valid only against its own migration set, so the
        // run has to remember which one that was.
        $this->assertNotNull($run->last_migration);

        Storage::disk('backups')->assertExists($run->path);
    }

    public function test_the_php_dumper_orders_tables_by_dependency_not_alphabetically(): void
    {
        Storage::fake('backups');

        $office = $this->office();
        $this->registerDocument($office, $this->staff($office));

        $target = Storage::disk('backups')->path('order-test.sql.gz');
        Storage::disk('backups')->makeDirectory('/');
        app(PhpDumper::class)->dump($target);

        $sql = (string) gzdecode((string) file_get_contents($target));

        // document_files sorts before documents alphabetically but depends on
        // it. Getting this wrong is a foreign key violation on restore.
        $this->assertLessThan(
            strpos($sql, '-- document_files'),
            strpos($sql, '-- documents'),
            'documents must be written before document_files.',
        );
        $this->assertLessThan(
            strpos($sql, '-- documents'),
            strpos($sql, '-- offices'),
            'offices must be written before documents.',
        );
    }

    public function test_the_php_dump_emits_typed_literals_not_stringified_booleans(): void
    {
        Storage::fake('backups');
        $this->office();

        $target = Storage::disk('backups')->path('types.sql.gz');
        Storage::disk('backups')->makeDirectory('/');
        app(PhpDumper::class)->dump($target);

        $sql = (string) gzdecode((string) file_get_contents($target));

        // The regression this guards: (string) false is the empty string,
        // which reloads as `''` and fails with "invalid input syntax for type
        // boolean". No value in the dump may be an empty-string literal.
        $this->assertStringNotContainsString(", '',", $sql);
        $this->assertStringNotContainsString("('',", $sql);

        // How a boolean is spelled depends on the driver -- PostgreSQL hands
        // back real PHP booleans, SQLite hands back 0/1 integers -- so accept
        // either, but require it to be an unquoted literal.
        // Identifier quoting differs per driver: backticks on MySQL, double
        // quotes elsewhere.
        preg_match('/INSERT INTO [`"]?offices[`"]? .*?VALUES \((.*)\);/', $sql, $m);
        $this->assertNotEmpty($m, 'Expected an offices INSERT in the dump.');
        $this->assertMatchesRegularExpression('/(TRUE|FALSE|\b[01]\b)/', $m[1]);
    }

    public function test_the_php_dump_never_uses_a_privileged_session_setting(): void
    {
        Storage::fake('backups');
        $this->office();

        $target = Storage::disk('backups')->path('privileges.sql.gz');
        Storage::disk('backups')->makeDirectory('/');
        app(PhpDumper::class)->dump($target);

        $sql = (string) gzdecode((string) file_get_contents($target));

        // session_replication_role is superuser-only. The application role is
        // deliberately not a superuser, so relying on it would make every
        // restore fail on the host it matters on.
        $this->assertStringNotContainsString('session_replication_role', $sql);
    }

    public function test_a_failed_backup_is_still_recorded(): void
    {
        Storage::fake('backups');

        // A disk that cannot be written to.
        config(['cicto.backup.disk' => 'backups']);

        $this->swap(PhpDumper::class, new class extends PhpDumper
        {
            public function isSupported(): bool
            {
                return true;
            }

            public function dump(string $target): int
            {
                throw new \RuntimeException('disk is full');
            }
        });
        config(['cicto.backup.driver' => 'php']);

        try {
            app(BackupService::class)->run($this->superAdmin());
            $this->fail('Expected the backup to throw.');
        } catch (\Throwable) {
            // expected
        }

        $run = BackupRun::query()->latest('id')->firstOrFail();

        // Silence on failure is the worst possible behaviour: everyone assumes
        // the backups are fine.
        $this->assertSame(BackupStatus::Failed, $run->status);
        $this->assertStringContainsString('disk is full', (string) $run->error);
        $this->assertDatabaseHas('security_events', [
            'type' => SecurityEventType::BackupFailed->value,
        ]);
    }

    public function test_backups_are_untested_until_a_restore_is_recorded(): void
    {
        Storage::fake('backups');

        $superAdmin = $this->superAdmin();
        $this->assertFalse(BackupRun::hasEverBeenRestored());

        $run = app(BackupService::class)->run($superAdmin);
        $this->assertFalse(BackupRun::hasEverBeenRestored());

        app(BackupService::class)->recordRestore($run, $superAdmin, 'Restored to scratch DB, row counts matched.');

        $this->assertTrue(BackupRun::hasEverBeenRestored());
        $this->assertDatabaseHas('security_events', [
            'type' => SecurityEventType::BackupRestored->value,
        ]);
    }

    public function test_only_a_super_admin_reaches_the_backup_console(): void
    {
        $office = $this->office();

        $this->actingAs($this->admin($office))
            ->get(route('super-admin.settings.edit'))
            ->assertForbidden();

        $this->actingAs($this->admin($office))
            ->post(route('super-admin.settings.backup'))
            ->assertForbidden();

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.settings.edit'))
            ->assertOk();
    }

    // ---------------------------------------------------------- §21 security

    public function test_settings_are_encrypted_at_rest(): void
    {
        AppSetting::put('mail.password', 'super-secret-smtp', 'string', 'mail', true);

        $raw = DB::table('app_settings')
            ->where('setting_key', 'mail.password')
            ->value('setting_value');

        // The ciphertext must not contain the plaintext.
        $this->assertNotSame('super-secret-smtp', $raw);
        $this->assertStringNotContainsString('super-secret-smtp', (string) $raw);

        // ...and it still round-trips.
        $this->assertSame('super-secret-smtp', AppSetting::get('mail.password'));

        // A secret is masked in the UI, never echoed back.
        $this->assertSame(str_repeat('•', 12), AppSetting::query()->find('mail.password')->displayValue());
    }

    public function test_signing_in_and_out_is_logged(): void
    {
        $office = $this->office();
        $user = $this->admin($office);

        $this->actingAs($user)->post(route('logout'));

        $this->assertDatabaseHas('security_events', [
            'type' => SecurityEventType::LoggedOut->value,
            'user_id' => $user->id,
        ]);
    }

    public function test_a_failed_sign_in_is_logged_without_the_password(): void
    {
        $this->post(route('login'), [
            'email' => 'nobody@example.test',
            'password' => 'hunter2-should-never-be-stored',
        ]);

        $event = SecurityEvent::query()
            ->where('type', SecurityEventType::LoginFailed->value)
            ->firstOrFail();

        $this->assertStringContainsString('nobody@example.test', $event->summary);
        $this->assertStringNotContainsString('hunter2', $event->summary);
    }

    public function test_downloading_a_file_is_audited_but_never_touches_the_ledger(): void
    {
        Storage::fake('documents');

        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $document = app(RegisterDocument::class)->handle(
            title: 'Audited download',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $clerk,
            upload: UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 x'),
        );

        $file = $document->currentFile()->first();
        $legsBefore = $document->movements()->count();

        $this->actingAs($admin)
            ->get(route('documents.files.download', [$document, $file]))
            ->assertOk();

        $this->assertDatabaseHas('security_events', [
            'type' => SecurityEventType::FileDownloaded->value,
        ]);

        // A read is not a transfer. The custody timeline must stay clean.
        $this->assertSame($legsBefore, $document->fresh()->movements()->count());
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_the_production_csp_nonce_matches_the_script_it_is_meant_to_allow(): void
    {
        // The header is production-only, so the one thing that would break the
        // whole app -- a policy whose nonce does not match the rendered tag --
        // is invisible in every other environment.
        app()->detectEnvironment(fn () => 'production');

        $response = $this->get('/');
        $response->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("script-src 'self' 'nonce-", $policy);
        $this->assertStringContainsString('report-uri', $policy);

        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $fromHeader);
        preg_match('/<script nonce="([A-Za-z0-9]+)"/', $response->getContent(), $fromBody);

        $this->assertNotEmpty($fromHeader, 'The policy carries no nonce.');
        $this->assertNotEmpty($fromBody, 'The inline theme script carries no nonce.');

        // Mismatch means the theme script is dropped on every production page
        // load once CICTO_CSP_ENFORCE is flipped.
        $this->assertSame($fromHeader[1], $fromBody[1]);
    }

    public function test_a_non_numeric_document_id_is_a_404_not_a_server_error(): void
    {
        $office = $this->office();

        // PostgreSQL raises 22P02 on `where id = 'labels'` where SQLite and
        // MySQL coerce to 0 and 404. That made a URL typo a 500 in production
        // only -- on the one driver a local click-through never exercises.
        $this->actingAs($this->admin($office))
            ->get('/documents/labels')
            ->assertNotFound();

        // The routes keyed by something other than an id must still resolve.
        $document = $this->registerDocument($office, $this->staff($office));

        $this->get('/s/'.$document->qr_token)->assertRedirect();
    }

    public function test_the_privacy_notice_is_public_and_states_the_retention_period(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('privacy')
                    ->where('retention.scans', (int) config('cicto.scans.retention_days')),
            );
    }

    public function test_pruners_ship_disabled_and_delete_nothing(): void
    {
        $office = $this->office();
        $document = $this->registerDocument($office, $this->staff($office));

        DocumentScan::create([
            'document_id' => $document->id,
            'source' => 'camera',
            'ip_address' => '10.0.0.1',
            'scanned_at' => now()->subYears(3),
        ]);

        SecurityEvent::log(SecurityEventType::LoginSucceeded, 'ancient', null);
        SecurityEvent::query()->update(['created_at' => now()->subYears(3)]);

        $before = DocumentScan::query()->count() + SecurityEvent::query()->count();

        $this->artisan('cicto:prune-personal-data --force')->assertSuccessful();

        // Deleting a municipal record on a developer's assumption is not a
        // decision to make quietly -- these stay off until B6 is answered.
        $this->assertSame(
            $before,
            DocumentScan::query()->count() + SecurityEvent::query()->count(),
        );
    }

    public function test_a_decrypted_secret_never_reaches_the_cache_store(): void
    {
        // CACHE_STORE is `database`. An earlier version memoised the decrypted
        // settings with Cache::rememberForever, which wrote the plaintext SMTP
        // password into the `cache` table and quietly undid the whole point of
        // the encrypted cast. Nothing that outlives the request may hold it.
        config(['cache.default' => 'database']);

        AppSetting::flushMemo();
        AppSetting::put('mail.password', 'PLAINTEXT-CANARY-9271', 'string', 'mail', true);

        $this->assertSame('PLAINTEXT-CANARY-9271', AppSetting::get('mail.password'));

        foreach (DB::table('cache')->get() as $row) {
            $this->assertStringNotContainsString(
                'PLAINTEXT-CANARY-9271',
                (string) $row->value,
                'A decrypted setting leaked into the cache store.',
            );
        }

        // ...and the column itself is still ciphertext.
        $column = (string) DB::table('app_settings')
            ->where('setting_key', 'mail.password')
            ->value('setting_value');

        $this->assertStringNotContainsString('PLAINTEXT-CANARY-9271', $column);
    }

    public function test_two_backups_in_the_same_second_do_not_share_a_filename(): void
    {
        Storage::fake('backups');
        Carbon::setTestNow('2026-08-10 21:00:00');

        $first = app(BackupService::class)->run();
        $second = app(BackupService::class)->run();

        // Second-resolution stamps collided, so the second run overwrote the
        // first and the first row then described bytes it had not written.
        $this->assertNotSame($first->path, $second->path);
        Storage::disk('backups')->assertExists($first->path);
        Storage::disk('backups')->assertExists($second->path);

        Carbon::setTestNow();
    }

    public function test_an_audit_log_failure_does_not_discard_a_good_backup(): void
    {
        Storage::fake('backups');

        // Anything thrown after the dump succeeded used to land in the catch,
        // flip the run to Failed, and DELETE the only copy of the data.
        Event::listen(function (MessageLogged $event): void {});

        $run = app(BackupService::class)->run();

        $this->assertSame(BackupStatus::Completed, $run->status);
        Storage::disk('backups')->assertExists($run->path);
    }

    public function test_the_postgres_dump_advances_sequences_past_the_restored_rows(): void
    {
        $dumper = new PhpDumper;

        $footer = (function (PhpDumper $d): string {
            $method = new \ReflectionMethod($d, 'footer');
            $method->setAccessible(true);

            return $method->invoke($d, 'pgsql', ['documents', 'offices']);
        })($dumper);

        // PostgreSQL does not advance a sequence when a row is inserted with an
        // explicit id. Without these the first document created after a restore
        // collides with id 1 and keeps colliding.
        $this->assertStringContainsString('setval', $footer);
        $this->assertStringContainsString("pg_get_serial_sequence('documents', 'id')", $footer);

        // MySQL and SQLite derive it from the inserted rows; emitting Postgres
        // syntax there would break the restore outright.
        $mysql = (function (PhpDumper $d): string {
            $method = new \ReflectionMethod($d, 'footer');
            $method->setAccessible(true);

            return $method->invoke($d, 'mysql', ['documents']);
        })($dumper);

        $this->assertStringNotContainsString('setval', $mysql);
    }

    public function test_the_mysql_dump_command_keeps_the_password_out_of_the_process_list(): void
    {
        $dumper = new ShellDumper;

        $command = (function (ShellDumper $d): array {
            $method = new \ReflectionMethod($d, 'command');
            $method->setAccessible(true);

            return $method->invoke($d, 'mysql', 'mysqldump', [
                'host' => '127.0.0.1',
                'port' => 3306,
                'username' => 'cicto',
                'password' => 'sup3rs3cret',
                'database' => 'cicto',
            ], '/tmp/x.sql');
        })($dumper);

        // argv is world-readable in `ps`. The password goes through MYSQL_PWD.
        $this->assertNotContains('--password=sup3rs3cret', $command);

        foreach ($command as $argument) {
            $this->assertStringNotContainsString('sup3rs3cret', $argument);
        }
    }

    public function test_the_print_view_carries_no_inline_event_handler(): void
    {
        $blade = file_get_contents(resource_path('views/documents/labels.blade.php'));

        // An enforced CSP treats onclick="" as inline script, and a nonce
        // cannot whitelist an attribute -- the Print button silently died.
        $this->assertStringNotContainsString('onclick=', $blade);
        $this->assertStringContainsString('cspNonce()', $blade);
    }

    public function test_the_csp_report_endpoint_accepts_a_browser_report_without_a_session(): void
    {
        $response = $this->postJson(route('security.csp-report'), [
            'csp-report' => [
                'document-uri' => 'https://example.test/documents/1',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'inline',
            ],
        ]);

        // The browser posts this with no session and no CSRF token. A policy
        // that reports nowhere cannot be watched before it is enforced.
        $response->assertNoContent();
    }

    public function test_a_backup_contains_the_uploaded_documents_not_only_the_database(): void
    {
        Storage::fake('backups');

        Storage::fake('documents');

        $office = $this->office();
        $clerk = $this->staff($office);
        $document = $this->registerDocument($office, $clerk);

        $file = app(StoreDocumentFile::class)->handle(
            $document,
            UploadedFile::fake()->createWithContent('attached.pdf', '%PDF-1.4 backed up'),
            $clerk,
        );

        $run = app(BackupService::class)->run();

        // A database-only backup is not a backup of this system: every
        // document_files row points at bytes on disk and every signature is a
        // hash OF those bytes. Restore the database alone and the register
        // describes files that no longer exist, while the nightly sweep
        // reports the whole signed record set as tampered.
        $this->assertSame('full', $run->kind);
        $this->assertStringEndsWith('.zip', (string) $run->path);

        $zip = new \ZipArchive;
        $this->assertTrue(
            $zip->open(Storage::disk('backups')->path((string) $run->path)) === true,
            'The backup archive could not be opened.',
        );

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        $sql = array_filter($names, fn (string $n) => str_starts_with($n, 'database/'));
        $docs = array_filter($names, fn (string $n) => str_starts_with($n, 'documents/'));

        $this->assertCount(1, $sql, 'The archive holds no database dump.');
        $this->assertNotEmpty($docs, 'The archive holds no document files.');
        $this->assertContains(
            'documents/'.$file->path,
            $names,
            'The uploaded file is missing from the backup.',
        );
    }

    public function test_a_host_without_ziparchive_still_backs_up_the_database(): void
    {
        Storage::fake('backups');

        // Shared hosting sometimes lacks the extension. The backup must degrade
        // to database-only and SAY so on the row, not silently claim coverage
        // it does not have.
        $service = app(BackupService::class);

        $this->assertTrue(
            $service->canArchiveFiles(),
            'This test environment is expected to have ZipArchive.',
        );

        config(['filesystems.disks.documents.driver' => 's3']);

        $this->assertFalse($service->canArchiveFiles());
        $this->assertFalse($service->capabilities()['includes_files']);
    }

    public function test_a_failure_before_the_dump_still_leaves_a_record(): void
    {
        Storage::fake('backups');

        // The classic shared-hosting case: the operator forced the shell driver
        // on a host that cannot shell out. dumper() throws before any file is
        // touched -- and that used to happen before the backup_runs row was
        // created, so the run vanished while the console reported that the
        // failure had been recorded.
        config(['cicto.backup.driver' => 'shell']);

        $this->swap(ShellDumper::class, new class extends ShellDumper
        {
            public function isSupported(): bool
            {
                return false;
            }
        });

        $before = BackupRun::query()->count();

        try {
            app(BackupService::class)->run();
            $this->fail('The backup should have failed.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($before + 1, BackupRun::query()->count());

        $run = BackupRun::query()->latest('id')->first();

        $this->assertSame(BackupStatus::Failed, $run->status);
        $this->assertNotNull($run->error);
        $this->assertNotNull($run->finished_at);
    }
}
