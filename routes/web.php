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
use Laravel\Fortify\Contracts\LoginViewResponse;

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
| §3 "Separate login entry points for User, Admin, and Super Admin".
|
| Three URLs, one page, one guard, one POST target. The portal only changes what
| the page says; it never reaches Auth::attempt, the session, or any
| authorization decision. Trusting a role posted from a login form would be
| privilege escalation with extra steps.
|
| A portal mismatch is IGNORED, not rejected: a clerk who signs in at
| /login/admin lands on the clerk dashboard. Rejecting them would leak which
| accounts are admins, and buys nothing -- the role in the database is what
| authorises, and it already did.
*/
$portalLogin = function (string $portal) {
    return function (Request $request) use ($portal) {
        // Read back by the Fortify::loginView closure and passed to the page as
        // a presentational prop only.
        $request->attributes->set('portal', $portal);

        return app(LoginViewResponse::class);
    };
};

// Fortify already owns GET /login for the default portal.
Route::get('login/admin', $portalLogin('admin'))->middleware('guest')->name('login.admin');
Route::get('login/super-admin', $portalLogin('super-admin'))->middleware('guest')->name('login.super-admin');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // §4 main navigation. Reports lands in Phase 3 (#15) and Help in Phase 4
    // (§23); both ship now as routed, honestly-labelled placeholders so the
    // application looks whole rather than growing a mysterious menu later.
    // §19 Reports and Analytics
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
    /*
    | §23 Help & Support. Static knowledge base, an emailed ticket and contact
    | details -- see HelpController for why it is deliberately that small.
    */
    Route::get('help', [HelpController::class, 'index'])->name('help.index');
    Route::get('help/knowledge-base', [HelpController::class, 'knowledgeBase'])
        ->name('help.knowledge-base');
    Route::get('help/knowledge-base/{slug}', [HelpController::class, 'article'])
        ->name('help.article');
    Route::get('help/contact', [HelpController::class, 'contact'])->name('help.contact');
    Route::get('help/ticket', [HelpController::class, 'ticket'])->name('help.ticket');
    Route::post('help/ticket', [HelpController::class, 'submitTicket'])
        ->middleware('throttle:6,1')
        ->name('help.ticket.store');
});

require __DIR__.'/documents.php';
require __DIR__.'/panels.php';
require __DIR__.'/settings.php';

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
