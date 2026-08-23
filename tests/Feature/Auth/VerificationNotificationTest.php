<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * Resending the verification link.
 *
 * Both of the original cases here described a server that can send, without
 * saying so -- which was harmless while RequireOutgoingMail guarded only
 * `password.email` and this route sent regardless of transport. As of
 * 2026-08-23 the guard covers `verification.send` too, for the same reason it
 * covered the reset: on a null transport Fortify answered with a green "A new
 * verification link has been sent" about a signed URL that reached nobody, and
 * that lie costs more now than it did -- User implements MustVerifyEmail, so an
 * unverified account is genuinely shut out of every protected screen and this
 * button is its only way through.
 *
 * So the two original cases now declare `smtp` the way PasswordResetTest does,
 * and the third pins the branch that made them fail.
 */
class VerificationNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::emailVerification());

        // phpunit.xml pins MAIL_MAILER=array, which OutgoingMail counts as no
        // transport at all. Without this the guard answers first and these
        // cases assert the emailed flow against a server with no email.
        config()->set('mail.default', 'smtp');
    }

    public function test_sends_verification_notification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('home'));

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_does_not_send_verification_notification_if_email_is_verified(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('dashboard', absolute: false));

        Notification::assertNothingSent();
    }

    public function test_a_resend_never_claims_to_have_sent_on_a_server_with_no_transport(): void
    {
        Notification::fake();
        config()->set('mail.default', 'log');

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->post(route('verification.send'));

        // Nothing left the building...
        Notification::assertNothingSent();

        // ...and the page says so, instead of Fortify's green confirmation.
        // A status rather than a field error: the verify-email screen has no
        // input to hang one on.
        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertStringContainsString(
            'cannot send email',
            (string) session('status'),
        );
    }
}
