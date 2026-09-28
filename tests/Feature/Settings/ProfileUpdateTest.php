<?php

namespace Tests\Feature\Settings;

use App\Enums\SecurityEventType;
use App\Mail\EmailChangedMail;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\LoginOtp;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * These cases describe a host that can send, and now have to say so.
         *
         * phpunit.xml pins MAIL_MAILER=array, which OutgoingMail counts as no
         * transport. As of 2026-08-23 ProfileController only clears
         * `email_verified_at` on an email change when a transport exists --
         * because User implements MustVerifyEmail now, so clearing it on a host
         * that can never re-verify locks the account out of every protected
         * screen with no way back. `test_profile_information_can_be_updated`
         * asserts the column IS cleared, which is the mail-ON answer.
         *
         * The mail-OFF half is pinned in
         * Tests\Feature\Auth\VerifiedMiddlewareTest.
         */
        config()->set('mail.default', 'smtp');
        Notification::fake();
        // Under `smtp` the notice to the old address is a real send, and the
        // dev .env holds a real Gmail account.
        Mail::fake();
    }

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    /**
     * Each person may move their own account to their own address (client,
     * 2026-09-28) -- with the password, because the address is where the
     * sign-in code goes.
     */
    public function test_changing_your_email_needs_your_password(): void
    {
        $user = User::factory()->create();
        $old = $user->email;

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'juan.delacruz@example.com',
        ])->assertSessionHasErrors(['current_password' => 'Enter your current password to change your email address.']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'juan.delacruz@example.com',
            'current_password' => 'not-it',
        ])->assertSessionHasErrors(['current_password' => 'That is not your current password.']);

        $this->assertSame($old, $user->refresh()->email);
        Mail::assertNothingSent();
    }

    public function test_the_move_is_confirmed_from_the_new_inbox_and_told_to_the_old_one(): void
    {
        $user = User::factory()->create(['email' => 'cictobaliwagcity+ocm.admin@gmail.com', 'name' => 'OCM Admin']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@example.com',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('juan.delacruz@example.com', $user->email);
        $this->assertNull($user->email_verified_at, 'Confirmed from the new inbox before it is used.');
        Notification::assertSentTo($user, VerifyEmail::class);

        // The old inbox -- on a shared office account, CICTO's -- is told.
        Mail::assertSent(EmailChangedMail::class, fn (EmailChangedMail $mail) => $mail->hasTo('cictobaliwagcity+ocm.admin@gmail.com')
            && $mail->newAddress === 'juan.delacruz@example.com');

        $this->assertTrue(SecurityEvent::query()
            ->where('type', SecurityEventType::EmailChangedByOwner->value)
            ->where('user_id', $user->id)
            ->where('summary', 'like', '%cictobaliwagcity+ocm.admin@gmail.com to juan.delacruz@example.com%')
            ->exists());
    }

    public function test_a_name_or_letter_case_edit_needs_no_password_and_changes_no_address(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Maria Santos',
            'email' => 'Maria@Example.com',
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Maria Santos', $user->name);
        $this->assertSame('maria@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        Mail::assertNothingSent();
    }

    public function test_the_notice_shows_the_new_address_masked(): void
    {
        $mail = new EmailChangedMail(recipientName: 'OCM Admin', newAddress: 'juan.delacruz@example.com', ipAddress: '203.0.113.9');

        $mail->assertSeeInHtml(LoginOtp::maskEmail('juan.delacruz@example.com'), false);
        $this->assertStringStartsWith('j•', LoginOtp::maskEmail('juan.delacruz@example.com'));
        $mail->assertDontSeeInHtml('juan.delacruz@example.com');
        $mail->assertSeeInText('203.0.113.9');
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
