<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * What "Forgot password" does on a server that cannot send email.
 *
 * Which, per client question B3, is every deployment of this system: CICTO
 * answered on 2026-08-20 that they will not supply email credentials or
 * configuration, and recommended an external service instead. Until somebody
 * sets one up, MAIL_MAILER=log is what ships.
 *
 * Before this, Fortify answered the form with a green "We have emailed your
 * password reset link" -- a statement that was false, about a token that was
 * real, single-use, and had just been written in cleartext into the shared
 * application log. Both halves are asserted here: the page no longer offers the
 * form, and the route no longer mints the token.
 */
class MailUnavailableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());

        // Belt and braces: phpunit.xml already pins `array`, which counts as no
        // transport. Stated rather than inherited, because this file is about
        // exactly one configuration and it should not silently change if the
        // harness does.
        config()->set('mail.default', 'log');
    }

    public function test_the_forgot_password_page_says_it_cannot_send_and_drops_the_form(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/forgot-password')
                ->where('mailConfigured', false));
    }

    public function test_the_page_offers_the_form_again_once_mail_is_configured(): void
    {
        config()->set('mail.default', 'smtp');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('mailConfigured', true));
    }

    /**
     * The half that matters even with no form on screen. A token is a working
     * credential for the account it names; one that cannot be delivered is
     * purely a liability.
     */
    public function test_no_reset_token_is_minted_for_a_request_that_cannot_be_delivered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        // And it must not report success by any other route either.
        $this->assertNull(session('status'));
    }

    /**
     * The refusal must not become an account-enumeration oracle: the same
     * answer for an address that exists and one that does not.
     */
    public function test_the_refusal_says_the_same_thing_for_an_unknown_address(): void
    {
        $user = User::factory()->create();

        $message = 'This server cannot send email yet, so no reset link can be sent. '.
            'Ask your administrator to set a new password for you.';

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasErrors(['email' => $message]);

        $this->flushSession();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'nobody@baliwag.gov.ph'])
            ->assertSessionHasErrors(['email' => $message]);
    }

    /**
     * The guard is scoped to the one route that mints tokens. Sign-in,
     * registration and the rest of Fortify run through the same middleware
     * stack and must be untouched by it.
     */
    public function test_signing_in_still_works_on_a_server_with_no_mail(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    /**
     * The help article that walks somebody through the emailed flow suppresses
     * its own steps and names the administrator instead -- which is now a real
     * procedure on /super-admin/users rather than a workaround.
     */
    public function test_the_help_article_points_at_the_administrator_instead(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('help.article', 'i-forgot-my-password'))
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertFalse($props['support']['mail_configured']);
                $this->assertStringContainsString(
                    'Manage Users',
                    (string) $props['article']['unavailable_without_mail'],
                );
            });
    }
}
