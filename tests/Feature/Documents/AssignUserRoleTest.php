<?php

namespace Tests\Feature\Documents;

use App\Actions\Users\AssignUserRole;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The single door through which users.role and users.office_id are written.
 *
 * Not routed yet -- user management is Phase 3 -- but the guard is written now
 * because the action already exists, and an unguarded privilege writer is the
 * kind of thing that gets wired up later by someone who assumes it is safe.
 */
class AssignUserRoleTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_an_admin_cannot_demote_a_super_admin(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $superAdmin = $this->superAdmin();

        // Super Admins have no office, so an office-based guard alone lets an
        // Admin walk straight past it and strip the role.
        $this->expectException(AuthorizationException::class);

        app(AssignUserRole::class)->handle($admin, $superAdmin, Role::User);
    }

    public function test_an_admin_cannot_mint_a_super_admin(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        $this->expectException(AuthorizationException::class);

        app(AssignUserRole::class)->handle($admin, $clerk, Role::SuperAdmin);
    }

    public function test_an_admin_cannot_touch_a_user_in_another_office(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $this->expectException(AuthorizationException::class);

        app(AssignUserRole::class)->handle(
            $this->admin($mpdo),
            $this->staff($mto),
            Role::Admin,
        );
    }

    public function test_an_admin_can_promote_someone_in_their_own_office(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);

        app(AssignUserRole::class)->handle($admin, $clerk, Role::Admin);

        $this->assertSame(Role::Admin, $clerk->fresh()->role);
        $this->assertSame($office->id, $clerk->fresh()->office_id);
    }

    public function test_an_admin_can_adopt_a_quarantined_self_registered_user(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $newcomer = User::factory()->create([
            'role' => Role::User,
            'office_id' => null,
        ]);

        app(AssignUserRole::class)->handle($admin, $newcomer, Role::User);

        // Adopted into the admin's own office -- never anywhere else.
        $this->assertSame($office->id, $newcomer->fresh()->office_id);
    }

    public function test_a_super_admin_can_do_all_of_it(): void
    {
        $office = $this->office();
        $superAdmin = $this->superAdmin();
        $clerk = $this->staff($office);

        app(AssignUserRole::class)->handle($superAdmin, $clerk, Role::SuperAdmin);

        $this->assertSame(Role::SuperAdmin, $clerk->fresh()->role);
    }

    public function test_a_deactivated_admin_cannot_change_roles(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $admin->forceFill(['is_active' => false])->save();

        $this->expectException(AuthorizationException::class);

        app(AssignUserRole::class)->handle($admin, $this->staff($office), Role::Admin);
    }
}
