<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limits password-reset link requests, per IP.
 *
 * This route had no limiter of any kind, and until MAIL_MAILER became smtp it
 * did not need one: RequireOutgoingMail refused every POST before the broker
 * ran, so the mail-off configuration WAS the throttle. Turning mail on removed
 * it without anyone touching this file.
 *
 * What is left in the framework is not enough on its own. config/auth.php's
 * `'throttle' => 60` lives on the password broker and is keyed on the EMAIL
 * ADDRESS, so it stops one address being mailed twice a minute and does nothing
 * at all about one client walking a staff list: N known addresses is N real
 * Gmail messages a minute, each carrying a working reset link, out of the
 * client's own account. Two costs, both bad -- the free App Password quota is
 * roughly 500 recipients a day, and after it is spent Gmail refuses everything
 * for 24 hours, which takes support tickets and verification mail down with it.
 *
 * Sits beside ThrottlePublicRegistration and works the same way, for the same
 * reason: Fortify declares this route itself and exposes no limiter key for it,
 * and a getByName() lookup while providers boot finds nothing because the
 * router's name map is not built yet. Registered in config/fortify.php's
 * middleware stack, next to RequireOutgoingMail.
 *
 * The limit is per IP and deliberately looser than registration's: a shared
 * municipal NAT can legitimately produce several forgotten passwords in an
 * hour, and the failure mode of being too tight is locking real staff out of
 * the only self-service route they have.
 */
class ThrottlePasswordResetRequests
{
    /** Reset-link requests allowed per IP per hour. */
    private const LIMIT = 10;

    /** How long the window lasts, in seconds. */
    private const WINDOW = 3600;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('password.email')) {
            return $next($request);
        }

        $key = 'password-reset:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            $seconds = RateLimiter::availableIn($key);

            /*
             * A field error rather than a 429, matching RequireOutgoingMail and
             * ThrottlePublicRegistration. Inertia renders it under the input;
             * a 429 would replace the whole page with the error card and lose
             * what they typed.
             */
            return back()->withErrors([
                'email' => "Too many reset requests from this connection. Try again in {$seconds} seconds, ".
                    'or ask your administrator to set a new password for you.',
            ]);
        }

        RateLimiter::hit($key, self::WINDOW);

        return $next($request);
    }
}
