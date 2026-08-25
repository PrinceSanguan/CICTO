<?php

use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\ReportController;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'welcome')->name('home');

/*
| RA 10173 privacy notice. Public and unauthenticated: the QR scan path logs
| the IP address of anyone who scans a label, including members of the public,
| so they have to be able to read what is collected without an account.
*/
Route::get('privacy', function () {
    return Inertia::render('privacy', [
        'retention' => [
            'scans' => (int) config('cicto.scans.retention_days'),
            'securityEvents' => (int) config('cicto.retention.security_events.after_days'),
        ],
        'contact' => config('cicto.security.privacy_contact'),
        'office' => config('cicto.support.office'),
    ]);
})->name('privacy');

/*
| Where the report-only Content-Security-Policy sends its violations.
|
| Public and CSRF-exempt by necessity -- the browser posts this itself, with no
| session and no token, so there is nothing to verify. Rate limited because it
| accepts input from anyone on the internet, and the payload is filtered and
| truncated before it reaches the log.
*/
Route::post('_csp-report', CspReportController::class)
    ->middleware(['throttle:30,1'])
    ->withoutMiddleware([ValidateCsrfToken::class, EnsureAccountIsActive::class])
    ->name('security.csp-report');

/*
| ONE login entry point.
|
| §3 asked for "separate login entry points for User, Admin, and Super Admin",
| and they shipped as /login, /login/admin and /login/super-admin -- three URLs
| rendering one page with a `portal` prop that changed the heading and the
| colour and nothing else. They all posted to Fortify's single /login against
| one guard, because trusting a role posted from a login form would be
| privilege escalation with extra steps.
|
| The client asked on 2026-08-17 for the three chips at the foot of the login
| card to go, leaving one login screen that works out the role afterwards. It
| already did: App\Http\Responses\RoleAwareLoginResponse reads the role from the
| database row and sends the user to their own panel, for all four ways of
| becoming authenticated. Removing the portals removed a choice that never
| decided anything.
|
| The two URLs stay as redirects rather than 404s. They have been in front of
| the client since Phase 1 and are bookmarked, printed in the pilot notes and
| hit by docs/qa/journeys.sh; a redirect costs one line and cannot be the thing
| that goes wrong at 21:00 on handover night.
|
| Fortify owns GET /login itself, so it is not declared here.
*/
Route::redirect('login/admin', '/login')->name('login.admin');
Route::redirect('login/super-admin', '/login')->name('login.super-admin');

/*
| §23 Help & Support, the READING half -- and it is public.
|
| The landing page's main navigation points Help at this route (see NAV in
| resources/js/components/landing/content.ts for why those items became real
| screens), so a visitor with no account was answered with /login when they
| asked to read an FAQ. Nothing on these four pages needs a session:
| HelpController serves static articles out of KnowledgeBase and the office's
| own published contact details out of config.
|
| The WRITING half stays in the authenticated group below. submitTicket
| attributes every ticket to $request->user() -- `from`, `name` and `office`
| all come from the session -- so an anonymous ticket is not a thing that can
| exist. pages/help/contact.tsx offers a guest the sign-in instead of a form
| that would bounce them on submit and lose what they typed.
*/
Route::get('help', [HelpController::class, 'index'])->name('help.index');
Route::get('help/knowledge-base', [HelpController::class, 'knowledgeBase'])
    ->name('help.knowledge-base');
Route::get('help/knowledge-base/{slug}', [HelpController::class, 'article'])
    ->name('help.article');
Route::get('help/contact', [HelpController::class, 'contact'])->name('help.contact');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // §4 main navigation. Reports lands in Phase 3 (#15) and Help in Phase 4
    // (§23); both ship now as routed, honestly-labelled placeholders so the
    // application looks whole rather than growing a mysterious menu later.
    // §19 Reports and Analytics
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

    // The one Help route that has to know who you are.
    Route::get('help/ticket', [HelpController::class, 'ticket'])->name('help.ticket');
    Route::post('help/ticket', [HelpController::class, 'submitTicket'])
        ->middleware('throttle:6,1')
        ->name('help.ticket.store');
});

require __DIR__.'/documents.php';
require __DIR__.'/panels.php';
require __DIR__.'/settings.php';

/*
| The sign-out confirmation, per §4's design.
|
| Public and unauthenticated by construction: it renders after the session has
| been destroyed, so requiring a session would make it unreachable.
*/
Route::inertia('logged-out', 'auth/logged-out')->name('logout.confirmed');

/*
| Unmatched URLs, handled inside the web group.
|
| The exception handler already renders this page for a 404 raised anywhere,
| but a URL matching no route at all never reaches session middleware -- so the
| page could not tell whether the visitor was signed in, and offered a "Go to
| sign in" button to people who already were. Routing the miss through the web
| stack first means the answer is known by the time the page renders.
|
| The router tries fallback routes last regardless of where they are declared,
| so this does not shadow anything required above.
*/
Route::fallback(function (Request $request) {
    return Inertia::render('error', [
        'status' => 404,
        'authenticated' => Auth::check(),
    ])
        ->toResponse($request)
        ->setStatusCode(404);
})->name('fallback');
