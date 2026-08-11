<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /register is the only unauthenticated write endpoint in the application,
 * and because User does not implement MustVerifyEmail every account it creates
 * is usable immediately rather than parked awaiting a confirmation that
 * MAIL_MAILER=log cannot send.
 */
class RegistrationThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function register(int $n): TestResponse
    {
        return $this->post(route('register.store'), [
            'name' => "Person {$n}",
            'email' => "person{$n}@example.test",
            'password' => 'Corr3ct-Horse-Batt3ry',
            'password_confirmation' => 'Corr3ct-Horse-Batt3ry',
        ]);
    }

    public function test_a_burst_of_registrations_from_one_address_is_refused(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            // Registering signs you in, and the `guest` middleware would bounce
            // every later attempt -- which is exactly how an unlimited endpoint
            // can look limited in a test.
            $this->register($i)->assertSessionHasNoErrors();
            $this->post(route('logout'));
        }

        $this->assertSame(5, User::query()->count());

        // The sixth is refused with something a human can read, not a bare 429.
        $this->register(6)->assertSessionHasErrors('email');
        $this->post(route('logout'));

        $this->assertSame(5, User::query()->count());
    }

    public function test_a_normal_signup_is_unaffected(): void
    {
        $this->register(1)->assertSessionHasNoErrors();

        $this->assertNotNull(User::firstWhere('email', 'person1@example.test'));
    }
}
