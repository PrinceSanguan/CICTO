<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limits public self-registration.
 *
 * POST /register is the only unauthenticated WRITE endpoint in the application,
 * Fortify owns the route, and Fortify offers no limiter key for it. Without a
 * limit one script can fill the users table overnight -- and because User does
 * not implement MustVerifyEmail, every account it creates is immediately usable
 * rather than parked awaiting a confirmation that MAIL_MAILER=log cannot send.
 *
 * Applied as middleware rather than by mutating Fortify's route object: the
 * router's name lookups are not populated when providers boot, so a
 * getByName() call there silently finds nothing and the throttle never applies.
 */
class ThrottlePublicRegistration
{
    /** Registrations allowed per IP per hour. */
    private const LIMIT = 5;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('register.store')) {
            return $next($request);
        }

        $key = 'register:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors([
                    'email' => "Too many accounts have been created from this connection. Try again in {$seconds} seconds.",
                ]);
        }

        RateLimiter::hit($key, 3600);

        return $next($request);
    }
}
