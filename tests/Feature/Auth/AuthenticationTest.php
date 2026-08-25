<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();

        // Home, not the dashboard: Role::homeRoute() sends a plain user to
        // the landing page as of 2026-08-26. The two panel roles still land on
        // their own panels -- see the two tests below.
        $response->assertRedirect(route('home', absolute: false));
    }

    /*
     * The other half of Role::homeRoute(). Untested until 2026-08-26, when the
     * plain-user destination changed and the split became load-bearing: the
     * three arms of that match are now the whole of §3's "separate login entry
     * points", and nothing else pins two of them.
     */
    public function test_an_admin_lands_on_the_admin_panel_after_login()
    {
        $user = User::factory()->admin()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_a_super_admin_lands_on_the_super_admin_panel_after_login()
    {
        $user = User::factory()->superAdmin()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('super-admin.dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        // §4's design ends the session on a confirmation screen rather than the
        // landing page, which looks identical whether or not sign-out worked --
        // an ambiguity that matters on a shared counter terminal.
        $response->assertRedirect(route('logout.confirmed'));

        $this->assertGuest();
    }

    public function test_the_sign_out_confirmation_is_reachable_without_a_session(): void
    {
        // It renders AFTER the session is destroyed, so anything requiring one
        // would make it unreachable -- which is how a sign-out ends in a
        // redirect loop.
        $this->get(route('logout.confirmed'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/logged-out'));
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }
}
