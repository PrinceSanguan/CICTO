<?php

namespace Tests\Feature\Mail;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * What every emailing screen does when the transport is real and it FAILS.
 *
 * This whole class of outcome was unreachable until 2026-08-23. Client question
 * B3 meant MAIL_MAILER=log everywhere, and `log` and `array` accept everything
 * and throw nothing -- so no send in this application had ever failed, and none
 * of them was wrapped. The moment an operator supplied Gmail credentials, four
 * ordinary events -- a revoked App Password, the ~500/day quota, a blocked 587,
 * a lost DNS lookup -- each turned a routine POST into a crash page.
 *
 * These cases pin the honest outcome for each flow. They are the counterpart to
 * MailUnavailableTest, which covers "there is no transport at all"; here there
 * IS one and it is broken, which is a different answer for the user.
 *
 * The failure is produced by pointing the smtp mailer at a port nothing listens
 * on rather than by mocking the facade, so the exception is raised by the real
 * Symfony transport and travels the real path through the handler in
 * bootstrap/app.php. A mock would prove only that the catch block runs.
 */
class OutgoingMailFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->breakTheTransport();
    }

    /**
     * A configured smtp mailer that cannot connect.
     *
     * Port 1 on loopback refuses immediately, so this costs no wall-clock. The
     * timeout is pinned low anyway in case a CI box routes it somewhere slow.
     *
     * `forgetMailers()` matters: MailManager memoises a resolved mailer by
     * name, so a `smtp` instance built by an earlier test in the same process
     * would otherwise keep its old, working transport.
     */
    private function breakTheTransport(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.scheme' => null,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.mailers.smtp.timeout' => 1,
        ]);

        Mail::forgetMailers();
    }

    private function user(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_a_failed_support_ticket_is_reported_as_recorded_but_not_delivered(): void
    {
        config(['cicto.support.email' => 'ict@baliwag.test']);

        $response = $this->actingAs($this->user())
            ->post(route('help.ticket.store'), [
                'name' => 'Emarie Alonzo',
                'email' => 'emarie@example.test',
                'issue_type' => 'QR code will not scan',
                'body' => 'The camera button does nothing.',
            ]);

        // Not a 500, and above all not a success toast: the ticket really was
        // written to the log, so "recorded" is true and "sent" is not.
        $response->assertRedirect();
        $response->assertSessionHas('toast.type', 'warning');
        $response->assertSessionMissing('errors');
    }

    public function test_a_failed_reset_link_request_is_a_field_error_not_a_crash(): void
    {
        $this->skipUnlessFortifyHas(Features::resetPasswords());

        $user = $this->user();

        $response = $this->post(route('password.email'), ['email' => $user->email]);

        /*
         * The important half is what the user is NOT told. PasswordBroker
         * commits the token row before it sends, so on a transport failure a
         * live token exists and config/auth.php's 60-second throttle will
         * refuse the retry. A green "we have emailed your link" here would be
         * the exact lie RequireOutgoingMail was written to prevent, arriving by
         * a different route.
         */
        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $response->assertSessionMissing('status');
    }

    public function test_a_failed_verification_resend_is_a_field_error_not_a_crash(): void
    {
        $this->skipUnlessFortifyHas(Features::emailVerification());

        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHasErrors('email');
    }

    public function test_a_failed_verification_email_still_leaves_a_usable_account_after_registration(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        $response = $this->post(route('register.store'), [
            'name' => 'New Clerk',
            'email' => 'new.clerk@baliwag.test',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ]);

        // The row is committed before the Registered event fires, so bouncing
        // them back to the form would meet a unique-email error on their own
        // brand-new account. They go forward to the screen with the Resend
        // button instead.
        $this->assertDatabaseHas('users', ['email' => 'new.clerk@baliwag.test']);
        $response->assertRedirect(route('verification.notice'));
        $response->assertSessionHas('status');

        /*
         * The half this test originally missed, and the reason the bug shipped
         * past it: asserting the Location header proves only where the browser
         * was POINTED. Fortify fires Registered -- the send that throws --
         * before $guard->login(), so the session was still a guest, and
         * verification.notice is `auth`-gated: the browser was pointed at a
         * screen that would immediately bounce it to /login, eating the flash
         * on the way. Following the redirect is what makes this an assertion
         * about the user's experience rather than about a string.
         */
        $this->assertTrue(auth()->check(), 'the new account should be signed in');

        $this->followingRedirects()
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('could not be sent', false);
    }
}
