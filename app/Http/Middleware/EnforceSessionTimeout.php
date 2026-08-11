<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out an idle session after the user's chosen timeout.
 *
 * §4's Settings screen offers a Session Timeout control. Laravel's own
 * `session.lifetime` is a single global value baked into config, so storing a
 * per-user choice and leaving it there would give the panel a setting that
 * silently does nothing -- worse than not offering it, because a clerk on a
 * shared counter terminal would believe the machine locks itself.
 *
 * The stored preference can only ever be SHORTER than the configured session
 * lifetime in practice, and this middleware never extends anything: it is an
 * additional idle check on top of the framework's, never a replacement.
 */
class EnforceSessionTimeout
{
    private const KEY = 'cicto.last_activity';

    public function handle(Request $request, Closure $next): Response
    {
        // hasSession(), not a nullsafe call: stateless routes reach this
        // middleware without one, and the CSP report endpoint is one of them.
        if (! $request->hasSession() || ! Auth::check()) {
            return $next($request);
        }

        $session = $request->session();
        $minutes = (int) Auth::user()->preference('session_timeout', 0);
        $last = $session->get(self::KEY);

        if ($minutes > 0 && is_int($last) && (time() - $last) > $minutes * 60) {
            Auth::logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()
                ->route('login')
                ->with('toast', [
                    'type' => 'info',
                    'message' => "You were signed out after {$minutes} minutes of inactivity.",
                ]);
        }

        $session->put(self::KEY, time());

        return $next($request);
    }
}
