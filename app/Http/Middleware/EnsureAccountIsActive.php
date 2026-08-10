<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivating an account must actually lock it out.
 *
 * `is_active` was being checked inside individual policies, which meant every
 * POLICY-GATED action was refused but every plain list, dashboard and print
 * route was not -- a deactivated employee kept a valid session and could still
 * browse control numbers and print labels until the session expired.
 *
 * Authorization by omission is the failure mode here, so the check lives in one
 * place that every authenticated route passes through, rather than being
 * something each new controller has to remember.
 */
class EnsureAccountIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'This account has been deactivated. Please contact your administrator.']);
        }

        return $next($request);
    }
}
