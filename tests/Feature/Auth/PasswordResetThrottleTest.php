<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * The per-IP limit on reset-link requests.
 *
 * POST /forgot-password ran with no limiter of any kind until 2026-08-23, and
 * did not need one: RequireOutgoingMail refused every request before the broker
 * ran, so MAIL_MAILER=log WAS the throttle. Configuring Gmail removed it
 * without anyone editing a file.
 *
 * What the framework leaves behind is not a substitute. config/auth.php's
 * `'throttle' => 60` belongs to the password broker and is keyed on the email
 * ADDRESS: it stops one address being mailed twice in a minute and does nothing
 * whatsoever about one client walking a staff list, which is N real messages a
 * minute out of the client's own Gmail account, each carrying a working reset
 * link. The first case below is the one that would have passed before and is
 * the reason this file exists.
 */
class PasswordResetThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());

        // A server that can send, or RequireOutgoingMail answers first and the
        // limiter is never reached. See PasswordResetTest for the same note.
        config()->set('mail.default', 'smtp');
    }

    public function test_many_different_addresses_from_one_connection_are_cut_off(): void
    {
        Notification::fake();

        // Eleven real accounts, so the broker's own per-address throttle never
        // fires and nothing but the IP limiter can stop this.
        $users = User::factory()->count(11)->create();

        foreach ($users->take(10) as $user) {
            $this->post(route('password.email'), ['email' => $user->email])
                ->assertSessionHasNoErrors();
        }

        $eleventh = $users->last();

        $this->post(route('password.email'), ['email' => $eleventh->email])
            ->assertSessionHasErrors('email');

        // Ten sent, and the eleventh stopped before the broker could mint a
        // token for it.
        Notification::assertSentTimes(ResetPassword::class, 10);
        Notification::assertNotSentTo($eleventh, ResetPassword::class);
    }

    public function test_the_refusal_tells_the_user_what_to_do_instead(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        foreach (range(1, 10) as $ignored) {
            $this->post(route('password.email'), ['email' => User::factory()->create()->email]);
        }

        $this->followingRedirects()
            ->post(route('password.email'), ['email' => $user->email])
            ->assertSee('administrator', false);
    }

    public function test_a_first_request_is_not_throttled(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }
}
