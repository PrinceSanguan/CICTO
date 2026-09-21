<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        // Registration goes through the same RoleAwareLoginResponse as login,
        // so a new account lands on Home like every other plain user.
        $response->assertRedirect(route('home', absolute: false));
    }

    /**
     * The login screen does not invite a visitor to make their own account,
     * client request 2026-09-21.
     *
     * Accounts in a municipal register are ISSUED: a Super Admin creates them
     * on Manage Users, or OfficeAccountSeeder mints one per office at
     * rollout. "Don't have an account? Register" was the first thing anyone
     * reaching the site saw, and it said the opposite.
     *
     * Asserted against the page SOURCE as well as the response, because the
     * link is rendered by an Inertia component the server never expands --
     * a route-level assertion alone would pass with the link still on screen.
     */
    public function test_the_login_screen_does_not_offer_registration(): void
    {
        $this->get(route('login'))->assertOk();

        $source = (string) file_get_contents(
            resource_path('js/pages/auth/login.tsx'),
        );

        /*
         * The LINK, not the word. An earlier version of this test searched
         * the file for "Register" and failed on the comment explaining why
         * the link was taken out -- a test that cannot survive its own
         * subject being documented is not testing the subject.
         */
        $this->assertStringNotContainsString(
            'register()',
            $source,
            'The login screen links to registration again.',
        );
        $this->assertStringNotContainsString(
            "from '@/routes'",
            $source,
            'The register route is imported again.',
        );
    }

    /**
     * ...and removing the link did not close the door behind it. The client
     * asked for the text to go, not for registration to stop working; that is
     * a separate decision, and it lives in config/fortify.php.
     */
    public function test_the_registration_route_still_works(): void
    {
        $this->get(route('register'))->assertOk();
    }
}
