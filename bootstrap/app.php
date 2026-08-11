<?php

use App\Exceptions\AlreadySignedException;
use App\Exceptions\IllegalTransitionException;
use App\Exceptions\StaleWorkflowStateException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottlePublicRegistration;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

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
    })->create();
