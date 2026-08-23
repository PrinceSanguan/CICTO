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
 * inert on the other thirteen.
 *
 * `verification.send` is covered for the same reason as `password.email`, and
 * was missed when this was written: Fortify answers a resend with the green
 * "A new verification link has been sent", which on a null transport is the
 * identical lie about an identical signed URL. It matters more now than it did
 * -- User implements MustVerifyEmail as of 2026-08-23, so an unverified account
 * is genuinely locked out of every protected route and that resend button is
 * the only way through. Telling somebody it worked when it did not leaves them
 * clicking it forever.
 *
 * `password.update` and `verification.verify` stay OUT, deliberately. Both
 * complete a link that was already issued and delivered; refusing them would
 * strand somebody holding a link that was legitimate when they got it, and
 * neither one sends anything.
 */
class RequireOutgoingMail
{
    public function handle(Request $request, Closure $next): Response
    {
        if (OutgoingMail::isConfigured()) {
            return $next($request);
        }

        if ($request->routeIs('verification.send')) {
            // The verify-email screen has no field to hang an error on, so this
            // one is a status: it is the only text on the page that changes.
            return back()->with(
                'status',
                'This server cannot send email yet, so no verification link can be sent. '.
                'Ask your administrator to confirm your account for you.',
            );
        }

        if (! $request->routeIs('password.email')) {
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
