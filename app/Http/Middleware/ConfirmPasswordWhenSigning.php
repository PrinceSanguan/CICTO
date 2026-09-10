<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §15. `password.confirm`, but only for the submits that actually sign.
 *
 * The signing route carries `password.confirm` outright, and the reason is on
 * that route: a signature applied from an unattended logged-in browser
 * identifies the browser, not the person, which is precisely what RA 8792 s.8
 * asks the method to do. The handoff signature is the SAME legal act, so it
 * cannot be the cheaper one to make -- otherwise "sign while forwarding" is
 * simply the way to sign without proving who you are.
 *
 * It cannot be the plain middleware, though. It rides on
 * documents.transitions.store, which is also every receive, approve, reject and
 * complete in the system; putting `password.confirm` on that route would demand
 * a password to press Received. So the gate is asked for only when the request
 * carries a signature block, and every other transition passes through
 * untouched.
 *
 * Delegated to the framework's RequirePassword rather than reimplemented: the
 * timeout, the session key and the 423-vs-redirect split are all behaviour the
 * confirm-password screen and Fortify already agree on, and a second opinion
 * about any of them is a bug waiting to happen.
 */
class ConfirmPasswordWhenSigning
{
    public function __construct(private readonly RequirePassword $requirePassword) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Matches TransitionDocumentRequest::carriesSignature(). Read straight
        // off the request because middleware runs before the form request is
        // resolved -- and a submit with no signature must be indistinguishable
        // from one made before this feature existed.
        if (! filled($request->input('signature_method'))) {
            return $next($request);
        }

        return $this->requirePassword->handle($request, $next);
    }
}
