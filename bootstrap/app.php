<?php

use App\Actions\Fortify\CreateNewUser;
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
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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
         * Outgoing mail failed. A refusal to show the user, not a 500.
         *
         * Until MAIL_MAILER became smtp this could not happen: `log` and
         * `array` accept everything, so no send ever threw and no call site
         * needed a catch. A real transport can fail for entirely ordinary
         * reasons -- Gmail rate-limits the account, the App Password is
         * revoked, the host loses DNS for a minute -- and every one of those
         * used to surface as a crash page.
         *
         * The reset flow is the reason this is centralised rather than a
         * try/catch per controller. Fortify owns `password.email`, and
         * PasswordBroker mints and STORES the token before it sends: on a
         * transport failure the row in `password_reset_tokens` is already
         * committed, config/auth.php's 60-second `throttle` then refuses the
         * retry, and the user is left staring at a 500 with a live token they
         * were never given. The message below tells them the truth and points
         * at the same fallback the mail-off page does.
         *
         * Deliberately NOT sending mail is not this: HelpController catches its
         * own send so it can keep the ticket it already wrote to the log, and
         * RequireOutgoingMail still refuses the reset POST outright when the
         * transport cannot deliver at all.
         */
        $exceptions->render(function (TransportExceptionInterface $e, Request $request) {
            Log::error('Outgoing mail failed', [
                'route' => $request->route()?->getName(),
                // The message only; the exception's context can carry the SMTP
                // dialogue, and on an auth failure that dialogue quotes the
                // credential back.
                'error' => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Email could not be sent right now. Please try again shortly.',
                ], 502);
            }

            /*
             * Registration is the one flow where the account already exists by
             * the time the verification mail fails. Sending them back to the
             * form would invite a duplicate-email error on their own new
             * account, so they go forward to the notice screen, which carries
             * a Resend button.
             */
            if ($request->routeIs('register.store')) {
                /*
                 * Sign them in on the way past, because Fortify has not yet.
                 * RegisteredUserController fires the Registered event -- which
                 * is what sends the mail and throws -- BEFORE $guard->login(),
                 * so without this the redirect below lands on `auth`-gated
                 * verification.notice as a guest, bounces to /login, and the
                 * flash is consumed by that hop. The user would see a bare
                 * login page and retry the form, only to be told their own
                 * brand-new address is taken.
                 */
                $registered = app()->bound(CreateNewUser::REGISTERED)
                    ? app()->make(CreateNewUser::REGISTERED)
                    : null;

                if ($registered instanceof User) {
                    Auth::login($registered);
                }

                return redirect()->route('verification.notice')->with(
                    'status',
                    'Your account was created, but the verification email could not be sent. '.
                    'Use Resend below, or ask your administrator.',
                );
            }

            return back()->withErrors([
                'email' => 'Email could not be sent right now. Please try again shortly, '.
                    'or ask your administrator to set a new password for you.',
            ]);
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
