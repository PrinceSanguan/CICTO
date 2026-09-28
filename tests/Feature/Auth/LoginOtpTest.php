<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Enums\SecurityEventType;
use App\Mail\LoginOtpMail;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\LoginOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The emailed sign-in code (client request, 2026-09-25): a right password no
 * longer opens the dashboard by itself.
 *
 * phpunit.xml switches the code off for the rest of the suite; this class
 * switches it back on.
 */
class LoginOtpTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cicto.login_otp.enabled' => true]);
        Mail::fake();

        $this->user = $this->admin($this->office());
    }

    private function signIn(?User $user = null, bool $remember = false): TestResponse
    {
        $user ??= $this->user;

        return $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => $remember ? 'on' : null,
        ]);
    }

    /** The code in the most recent sign-in email to $user. */
    private function codeFor(?User $user = null): string
    {
        $user ??= $this->user;
        $code = null;

        Mail::assertSent(LoginOtpMail::class, function (LoginOtpMail $mail) use ($user, &$code): bool {
            if ($mail->hasTo($user->email)) {
                $code = $mail->code;
            }

            return true;
        });

        $this->assertIsString($code, 'No sign-in code was emailed.');

        return $code;
    }

    private function wrongCode(string $right): string
    {
        return $right === '000000' ? '111111' : '000000';
    }

    public function test_a_right_password_emails_a_code_and_does_not_sign_in(): void
    {
        $this->signIn()->assertRedirect(route('login.otp'));

        $this->assertGuest();
        Mail::assertSent(LoginOtpMail::class, 1);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->codeFor());

        // Nothing behind the login answers yet.
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->get(route('login.otp'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/login-otp')
                ->where('length', 6)
                ->where('email', LoginOtp::maskEmail($this->user->email)));
    }

    public function test_the_code_is_not_in_the_subject_line(): void
    {
        $this->signIn();

        Mail::assertSent(LoginOtpMail::class, function (LoginOtpMail $mail): bool {
            $mail->assertHasSubject('[CICTO] Your sign-in code');
            $mail->assertDontSeeInText($mail->code);   // grouped as "482 913" in the body
            $mail->assertSeeInText(trim(chunk_split($mail->code, 3, ' ')));

            return true;
        });
    }

    public function test_a_wrong_password_sends_nothing(): void
    {
        $this->post(route('login.store'), ['email' => $this->user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        Mail::assertNothingSent();
    }

    public function test_the_right_code_signs_in_and_goes_to_the_role_home(): void
    {
        $this->signIn();
        $before = session()->getId();

        $this->post(route('login.otp.verify'), ['code' => $this->codeFor()])
            ->assertRedirect(route(Role::Admin->homeRoute()));

        $this->assertAuthenticatedAs($this->user);

        // A new session id at the moment of signing in: an id planted before
        // the password (session fixation) is worthless afterwards.
        $this->assertNotSame($before, session()->getId());

        // And the pending sign-in -- with the code's hash -- is gone the moment
        // it is used, not left to be cleared by the next sign-out.
        $this->assertNull(session('login_otp'));
        $this->assertTrue(SecurityEvent::query()->where('type', SecurityEventType::LoginSucceeded->value)->exists());

        // The code works once.
        $this->post(route('logout'));
        $this->post(route('login.otp.verify'), ['code' => $this->codeFor()])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_remember_me_survives_the_code_step(): void
    {
        $this->signIn(remember: true);

        $response = $this->post(route('login.otp.verify'), ['code' => $this->codeFor()]);

        $remember = collect($response->headers->getCookies())
            ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));

        $this->assertNotNull($remember, 'Remember me was lost between the password and the code.');
    }

    public function test_the_page_the_person_was_going_to_is_where_they_land(): void
    {
        $document = $this->registerDocument($this->user->office, $this->staff($this->user->office));

        $this->get(route('documents.show', $document))->assertRedirect(route('login'));
        $this->signIn();

        $this->post(route('login.otp.verify'), ['code' => $this->codeFor()])
            ->assertRedirect(route('documents.show', $document));
    }

    public function test_wrong_codes_count_down_and_five_cancel_the_sign_in(): void
    {
        $this->signIn();
        $wrong = $this->wrongCode($this->codeFor());

        foreach ([4, 3, 2, 1] as $left) {
            $this->post(route('login.otp.verify'), ['code' => $wrong])
                ->assertSessionHasErrors(['code' => "That code is not correct. {$left} ".($left === 1 ? 'try' : 'tries').' left.']);
        }

        $this->post(route('login.otp.verify'), ['code' => $wrong])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertTrue(SecurityEvent::query()->where('type', SecurityEventType::LoginOtpLockout->value)->exists());

        // The right code is no use now: the sign-in has to start again.
        $this->post(route('login.otp.verify'), ['code' => $this->codeFor()])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_attempt_past_the_limit_is_not_compared_even_if_right(): void
    {
        $this->signIn();
        $code = $this->codeFor();

        // Five guesses already counted against this code, e.g. sent all at once.
        $pending = session('login_otp');
        foreach (range(1, 5) as $attempt) {
            app(LoginOtp::class)->countAttempt($pending);
        }

        $this->post(route('login.otp.verify'), ['code' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_expired_code_is_refused_and_a_new_one_works(): void
    {
        Carbon::setTestNow('2026-09-25 09:00:00');
        $this->signIn();
        $old = $this->codeFor();

        Carbon::setTestNow('2026-09-25 09:11:00');
        $this->post(route('login.otp.verify'), ['code' => $old])
            ->assertSessionHasErrors(['code' => 'This code has expired. Press "Send a new code" and use the newest email.']);

        $this->post(route('login.otp.resend'))->assertRedirect(route('login.otp'));
        Mail::assertSent(LoginOtpMail::class, 2);

        $new = $this->codeFor();

        if ($new !== $old) {
            $this->post(route('login.otp.verify'), ['code' => $old])->assertSessionHasErrors('code');
        }

        $this->post(route('login.otp.verify'), ['code' => $new])->assertRedirect();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_new_code_cannot_be_asked_for_straight_away(): void
    {
        $this->signIn();

        $this->post(route('login.otp.resend'))
            ->assertSessionHasErrors('code');

        Mail::assertSent(LoginOtpMail::class, 1);
    }

    public function test_one_account_is_sent_at_most_five_codes_in_fifteen_minutes(): void
    {
        // A minute and a bit apart, as a person would -- Fortify's own limit
        // of five password tries a minute would otherwise answer first.
        $at = Carbon::parse('2026-09-25 09:00:00');

        foreach (range(1, 5) as $attempt) {
            Carbon::setTestNow($at->addSeconds(70));
            $this->signIn()->assertRedirect(route('login.otp'));
            $this->post(route('login.otp.cancel'));
        }

        Carbon::setTestNow($at->addSeconds(70));
        $this->signIn()->assertSessionHasErrors(['email' => 'Too many sign-in codes were sent to this account. Try again in 10 minutes.']);
        $this->assertGuest();
        Mail::assertSent(LoginOtpMail::class, 5);

        RateLimiter::clear('login-otp-sends:'.$this->user->id);
    }

    public function test_a_deactivated_account_gets_no_code(): void
    {
        $this->user->forceFill(['is_active' => false])->save();

        $this->signIn()->assertSessionHasErrors(['email' => 'This account has been deactivated. Please contact your administrator.']);

        Mail::assertNothingSent();
        $this->assertGuest();
    }

    public function test_an_account_deactivated_mid_sign_in_is_not_let_in(): void
    {
        $this->signIn();
        $code = $this->codeFor();

        $this->user->forceFill(['is_active' => false])->save();

        $this->post(route('login.otp.verify'), ['code' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_authenticator_app_user_answers_that_instead_of_an_email(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->withTwoFactor()->create();

        $this->signIn($user)->assertRedirect(route('two-factor.login'));

        Mail::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_failed_email_is_reported_on_the_login_form(): void
    {
        // Faked, so the failure is asserted as reported without writing a
        // real ERROR line into storage/logs/laravel.log on every test run.
        Exceptions::fake();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $this->signIn()->assertSessionHasErrors([
            'email' => 'We could not email your sign-in code just now. Please try again in a few minutes.',
        ]);

        $this->assertGuest();
        $this->get(route('login.otp'))->assertRedirect(route('login'));

        // Told to the log for whoever looks after the server -- not swallowed.
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === 'SMTP is down');
    }

    public function test_the_code_page_needs_a_sign_in_in_progress(): void
    {
        $this->get(route('login.otp'))->assertRedirect(route('login'));
        $this->post(route('login.otp.verify'), ['code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_use_a_different_account_drops_the_pending_sign_in(): void
    {
        $this->signIn();
        $code = $this->codeFor();

        $this->post(route('login.otp.cancel'))->assertRedirect(route('login'));

        $this->post(route('login.otp.verify'), ['code' => $code])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_switched_off_a_password_signs_straight_in(): void
    {
        config(['cicto.login_otp.enabled' => false]);

        $this->signIn()->assertRedirect(route(Role::Admin->homeRoute()));

        $this->assertAuthenticatedAs($this->user);
        Mail::assertNothingSent();
    }

    /**
     * Found in QA 2026-09-25: with the code by email, a wrong address locks an
     * account out -- and nothing on screen could change somebody else's. The
     * console can.
     */
    public function test_the_console_moves_an_account_to_a_real_inbox_and_the_code_follows(): void
    {
        $old = $this->user->email;

        $this->artisan('cicto:user', ['email' => $old, '--email' => 'Maria.Santos@Example.PH'])
            ->assertSuccessful();

        $this->user->refresh();
        $this->assertSame('maria.santos@example.ph', $this->user->email);
        $this->assertNotNull($this->user->email_verified_at);
        $this->assertTrue(SecurityEvent::query()->where('type', SecurityEventType::EmailChangedByAdmin->value)->exists());

        $this->signIn()->assertRedirect(route('login.otp'));
        Mail::assertSent(LoginOtpMail::class, fn (LoginOtpMail $mail): bool => $mail->hasTo('maria.santos@example.ph'));
        Mail::assertNotSent(LoginOtpMail::class, fn (LoginOtpMail $mail): bool => $mail->hasTo($old));
    }

    public function test_the_console_refuses_a_taken_address_or_a_missing_account(): void
    {
        $other = $this->staff($this->user->office);

        $this->artisan('cicto:user', ['email' => $this->user->email, '--email' => $other->email])
            ->assertFailed();
        $this->assertNotSame($other->email, $this->user->refresh()->email);

        // A typo in the CURRENT address must not mint a new account.
        $before = User::query()->count();
        $this->artisan('cicto:user', ['email' => 'nobody@nowhere.test', '--email' => 'fresh@example.ph'])
            ->assertFailed();
        $this->assertSame($before, User::query()->count());
    }

    public function test_the_address_on_screen_is_masked(): void
    {
        $this->assertSame('o•••••••n@baliwag.gov.ph', LoginOtp::maskEmail('ocm.admin@baliwag.gov.ph'));
        $this->assertSame('a•@x.ph', LoginOtp::maskEmail('ab@x.ph'));
        $this->assertSame('j•••n@gmail.com', LoginOtp::maskEmail('juan@gmail.com'));
    }
}
