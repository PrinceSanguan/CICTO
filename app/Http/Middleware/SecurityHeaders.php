<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * §21 "transmitted securely", for the part that is actually ours.
 *
 * HTTPS itself is a hosting fact somebody else has to own -- see the §21
 * checklist. These are the headers the application can set regardless.
 *
 * The CSP ships REPORT-ONLY. Turning it on enforcing without watching the
 * reports first breaks the app in ways that look like random blank panels, and
 * an LGU office cannot debug that. Flip CICTO_CSP_ENFORCE once the report log
 * has been quiet for a week -- the reports land in storage/logs via the `csp`
 * channel, so there is something real to watch.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generated BEFORE the response is built, because the Blade shell has
        // to stamp it onto the inline theme script. Laravel's Vite helper
        // stamps the same value onto the bundle tags it emits.
        $nonce = Str::random(24);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        // The printable label view is plain Blade with an inlined SVG and a
        // <style> block; the SPA is a bundled script. Neither needs to be
        // framed, and nothing here should ever be sniffed into another type.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // A scanned QR lands on a page that must not leak the token to a third
        // party through the Referer header.
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), payment=()');

        if (app()->isProduction()) {
            // Only meaningful over HTTPS, and actively harmful if the host is
            // still HTTP -- browsers remember it. Gated on explicit opt-in,
            // exactly like SESSION_SECURE_COOKIE.
            if (config('cicto.security.hsts', false)) {
                $response->headers->set(
                    'Strict-Transport-Security',
                    'max-age=31536000; includeSubDomains',
                );
            }

            $header = config('cicto.security.csp_enforce', false)
                ? 'Content-Security-Policy'
                : 'Content-Security-Policy-Report-Only';

            $response->headers->set($header, $this->policy($nonce));
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $directives = [
            "default-src 'self'",

            // A NONCE, not 'unsafe-inline'. Two inline scripts are genuinely
            // required and both carry it: the theme script in app.blade.php,
            // which must run before first paint or the page flashes white, and
            // the print-button handler in the label view.
            //
            // 'strict-dynamic' is deliberately absent -- it would disable the
            // 'self' allowance that the Vite bundles rely on.
            sprintf("script-src 'self' 'nonce-%s'", $nonce),

            // Styles keep 'unsafe-inline': the print and certificate views
            // carry whole stylesheets inline, dompdf will not read an external
            // one, and a nonce cannot cover a style="" attribute.
            "style-src 'self' 'unsafe-inline'",

            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        // Without this the report-only policy reports to nobody, and the
        // instruction to "watch the reports for a week" is unfollowable.
        if (config('cicto.security.csp_report', true)) {
            $directives[] = 'report-uri '.route('security.csp-report');
        }

        return implode('; ', $directives);
    }
}
