<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Administering users.
 *
 * Feature #11 ships the enforcement half of RBAC; the Manage Users screen is
 * still a placeholder, so without this command a fresh deployment has exactly
 * one usable account and no way to add a clerk.
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
}
