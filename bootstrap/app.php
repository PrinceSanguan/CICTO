<?php

use App\Exceptions\AlreadySignedException;
use App\Exceptions\IllegalTransitionException;
use App\Exceptions\StaleWorkflowStateException;
use App\Http\Middleware\EnforceSessionTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottlePublicRegistration;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            SecurityHeaders::class,
            // POST /register is the only unauthenticated write endpoint here.
            ThrottlePublicRegistration::class,
            // Before HandleInertiaRequests, so a deactivated account never gets
            // as far as having its role and office serialised into a page.
            EnsureAccountIsActive::class,
            // Same reasoning, one step further: an idle session is signed out
            // before the page it asked for is built.
            EnforceSessionTimeout::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Workflow refusals are user-facing, not bugs. Rendered once here so no
        // controller has to branch on document status -- they call the action
        // and let it throw.
        $exceptions->render(function (IllegalTransitionException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 409)
                : back()->withErrors(['action' => $e->getMessage()]);
        });

        // Two people clicking Sign on the same tab, or a stale page whose
        // can.sign was computed before someone else's upload. A refusal, not a
        // crash -- previously this surfaced as a raw 500.
        $exceptions->render(function (AlreadySignedException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 409)
                : back()->withErrors(['method' => $e->getMessage()]);
        });

        // A double-click or a stale tab. Inertia turns the redirect into a 303
        // for non-GET, so React sees an ordinary validation error.
        $exceptions->render(function (StaleWorkflowStateException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 409)
                : back()->withErrors(['action' => $e->getMessage()]);
        });

        /*
         * Branded pages for the statuses a user can actually reach.
         *
         * 403 in particular is a routine outcome here -- office scoping means a
         * clerk who opens another department's document gets one -- and
         * Laravel's stock page for it is unstyled, unbranded and, worst of all,
         * has no link back into the app. 404 and 419 are the same story.
         *
         * 500 and 503 are handled too, but only once debug is off: with debug
         * on the detailed error page is the entire point, and hiding it behind
         * a friendly card would make local development considerably worse.
         */
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            $friendly = in_array($status, [403, 404, 419, 429], true)
                || (in_array($status, [500, 503], true) && ! config('app.debug'));

            if (! $friendly || $request->expectsJson() || $request->is('api/*')) {
                return $response;
            }

            return Inertia::render('error', [
                'status' => $status,
                'authenticated' => $request->hasSession() && Auth::check(),
                /*
                 * Which KIND of 403 this is.
                 *
                 * Office scoping and role gating both abort with 403, and the
                 * page used to explain both as "this document belongs to
                 * another office" -- nonsense for an Admin who typed a Super
                 * Admin URL and opened no document at all.
                 */
                'reason' => $status === 403 && $e->getMessage() === EnsureRole::ROLE_DENIED
                    ? 'role'
                    : null,
            ])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
