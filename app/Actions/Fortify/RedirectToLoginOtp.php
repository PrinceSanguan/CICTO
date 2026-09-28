<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\LoginOtp;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\LoginRateLimiter;

/**
 * The emailed sign-in code's place in Fortify's login pipeline (client
 * request, 2026-09-25): after the password is checked, before anybody is
 * signed in.
 *
 * Built on Fortify's own authenticator-app step, which does the same job for
 * that kind of code -- and so inherits its credential check whole: the failed
 * event the Security Log records, the login throttle, the rehash-on-login. A
 * wrong password therefore behaves exactly as it always did; only a RIGHT one
 * is stopped here, with the code sent and the person sent to enter it.
 *
 * Sits AFTER that authenticator step in the pipeline: an account with an
 * authenticator app set up has been redirected to that challenge already and
 * never reaches this one. One second factor per sign-in, not two.
 */
class RedirectToLoginOtp extends RedirectIfTwoFactorAuthenticatable
{
    public function __construct(StatefulGuard $guard, LoginRateLimiter $limiter, private readonly LoginOtp $otp)
    {
        parent::__construct($guard, $limiter);
    }

    public function handle($request, $next)
    {
        if (! LoginOtp::enabled()) {
            return $next($request);
        }

        $user = $this->validateCredentials($request);

        if (! $user instanceof User) {
            return $next($request);
        }

        // Refused here, before a code is emailed: EnsureAccountIsActive would
        // sign a deactivated account out on its first page anyway, and a code
        // sent to it would be a code nobody should be able to use.
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Please contact your administrator.',
            ]);
        }

        $this->otp->start($request, $user, $request->boolean('remember'));

        return $request->wantsJson()
            ? response()->json(['login_otp' => true])
            : redirect()->route('login.otp');
    }
}
