<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * That `verified` on the route groups is a gate rather than decoration.
 *
 * routes/web.php:72, settings.php:15, documents.php:23 and :101, panels.php:25
 * and :38 have all read `['auth', 'verified']` since the routes were written,
 * and until 2026-08-23 not one of them enforced anything. The reason was one
 * missing word: User inherited the MustVerifyEmail *trait* from
 * Illuminate\Foundation\Auth\User -- so `hasVerifiedEmail()` existed and the
 * model looked verified-capable -- but did not declare the *contract*, and
 * EnsureEmailIsVerified tests `instanceof MustVerifyEmail`. Six closed-looking
 * doors, none of them latched.
 *
 * It was invisible because it took two things to matter: public registration
 * (which is on) and a way to create an unverified account. It could not be
 * caught by reading the routes, and nothing failed, which is the worst shape a
 * hole can have. This is the regression test for the word.
 */
class VerifiedMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::emailVerification());
    }

    public function test_the_user_model_declares_the_contract_the_middleware_tests_for(): void
    {
        // Asserted on the contract, not on the methods: the methods came free
        // with the trait and were never the problem.
        $this->assertInstanceOf(
            MustVerifyEmail::class,
            User::factory()->create(),
        );
    }

    public function test_an_unverified_account_cannot_reach_a_protected_screen(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_a_verified_account_passes_straight_through(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_self_registration_on_a_host_with_no_mail_is_not_a_dead_account(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        // phpunit.xml's `array` is already a null transport; said out loud
        // because this case is entirely about that branch.
        config()->set('mail.default', 'log');

        $this->post(route('register.store'), [
            'name' => 'Rural Clerk',
            'email' => 'rural.clerk@baliwag.test',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ]);

        $created = User::where('email', 'rural.clerk@baliwag.test')->sole();

        /*
         * Verified on creation, because on this host it can never be verified
         * any other way: the link cannot be delivered and RequireOutgoingMail
         * correctly refuses the resend. Without this the account signs in and
         * reaches nothing, for ever.
         *
         * The regression this guards is subtle -- `email_verified_at` is not in
         * User's #[Fillable], so the obvious implementation (passing it to
         * create()) is silently dropped and this assertion fails while the code
         * reads as though it works.
         */
        $this->assertNotNull($created->email_verified_at);

        $this->actingAs($created)->get(route('dashboard'))->assertOk();
    }

    public function test_changing_your_email_on_a_host_with_no_mail_does_not_lock_you_out(): void
    {
        config()->set('mail.default', 'log');

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'corrected.address@baliwag.test',
            ])
            ->assertSessionHasNoErrors();

        /*
         * Still verified, because on this host it could never be re-verified:
         * nothing mails an email CHANGE (only Registered does), and the resend
         * is refused. Nulling it here would be a one-way door -- and editing
         * the address back re-fires isDirty('email'), so even undoing it does
         * not help.
         */
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_changing_your_email_where_mail_works_does_require_reverification(): void
    {
        config()->set('mail.default', 'smtp');
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'moved.desk@baliwag.test',
        ]);

        $fresh = $user->fresh();

        $this->assertNull($fresh->email_verified_at);
        $this->actingAs($fresh)->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));

        // And a link actually goes out, which Fortify does not do for an email
        // change on its own.
        Notification::assertSentTo($fresh, VerifyEmail::class);
    }

    public function test_registration_now_actually_sends_a_verification_notification(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        Notification::fake();
        config()->set('mail.default', 'smtp');

        $this->post(route('register.store'), [
            'name' => 'New Clerk',
            'email' => 'new.clerk@baliwag.test',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ]);

        $created = User::where('email', 'new.clerk@baliwag.test')->sole();

        // SendEmailVerificationNotification also tests the contract, so before
        // the change this assertion could not pass no matter how mail was set.
        $this->assertNull($created->email_verified_at);
        Notification::assertSentTo($created, VerifyEmail::class);
    }
}
