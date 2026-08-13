<?php

namespace Tests\Feature;

use App\Console\Commands\CreateSuperAdminCommand;
use App\Console\Commands\ManageUserCommand;
use App\Enums\Role;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The two commands that create accounts, run the way a deployment actually runs
 * them.
 *
 * Both used to demand a hidden password prompt. Managed hosting panels execute
 * artisan through a web form with no terminal, where that prompt reads back as
 * an empty string -- so on the one host that mattered, the only way to create
 * the first account failed with a validation error about a password nobody had
 * been asked for. These tests run with interaction switched off, which is the
 * same condition.
 */
class AccountCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_can_be_created_without_a_terminal(): void
    {
        $this->artisan('cicto:create-super-admin', [
            '--name' => 'Prince Sanguan',
            '--email' => 'super@baliwag.gov.ph',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $user = User::query()->where('email', 'super@baliwag.gov.ph')->firstOrFail();

        $this->assertSame(Role::SuperAdmin, $user->role);
        $this->assertTrue($user->is_active);

        // Verified on creation: the `verified` middleware gates the whole app
        // and there may be no mail service on the host yet.
        $this->assertNotNull($user->email_verified_at);
    }

    /**
     * The generated password has to be shown, or the account is unreachable.
     */
    public function test_the_generated_password_is_printed_and_actually_works(): void
    {
        // Artisan::call, not $this->artisan(): the PendingCommand helper does
        // not populate Artisan::output(), and this test is specifically about
        // what the operator sees printed.
        $exit = Artisan::call('cicto:create-super-admin', [
            '--name' => 'Prince Sanguan',
            '--email' => 'super@baliwag.gov.ph',
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exit);

        $output = Artisan::output();

        $this->assertStringContainsString('Password:', $output);

        preg_match('/Password: (.+)/', $output, $matches);
        $password = trim($matches[1] ?? '');

        $this->assertNotSame('', $password, 'No password was printed.');
        $this->assertGreaterThanOrEqual(12, mb_strlen($password));

        // The real proof: sign in with what was printed.
        $this->post(route('login.store'), [
            'email' => 'super@baliwag.gov.ph',
            'password' => $password,
        ]);

        $this->assertAuthenticated();
    }

    public function test_a_staff_account_can_be_created_without_a_terminal(): void
    {
        Office::query()->create([
            'code' => 'MO',
            'name' => "Mayor's Office",
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->artisan('cicto:user', [
            'email' => 'maria@baliwag.gov.ph',
            '--name' => 'Maria Santos',
            '--role' => 'admin',
            '--office' => 'MO',
            '--no-interaction' => true,
        ])->assertSuccessful();

        $user = User::query()->where('email', 'maria@baliwag.gov.ph')->firstOrFail();

        $this->assertSame(Role::Admin, $user->role);
        $this->assertSame('MO', $user->office?->code);
        $this->assertTrue($user->is_active);
    }

    /**
     * Without a terminal there is nobody to ask, so the missing detail has to
     * be reported rather than silently accepted as an empty string.
     */
    public function test_a_missing_name_is_refused_rather_than_left_blank(): void
    {
        $this->artisan('cicto:create-super-admin', [
            '--email' => 'super@baliwag.gov.ph',
            '--no-interaction' => true,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'super@baliwag.gov.ph']);
    }

    /**
     * uncompromised() calls the Have I Been Pwned API and FAILS OPEN when the
     * host has no outbound HTTPS, which is common on municipal hosting. A check
     * that silently passes everything reads as protection without being any, so
     * neither command may use it.
     */
    public function test_neither_command_depends_on_an_external_password_service(): void
    {
        foreach ([
            'app/Console/Commands/CreateSuperAdminCommand.php',
            'app/Console/Commands/ManageUserCommand.php',
        ] as $file) {
            $source = (string) file_get_contents(base_path($file));

            $this->assertStringNotContainsString(
                '->uncompromised()',
                $source,
                "{$file} calls uncompromised(), which fails open without outbound HTTPS.",
            );
        }
    }

    /**
     * A --password option would be stored forever in the hosting panel's
     * command history, which is why the password is generated instead.
     *
     * Asserted against the command's actual option list rather than by grepping
     * the source, which matched the docblock explaining why the option is
     * absent.
     */
    public function test_no_password_can_be_passed_on_the_command_line(): void
    {
        foreach ([
            CreateSuperAdminCommand::class,
            ManageUserCommand::class,
        ] as $class) {
            $definition = $this->app->make($class)->getDefinition();

            $this->assertFalse(
                $definition->hasOption('password'),
                $class.' accepts a password as an option; it would outlive the '.
                "deployment in the panel's command history.",
            );
        }
    }
}
