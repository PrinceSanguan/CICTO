<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\Role;
use App\Enums\SecurityEventType;
use App\Http\Requests\SuperAdmin\StoreUserRequest;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §4's Manage Users screen, and the capability it introduces.
 *
 * Creating an account is the operation that hands somebody access to a
 * municipal register, so the interesting assertions here are the refusals.
 */
class ManageUsersTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-9!battery';

    public function test_a_super_admin_creates_an_account_that_can_sign_in(): void
    {
        $office = $this->office('MO', "Mayor's Office");

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.store'), [
                'name' => 'Emarie Alonzo',
                'email' => 'emarie@baliwag.gov.ph',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => Role::Admin->value,
                'office_id' => $office->id,
            ])
            ->assertRedirect(route('super-admin.users.index'));

        $created = User::query()->where('email', 'emarie@baliwag.gov.ph')->firstOrFail();

        $this->assertSame(Role::Admin, $created->role);
        $this->assertSame($office->id, $created->office_id);
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $created->password));

        /*
         * Verified at creation, deliberately. The `verified` middleware gates
         * the whole app and this deployment cannot send mail, so an unverified
         * account would be one nobody could ever sign in to.
         */
        $this->assertNotNull($created->email_verified_at);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => 'emarie@baliwag.gov.ph',
            'password' => self::PASSWORD,
        ]);

        $this->assertAuthenticatedAs($created);
    }

    public function test_creating_an_account_is_written_to_the_security_log(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post(route('super-admin.users.store'), [
            'name' => 'Emarie Alonzo',
            'email' => 'emarie@baliwag.gov.ph',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => Role::Admin->value,
            'office_id' => $office->id,
        ]);

        $event = SecurityEvent::query()
            ->where('type', SecurityEventType::UserCreated->value)
            ->firstOrFail();

        $this->assertSame('emarie@baliwag.gov.ph', $event->subject_label);
        $this->assertStringContainsString($actor->name, $event->summary);
    }

    /**
     * An Admin can arrange people inside their own office through
     * AssignUserRole, but must not be able to mint access.
     */
    public function test_an_admin_cannot_reach_the_screen_or_create_an_account(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $admin = $this->admin($office);

        $this->actingAs($admin)
            ->get(route('super-admin.users.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('super-admin.users.store'), [
                'name' => 'Escalation',
                'email' => 'escalation@baliwag.gov.ph',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => Role::SuperAdmin->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'escalation@baliwag.gov.ph']);
    }

    public function test_a_non_super_admin_role_must_be_given_an_office(): void
    {
        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.store'), [
                'name' => 'No Office',
                'email' => 'nooffice@baliwag.gov.ph',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => Role::Admin->value,
            ])
            ->assertSessionHasErrors('office_id');

        $this->assertDatabaseMissing('users', ['email' => 'nooffice@baliwag.gov.ph']);
    }

    /**
     * An account created here has at least as much access as one created
     * anywhere else, so it is held to the same password profile.
     *
     * The password asserted below is short enough to be refused in EVERY
     * environment. The full production profile -- min 12, mixed case, numbers,
     * symbols -- is applied through Password::defaults(), which
     * AppServiceProvider deliberately relaxes outside production so local
     * seeding is not a chore; asserting the strict rules here would only be
     * testing the environment, not this screen.
     */
    public function test_a_weak_password_is_refused(): void
    {
        $office = $this->office('MO', "Mayor's Office");

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.store'), [
                'name' => 'Weak',
                'email' => 'weak@baliwag.gov.ph',
                'password' => 'short',
                'password_confirmation' => 'short',
                'role' => Role::Admin->value,
                'office_id' => $office->id,
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'weak@baliwag.gov.ph']);
    }

    /**
     * The rule delegates rather than hard-coding a strength, so a deployment
     * that tightens Password::defaults() tightens this screen with it.
     */
    public function test_the_password_rule_follows_the_application_default(): void
    {
        $rules = (new StoreUserRequest)->rules();

        $this->assertContains('confirmed', $rules['password']);

        $usesDefaults = collect($rules['password'])->contains(
            fn ($rule) => $rule instanceof Password,
        );

        $this->assertTrue(
            $usesDefaults,
            'The password rule must go through Password::defaults() so it '.
            'follows whatever the deployment enforces.',
        );
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $existing = $this->staff($office);

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.store'), [
                'name' => 'Duplicate',
                'email' => $existing->email,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
                'role' => Role::Admin->value,
                'office_id' => $office->id,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(
            1,
            User::query()->where('email', $existing->email)->count(),
        );
    }

    public function test_the_list_searches_by_name_and_email(): void
    {
        $office = $this->office('MO', "Mayor's Office");
        $target = $this->staff($office);

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.users.index', ['q' => $target->email]))
            ->assertInertia(function ($page) use ($target) {
                $emails = collect($page->toArray()['props']['users']['data'])
                    ->pluck('email');

                $this->assertSame([$target->email], $emails->all());
            });
    }
}
