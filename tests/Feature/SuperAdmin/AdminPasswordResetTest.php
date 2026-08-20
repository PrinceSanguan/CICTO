<?php

namespace Tests\Feature\SuperAdmin;

use App\Enums\SecurityEventType;
use App\Http\Requests\SuperAdmin\ResetUserPasswordRequest;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Client question B3's module: an administrator sets somebody else's password.
 *
 * CICTO declined to supply SMTP credentials on 2026-08-20 and asked for this
 * instead. With no mail there is no reset link, so this route is the whole
 * recovery path for a forgotten password -- which makes it both the most useful
 * thing on the Manage Users screen and the only one that can hand an account to
 * the wrong person. The interesting assertions are therefore the refusals, and
 * the four things the reset does BESIDES writing a hash.
 */
class AdminPasswordResetTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private const ACTOR_PASSWORD = 'password';

    private const NEW_PASSWORD = 'Correct-Horse-9!battery';

    public function test_a_super_admin_sets_a_password_the_account_can_sign_in_with(): void
    {
        $office = $this->office('OCM', 'Office of the City Mayor');
        $target = $this->staff($office);

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('super-admin.users.index'));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $target->refresh()->password));

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => $target->email,
            'password' => self::NEW_PASSWORD,
        ]);

        $this->assertAuthenticatedAs($target);
    }

    public function test_the_old_password_stops_working(): void
    {
        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => $target->email,
            'password' => self::ACTOR_PASSWORD,
        ]);

        $this->assertGuest();
    }

    /**
     * A stolen "remember me" cookie authenticates against remember_token, not
     * against the password. Leaving it in place means the reset did not end the
     * access it was performed to end.
     */
    public function test_the_remember_me_token_is_rotated(): void
    {
        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));
        $before = $target->remember_token;

        $this->assertNotNull($before, 'The factory is expected to seed a remember token.');

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $this->assertNotSame($before, $target->refresh()->remember_token);
    }

    /**
     * A reset link issued before the administrator stepped in is still live for
     * an hour. Whoever holds it could set the password straight back.
     */
    public function test_an_outstanding_emailed_reset_token_is_destroyed(): void
    {
        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        Password::broker()->createToken($target);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $target->email]);

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $target->email]);
    }

    /**
     * The one that needs the session driver switched on.
     *
     * phpunit.xml pins SESSION_DRIVER=array, under which the eviction is a
     * deliberate no-op -- so a test that did not override it would assert
     * nothing and pass. Deployments run on `database`, where the sessions table
     * is the only thing that can end somebody else's session: Laravel's own
     * logoutOtherDevices acts on the CURRENTLY authenticated user and needs
     * AuthenticateSession middleware this application does not register.
     */
    public function test_live_sessions_belonging_to_the_account_are_destroyed(): void
    {
        config()->set('session.driver', 'database');

        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));
        $bystander = $this->staff($this->office('SP', 'Office of the Sangguniang Panlungsod'));

        foreach ([$target, $bystander] as $user) {
            DB::table('sessions')->insert([
                'id' => 'session-for-'.$user->id,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'payload' => 'x',
                'last_activity' => time(),
            ]);
        }

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);

        // Nobody else is signed out by somebody else's reset.
        $this->assertDatabaseHas('sessions', ['user_id' => $bystander->id]);
    }

    /**
     * Not ticked: an ordinary "they forgot it" reset leaves the account's other
     * credentials alone, because a working authenticator is not a problem.
     */
    public function test_second_factors_survive_an_ordinary_reset(): void
    {
        $target = User::factory()
            ->staff($this->office('OCM', 'Office of the City Mayor'))
            ->withTwoFactor()
            ->create();

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $this->assertNotNull($target->refresh()->two_factor_secret);
        $this->assertNotNull($target->two_factor_confirmed_at);
    }

    /**
     * Ticked: the account may be in somebody else's hands, and a passkey signs
     * its holder in without the password ever being consulted. A reset that
     * leaves one in place has revoked nothing.
     */
    public function test_second_factors_are_removed_on_request(): void
    {
        $target = User::factory()
            ->staff($this->office('OCM', 'Office of the City Mayor'))
            ->withTwoFactor()
            ->create();

        $target->passkeys()->create([
            'name' => 'A laptop',
            'credential_id' => 'credential-1',
            'credential' => ['id' => 'credential-1'],
        ]);

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
                'revoke_second_factors' => '1',
            ])
            ->assertSessionHasNoErrors();

        $target->refresh();

        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_recovery_codes);
        $this->assertNull($target->two_factor_confirmed_at);
        $this->assertSame(0, $target->passkeys()->count());
    }

    public function test_the_reset_is_written_to_the_security_log_naming_the_administrator(): void
    {
        $actor = $this->superAdmin();
        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        $this->actingAs($actor)->post(route('super-admin.users.password', $target), [
            'your_password' => self::ACTOR_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $event = SecurityEvent::query()
            ->where('type', SecurityEventType::PasswordResetByAdmin->value)
            ->firstOrFail();

        $this->assertSame($target->email, $event->subject_label);
        $this->assertSame($actor->id, $event->user_id);
        $this->assertStringContainsString($actor->name, $event->summary);

        /*
         * The whole reason this is its own case rather than reusing
         * SecurityEventType::PasswordReset: that one is written by
         * RecordSecurityEvents as "<email> reset their password" with the
         * ACCOUNT HOLDER as actor. Filing an administrator-set password under
         * it would put the wrong person's name against the one operation that
         * hands over somebody else's account.
         */
        $this->assertSame(0, SecurityEvent::query()
            ->where('type', SecurityEventType::PasswordReset->value)
            ->count());
    }

    public function test_an_admin_cannot_set_anybody_elses_password(): void
    {
        $office = $this->office('OCM', 'Office of the City Mayor');
        $admin = $this->admin($office);
        $target = $this->staff($office);

        $this->actingAs($admin)
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertForbidden();

        $this->assertTrue(Hash::check(self::ACTOR_PASSWORD, $target->refresh()->password));
    }

    public function test_a_user_cannot_set_anybody_elses_password(): void
    {
        $office = $this->office('OCM', 'Office of the City Mayor');

        $this->actingAs($this->staff($office))
            ->post(route('super-admin.users.password', $this->staff($office)), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertForbidden();
    }

    /**
     * The re-authentication check. An unattended signed-in browser must not be
     * able to take over every account in the city.
     */
    public function test_the_administrators_own_password_is_required(): void
    {
        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.password', $target), [
                'your_password' => 'not-the-right-one',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasErrors('your_password');

        $this->assertTrue(Hash::check(self::ACTOR_PASSWORD, $target->refresh()->password));
    }

    public function test_it_is_refused_without_the_administrators_password_at_all(): void
    {
        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.password', $target), [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasErrors('your_password');
    }

    /**
     * Your own password is changed under Settings > Security, where the current
     * one has to be typed. Routing it through here would swap a deliberate
     * check for an accidental one -- and sign the administrator out of the
     * session they are doing it from.
     */
    public function test_a_super_admin_cannot_reset_their_own_password_here(): void
    {
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->post(route('super-admin.users.password', $actor), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertForbidden();

        $this->assertTrue(Hash::check(self::ACTOR_PASSWORD, $actor->refresh()->password));
    }

    /**
     * Allowed, and pinned so it stays a decision rather than an accident.
     *
     * CICTO asked for a module that resets "the password of any user registered
     * in your application", and a Super Admin locked out of their own account is
     * the likeliest person to need one. The cost is real and is inherent to the
     * role: one Super Admin can take over another's account. It is written to
     * the security log with both names, which is the only control that fits --
     * there is no fourth role above them to ask.
     */
    public function test_a_super_admin_can_reset_another_super_admins_password(): void
    {
        $actor = $this->superAdmin();
        $peer = $this->superAdmin();

        $this->actingAs($actor)
            ->post(route('super-admin.users.password', $peer), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $peer->refresh()->password));

        $event = SecurityEvent::query()
            ->where('type', SecurityEventType::PasswordResetByAdmin->value)
            ->firstOrFail();

        $this->assertStringContainsString($actor->name, $event->summary);
        $this->assertSame($peer->email, $event->subject_label);
    }

    /**
     * Short enough to be refused in EVERY environment. The full production
     * profile is applied through Password::defaults(), which AppServiceProvider
     * deliberately relaxes outside production, so asserting the strict rules
     * here would only be testing the environment.
     */
    public function test_a_weak_password_is_refused(): void
    {
        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::ACTOR_PASSWORD, $target->refresh()->password));
    }

    public function test_a_mistyped_confirmation_is_refused(): void
    {
        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        $this->actingAs($this->superAdmin())
            ->from(route('super-admin.users.index'))
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD.'-typo',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::ACTOR_PASSWORD, $target->refresh()->password));
    }

    /**
     * The rule delegates rather than hard-coding a strength, so a deployment
     * that tightens Password::defaults() tightens this screen with it. The same
     * assertion guards the create form.
     */
    public function test_the_password_rule_follows_the_application_default(): void
    {
        $rules = (new ResetUserPasswordRequest)->rules();

        $this->assertContains('confirmed', $rules['password']);

        $this->assertTrue(
            collect($rules['password'])->contains(fn ($rule) => $rule instanceof PasswordRule),
            'The password rule must go through Password::defaults() so it '.
            'follows whatever the deployment enforces.',
        );
    }

    /**
     * A password on a closed account still will not sign in --
     * EnsureAccountIsActive refuses it whatever the credential -- so the screen
     * says so rather than reporting a fixed problem that is not fixed.
     */
    public function test_resetting_a_deactivated_account_warns_rather_than_congratulates(): void
    {
        $target = User::factory()
            ->staff($this->office('OCM', 'Office of the City Mayor'))
            ->inactive()
            ->create();

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHas('toast', function (array $toast) {
                $this->assertSame('warning', $toast['type']);
                $this->assertStringContainsString('deactivated', $toast['message']);

                return true;
            });
    }

    /** The list has to tell the screen which rows have second factors on them. */
    public function test_the_list_reports_what_a_reset_would_and_would_not_unlock(): void
    {
        $office = $this->office('OCM', 'Office of the City Mayor');
        $plain = $this->staff($office);
        $protected = User::factory()->staff($office)->withTwoFactor()->create();

        $protected->passkeys()->create([
            'name' => 'A laptop',
            'credential_id' => 'credential-1',
            'credential' => ['id' => 'credential-1'],
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('super-admin.users.index'))
            ->assertInertia(function ($page) use ($plain, $protected) {
                $rows = collect($page->toArray()['props']['users']['data'])->keyBy('id');

                $this->assertFalse($rows[$plain->id]['has_two_factor']);
                $this->assertSame(0, $rows[$plain->id]['passkeys']);

                $this->assertTrue($rows[$protected->id]['has_two_factor']);
                $this->assertSame(1, $rows[$protected->id]['passkeys']);
            });
    }

    /**
     * The report names what was actually there, not what the checkbox is
     * called in general.
     *
     * "Two-factor and passkeys were removed" on an account that only had a
     * passkey contradicts the label the administrator just read and promises a
     * two-factor re-enrolment that was never in play.
     */
    public function test_the_report_names_only_the_factors_the_account_actually_had(): void
    {
        $office = $this->office('OCM', 'Office of the City Mayor');
        $target = $this->staff($office);

        $target->passkeys()->create([
            'name' => 'A laptop',
            'credential_id' => 'credential-1',
            'credential' => ['id' => 'credential-1'],
        ]);

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
                'revoke_second_factors' => '1',
            ])
            ->assertSessionHas('toast', function (array $toast) {
                $this->assertStringContainsString('1 passkey', $toast['message']);
                $this->assertStringNotContainsString('Two-factor', $toast['message']);
                $this->assertStringNotContainsString('two-factor', $toast['message']);

                return true;
            });

        $event = SecurityEvent::query()
            ->where('type', SecurityEventType::PasswordResetByAdmin->value)
            ->firstOrFail();

        $this->assertStringContainsString('1 passkey', $event->summary);
        $this->assertStringNotContainsString('two-factor', $event->summary);
    }

    /** And says nothing about second factors when there were none to remove. */
    public function test_the_report_is_silent_when_there_was_nothing_to_revoke(): void
    {
        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        $this->actingAs($this->superAdmin())
            ->post(route('super-admin.users.password', $target), [
                'your_password' => self::ACTOR_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
                'revoke_second_factors' => '1',
            ])
            ->assertSessionHas('toast', function (array $toast) {
                $this->assertStringNotContainsString('removed', $toast['message']);

                return true;
            });
    }

    /**
     * A field posted as an array used to reach password_verify() and throw an
     * uncatchable TypeError, so the one route in the panel that can take over an
     * account answered 500 -- and, with APP_DEBUG on, a stack trace -- instead
     * of a validation error.
     */
    public function test_a_non_string_password_field_is_refused_rather_than_crashing(): void
    {
        $target = $this->staff($this->office('OCM', 'Office of the City Mayor'));

        foreach (['your_password', 'password'] as $field) {
            $this->actingAs($this->superAdmin())
                ->from(route('super-admin.users.index'))
                ->post(route('super-admin.users.password', $target), [
                    'your_password' => self::ACTOR_PASSWORD,
                    'password' => self::NEW_PASSWORD,
                    'password_confirmation' => self::NEW_PASSWORD,
                    $field => ['an' => 'array'],
                ])
                ->assertSessionHasErrors($field);
        }

        $this->assertTrue(Hash::check(self::ACTOR_PASSWORD, $target->refresh()->password));
    }
}
