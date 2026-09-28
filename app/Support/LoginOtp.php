<?php

namespace App\Support;

use App\Mail\LoginOtpMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The emailed sign-in code (client request, 2026-09-25): a correct password
 * no longer opens the dashboard by itself.
 *
 * Between the password and the code, the person is NOT signed in. The session
 * holds only a pending sign-in -- which account, "remember me", and the hash of
 * the code last sent -- so nothing past the login screen answers them until
 * the code is right. The code itself is never stored, logged or sent anywhere
 * but the account's own inbox.
 *
 * Two counters live in the cache, not the session, because the session is
 * written once per request and parallel requests would overwrite each other:
 *  - wrong codes, per code sent, numbered BEFORE the comparison (the same
 *    lesson as the Security PIN: a burst of guesses must not all be checked
 *    against one stale count);
 *  - codes sent, per account per 15 minutes, which also protects the shared
 *    Gmail allowance from somebody who knows a password.
 */
final class LoginOtp
{
    public const LENGTH = 6;

    private const SESSION_KEY = 'login_otp';

    private const SEND_WINDOW_SECONDS = 900;

    /** A pending sign-in older than this starts again from the password. */
    private const ABANDON_SECONDS = 1800;

    public static function enabled(): bool
    {
        return (bool) config('cicto.login_otp.enabled', true);
    }

    public static function ttlMinutes(): int
    {
        return max(1, (int) config('cicto.login_otp.ttl_minutes', 10));
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) config('cicto.login_otp.max_attempts', 5));
    }

    public static function resendSeconds(): int
    {
        return max(0, (int) config('cicto.login_otp.resend_seconds', 60));
    }

    public static function maxSends(): int
    {
        return max(1, (int) config('cicto.login_otp.max_sends', 5));
    }

    /**
     * The password was right: hold the sign-in and email the first code.
     *
     * @throws ValidationException when no code can be sent -- shown on the
     *                             login form, under the email field
     */
    public function start(Request $request, User $user, bool $remember): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->id,
            'remember' => $remember,
            'started_at' => now()->getTimestamp(),
        ]);

        try {
            $this->send($request, $user, 'email');
        } catch (ValidationException $e) {
            $this->forget($request);

            throw $e;
        }
    }

    /**
     * Email a fresh code, replacing any earlier one.
     *
     * @throws ValidationException
     */
    public function send(Request $request, User $user, string $errorKey = 'code'): void
    {
        $sends = 'login-otp-sends:'.$user->id;

        if (RateLimiter::tooManyAttempts($sends, self::maxSends())) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($sends) / 60));

            throw ValidationException::withMessages([
                $errorKey => "Too many sign-in codes were sent to this account. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.',
            ]);
        }

        $code = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);

        try {
            Mail::to($user)->send(new LoginOtpMail(
                user: $user,
                code: $code,
                ttlMinutes: self::ttlMinutes(),
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent(),
            ));
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                $errorKey => 'We could not email your sign-in code just now. Please try again in a few minutes.',
            ]);
        }

        RateLimiter::hit($sends, self::SEND_WINDOW_SECONDS);

        $pending = (array) $request->session()->get(self::SESSION_KEY, []);

        $request->session()->put(self::SESSION_KEY, [
            ...$pending,
            'challenge' => (string) Str::uuid(),
            'hash' => self::hash($code),
            'sent_at' => now()->getTimestamp(),
            'expires_at' => now()->addMinutes(self::ttlMinutes())->getTimestamp(),
        ]);
    }

    /**
     * The sign-in waiting for its code, or null when there is none (or it was
     * abandoned long enough ago that it should start over).
     *
     * @return array{user_id: int, remember: bool, started_at: int, challenge?: string, hash?: string, sent_at?: int, expires_at?: int}|null
     */
    public function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ! isset($pending['user_id'], $pending['started_at'])) {
            return null;
        }

        if (now()->getTimestamp() - (int) $pending['started_at'] > self::ABANDON_SECONDS) {
            $this->forget($request);

            return null;
        }

        /** @var array{user_id: int, remember: bool, started_at: int, challenge?: string, hash?: string, sent_at?: int, expires_at?: int} $pending */
        return $pending;
    }

    /**
     * The account of a pending sign-in, if it still exists and may sign in.
     *
     * @param  array<string, mixed>  $pending
     */
    public function user(array $pending): ?User
    {
        $user = User::query()->find($pending['user_id'] ?? null);

        return $user instanceof User && $user->is_active ? $user : null;
    }

    /** @param  array<string, mixed>  $pending */
    public function isExpired(array $pending): bool
    {
        return now()->getTimestamp() >= (int) ($pending['expires_at'] ?? 0);
    }

    /**
     * Seconds until another code may be requested; 0 when it may be now.
     *
     * @param  array<string, mixed>  $pending
     */
    public function resendIn(array $pending): int
    {
        return max(0, (int) ($pending['sent_at'] ?? 0) + self::resendSeconds() - now()->getTimestamp());
    }

    /**
     * Number this attempt against the code it is for, BEFORE comparing it.
     * Returns the attempt number; past maxAttempts() the caller must not
     * compare at all.
     *
     * @param  array<string, mixed>  $pending
     */
    public function countAttempt(array $pending): int
    {
        return RateLimiter::increment(
            'login-otp-attempts:'.($pending['challenge'] ?? 'none'),
            self::ttlMinutes() * 60,
        );
    }

    /** @param  array<string, mixed>  $pending */
    public function matches(array $pending, string $code): bool
    {
        return isset($pending['hash']) && hash_equals((string) $pending['hash'], self::hash($code));
    }

    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * "ocm.admin@baliwag.gov.ph" -> "o•••••••n@baliwag.gov.ph": enough to
     * recognise which inbox to open, not enough to read an address off a
     * screen somebody else is looking at.
     */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $length = mb_strlen($local);

        $masked = $length <= 2
            ? mb_substr($local, 0, 1).'•'
            : mb_substr($local, 0, 1).str_repeat('•', max(3, $length - 2)).mb_substr($local, -1);

        return $domain === '' ? $masked : $masked.'@'.$domain;
    }

    /** Keyed with the app key, so a leaked session row does not give up a code by brute force. */
    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
