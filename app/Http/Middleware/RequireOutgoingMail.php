<?php

namespace App\Http\Middleware;

use App\Support\OutgoingMail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse to mint a password-reset token that nobody can deliver.
 *
 * Client question B3. Fortify's forgot-password flow is enabled and it does not
 * check whether mail works: on MAIL_MAILER=log it generates a real, working
 * reset token, writes the whole message -- link included -- into a log file,
 * and returns the green "We have emailed your password reset link" status. The
 * user is told something that did not happen, and a credential that opens their
 * account is sitting in a file that is not treated as a secret.
 *
 * Both halves are fixed here. The page itself now says plainly that no link can
 * be sent and points at the administrator instead (auth/forgot-password), and
 * this stops the request even if somebody posts to the route directly.
 *
 * Registered in config/fortify.php's middleware stack rather than on a route,
 * because Fortify declares its own routes; the routeIs() guard is what keeps it
 * inert on the other fourteen. Only `password.email` is covered, deliberately
 * -- `password.update` completes a token that was already issued and delivered,
 * and refusing that would strand somebody holding a link that was legitimate
 * when they got it.
 */
class RequireOutgoingMail
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('password.email') || OutgoingMail::isConfigured()) {
            return $next($request);
        }

        // An error rather than a status, so the page renders it in red under
        // the field instead of as the green confirmation it replaces.
        return back()->withErrors([
            'email' => 'This server cannot send email yet, so no reset link can be sent. '.
                'Ask your administrator to set a new password for you.',
        ]);
    }
}
