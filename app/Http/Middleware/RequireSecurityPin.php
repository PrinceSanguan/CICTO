<?php

namespace App\Http\Middleware;

use App\Models\Document;
use App\Support\SecurityPin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything reachable FROM an open document -- its files, its signing, its
 * comments, its workflow buttons -- needs the same unlocked session the page
 * itself does (client request, 2026-09-25).
 *
 * The View Documents page is not behind this middleware: it answers a locked
 * session with the PIN prompt itself (DocumentController::show), which a
 * redirect to it could not do without looping. Every route here, when locked,
 * sends the browser to that page instead -- so a download link, a stale tab's
 * "Received" button and a bookmarked preview all land on the prompt, and
 * nothing about the document is served first.
 */
class RequireSecurityPin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SecurityPin::isUnlocked($request)) {
            SecurityPin::touch($request);

            return $next($request);
        }

        // A background fetch (the signing panel loading its PDF) cannot show
        // a prompt; tell it plainly so the page can lock itself.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'Enter your Security PIN to view this document.',
            ], 423);
        }

        $document = $request->route('document');

        $redirect = redirect()->to(
            $document instanceof Document
                ? route('documents.show', $document)
                : route('documents.index'),
        );

        // A button pressed on a page whose PIN had lapsed -- "Received", a
        // comment, an upload. Say plainly that it did NOT happen, or the
        // person enters their PIN and walks away believing it did.
        return $request->isMethod('GET')
            ? $redirect
            : $redirect->with('security_pin_locked', 'action');
    }
}
