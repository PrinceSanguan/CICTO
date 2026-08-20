<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Administering users.
 *
 * Feature #11 ships the enforcement half of RBAC; role, office and active state
 * have no screen at all, so without this command a fresh deployment has exactly
 * one usable account and no way to add a clerk.
 *
 * --reset-password is the fallback for the one situation the Manage Users
 * screen cannot serve: every Super Admin is locked out, so there is nobody left
 * who can sign in and press the button -- and with no mail service (client
 * question B3) there is no reset link either.
 */
class ManageUserCommandTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_it_creates_a_user_with_a_role_and_an_office(): void
    {
        $office = $this->office('MPDO');

        $this->artisan('cicto:user', [
            'email' => 'clerk@baliwag.example',
            '--name' => 'New Clerk',
            '--role' => 'admin',
            '--office' => 'MPDO',
        ])
            ->expectsQuestion('Password', 'Corr3ct-Horse-Batt3ry')
            ->expectsQuestion('Confirm password', 'Corr3ct-Horse-Batt3ry')
            ->assertSuccessful();

        $user = User::firstWhere('email', 'clerk@baliwag.example');

        $this->assertNotNull($user);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertSame($office->id, $user->office_id);
        $this->assertTrue($user->is_active);

        // Verified on creation: an administrator made this account and the
        // deployment may have no mail service yet.
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_it_changes_the_role_of_an_existing_user(): void
    {
        $office = $this->office();
        $user = $this->staff($office);

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--role' => 'super_admin',
        ])->assertSuccessful();

        $this->assertSame(Role::SuperAdmin, $user->fresh()->role);
    }

    public function test_deactivate_closes_the_account_without_deleting_it(): void
    {
        $office = $this->office();
        $user = $this->staff($office);
        $this->registerDocument($office, $user);

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--deactivate' => true,
        ])->assertSuccessful();

        // Never a delete: actor_id is nullOnDelete, so removing the row would
        // rewrite the audit trail.
        $this->assertNotNull(User::find($user->id));
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_an_unknown_role_or_office_is_refused(): void
    {
        $office = $this->office();
        $user = $this->staff($office);

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--role' => 'wizard',
        ])->assertFailed();

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--office' => 'NOSUCH',
        ])->assertFailed();

        $this->assertSame(Role::User, $user->fresh()->role);
    }

    public function test_reset_password_generates_a_password_and_prints_it_once(): void
    {
        $user = $this->staff($this->office('OCM', 'Office of the City Mayor'));
        $before = $user->password;

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--reset-password' => true,
        ])->assertSuccessful();

        $this->assertNotSame($before, $user->refresh()->password);

        // The plaintext is not knowable from here -- that is the point of
        // generating it -- so what is asserted is that the OLD one is gone.
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_reset_password_rotates_the_remember_token(): void
    {
        $user = $this->staff($this->office('OCM', 'Office of the City Mayor'));
        $before = $user->remember_token;

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--reset-password' => true,
        ])->assertSuccessful();

        $this->assertNotSame($before, $user->refresh()->remember_token);
    }

    /**
     * Same eviction as the screen. phpunit.xml pins SESSION_DRIVER=array, under
     * which this is a deliberate no-op, so the driver is switched on here or
     * the assertion would pass without testing anything.
     */
    public function test_reset_password_ends_the_accounts_live_sessions(): void
    {
        config()->set('session.driver', 'database');

        $user = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        DB::table('sessions')->insert([
            'id' => 'a-live-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--reset-password' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    /**
     * §21 does not stop applying because the operation happened over SSH. The
     * console has no signed-in actor, so SecurityEvent records it as `system`
     * and the summary names where it came from.
     */
    public function test_reset_password_is_written_to_the_security_log(): void
    {
        $user = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--reset-password' => true,
        ])->assertSuccessful();

        $event = SecurityEvent::query()
            ->where('type', SecurityEventType::PasswordResetByAdmin->value)
            ->firstOrFail();

        $this->assertSame($user->email, $event->subject_label);
        $this->assertNull($event->user_id);
        $this->assertStringContainsString('console', $event->summary);
    }

    /** Nothing is touched unless the flag is passed. */
    public function test_the_password_is_left_alone_without_the_flag(): void
    {
        $user = $this->staff($this->office('OCM', 'Office of the City Mayor'));
        $before = $user->password;

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--role' => 'admin',
        ])->assertSuccessful();

        $this->assertSame($before, $user->refresh()->password);
        $this->assertSame(0, SecurityEvent::query()
            ->where('type', SecurityEventType::PasswordResetByAdmin->value)
            ->count());
    }

    /**
     * The rescue path is run under pressure, from memory, by somebody who
     * cannot sign in. A mistyped address must not quietly mint a SECOND account
     * and print working credentials for it while the real one stays locked --
     * which is what create-on-miss would do, and does for every other option
     * this command takes.
     */
    public function test_reset_password_refuses_to_create_an_account_for_a_typo(): void
    {
        $real = $this->staff($this->office('OCM', 'Office of the City Mayor'));
        $real->forceFill(['email' => 'super@baliwag.gov.ph'])->save();
        $hash = $real->fresh()->password;

        $this->artisan('cicto:user', [
            'email' => 'supe@baliwag.gov.ph',
            '--reset-password' => true,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'supe@baliwag.gov.ph']);
        $this->assertSame($hash, $real->fresh()->password);
    }

    /**
     * The last-resort path has to be able to clear what would still block the
     * account. A password handed to somebody who cannot produce a code from a
     * phone they have lost is not a rescue.
     */
    public function test_reset_password_can_revoke_second_factors(): void
    {
        $user = User::factory()
            ->staff($this->office('OCM', 'Office of the City Mayor'))
            ->withTwoFactor()
            ->create();

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--reset-password' => true,
            '--revoke-second-factors' => true,
        ])->assertSuccessful();

        $user->refresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    /** And says so plainly when it is leaving one in place. */
    public function test_reset_password_warns_when_two_factor_would_still_block_them(): void
    {
        $user = User::factory()
            ->staff($this->office('OCM', 'Office of the City Mayor'))
            ->withTwoFactor()
            ->create();

        $this->artisan('cicto:user', [
            'email' => $user->email,
            '--reset-password' => true,
        ])
            ->expectsOutputToContain('two-factor')
            ->expectsOutputToContain('--revoke-second-factors')
            ->assertSuccessful();

        // Warned, not silently done.
        $this->assertNotNull($user->refresh()->two_factor_secret);
    }
}
