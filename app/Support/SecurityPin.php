<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The Security PIN gate on viewing a document (client request, 2026-09-25).
 *
 * The adviser's scenario: somebody leaves their computer signed in with a
 * document open, and whoever sits down next can read it. So each person sets
 * their own 4-digit PIN, the View Documents page asks for it before showing
 * anything, and an unlock lasts only while the person is active -- after
 * `cicto.security_pin.idle_minutes` without activity it lapses and the PIN is
 * asked for again.
 *
 * The unlock lives in the SESSION, not on the user: it is this browser, now,
 * that has proved it knows the PIN. It records whose it is, so a session that
 * somehow outlived a sign-out could never carry one person's unlock over to
 * another. "Last active" is refreshed by every gated request and by the page's
 * heartbeat while somebody is using it -- see resources/js/components/
 * security-pin/security-pin-idle-lock.tsx for the browser half.
 */
final class SecurityPin
{
    public const LENGTH = 4;

    public const SESSION_KEY = 'security_pin.unlocked';

    /** How long unused wrong-PIN attempts are remembered. */
    private const ATTEMPT_WINDOW_SECONDS = 3600;

    public static function enabled(): bool
    {
        return (bool) config('cicto.security_pin.enabled', true);
    }

    public static function idleSeconds(): int
    {
        return max(1, (int) config('cicto.security_pin.idle_minutes', 5)) * 60;
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) config('cicto.security_pin.max_attempts', 5));
    }

    /**
     * Whether this request may be shown a document.
     *
     * Always true with the feature switched off, so every caller can ask the
     * one question without knowing about the switch.
     */
    public static function isUnlocked(Request $request): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return false;
        }

        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || ($state['user_id'] ?? null) !== $user->id) {
            return false;
        }

        return now()->getTimestamp() - (int) ($state['at'] ?? 0) < self::idleSeconds();
    }

    /** The PIN was just proved (or just chosen): open documents for this session. */
    public static function unlock(Request $request): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->id,
            'at' => now()->getTimestamp(),
        ]);

        self::clearAttempts($user);
    }

    /**
     * Mark the person as still active, which is what keeps an unlock alive.
     *
     * A no-op on a session that is not unlocked: activity must never revive an
     * unlock that has already lapsed, or the idle timeout would mean nothing.
     */
    public static function touch(Request $request): void
    {
        if (! self::enabled() || ! self::isUnlocked($request)) {
            return;
        }

        $request->session()->put(self::SESSION_KEY.'.at', now()->getTimestamp());
    }

    public static function lock(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * Count one PIN attempt BEFORE it is checked, and return its number.
     *
     * Before, not after: SecurityPinController::verify refuses an attempt
     * numbered past maxAttempts() without comparing it at all. Counting after
     * the check let a burst of simultaneous requests all be checked against
     * the same stale count -- ten guesses at once, each told "4 tries left".
     *
     * In the cache, per USER, because the increment there is atomic and the
     * session is not (it is read at the start of a request and written at the
     * end, so parallel requests overwrite each other's count). Cleared by a
     * right PIN, a new PIN, and the sign-out that running out causes -- so the
     * owner signing back in starts with every try.
     */
    public static function countAttempt(User $user): int
    {
        return RateLimiter::increment(self::attemptsKey($user), self::ATTEMPT_WINDOW_SECONDS);
    }

    public static function clearAttempts(User $user): void
    {
        RateLimiter::clear(self::attemptsKey($user));
    }

    public static function attemptsLeft(User $user): int
    {
        // attempts() hands back whatever the cache store holds -- a string on
        // the database store -- so it is read as a number here.
        $used = (int) RateLimiter::attempts(self::attemptsKey($user));

        return max(0, self::maxAttempts() - $used);
    }

    private static function attemptsKey(User $user): string
    {
        return 'security-pin-attempts:'.$user->id;
    }

    /**
     * Validation for a PIN being CHOSEN (create, change, reset).
     *
     * Checking a PIN being ENTERED only needs `digits:4` -- refusing an entry
     * for being guessable would tell somebody guessing that it is not 1234.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'digits:'.self::LENGTH,
            'confirmed',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && self::isGuessable($value)) {
                    $fail('Choose a PIN that is harder to guess — not four of the same digit, and not a run like 1234 or 4321.');
                }
            },
        ];
    }

    /**
     * Plain wording for the few ways a typed PIN can be refused. The pop-up
     * only accepts digits and stops at four, so these are mostly what a
     * hand-made request sees -- but "The pin field must be 4 digits." is not a
     * sentence to show staff.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'pin.required' => 'Enter your '.self::LENGTH.'-digit PIN.',
            'pin.string' => 'Your PIN must be '.self::LENGTH.' digits.',
            'pin.digits' => 'Your PIN must be exactly '.self::LENGTH.' digits (0–9).',
            'pin.confirmed' => 'The two PINs do not match. Type the same '.self::LENGTH.' digits in both.',
        ];
    }

    /**
     * The PINs somebody at the desk would try first: one digit four times, or
     * a straight run up or down.
     */
    public static function isGuessable(string $pin): bool
    {
        if (preg_match('/^(\d)\1+$/', $pin) === 1) {
            return true;
        }

        return str_contains('0123456789', $pin) || str_contains('9876543210', $pin);
    }

    /**
     * What the browser needs to know, shared on every page.
     *
     * Never the PIN or its hash -- only whether one exists, whether this
     * session is unlocked, and how long an unlock lasts without activity.
     *
     * @return array{has_pin: bool, unlocked: bool, idle_seconds: int}|null
     */
    public static function forClient(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User || ! self::enabled()) {
            return null;
        }

        return [
            'has_pin' => $user->hasSecurityPin(),
            'unlocked' => self::isUnlocked($request),
            'idle_seconds' => self::idleSeconds(),
        ];
    }
}
