<?php

use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
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
    Route::inertia('help', 'help/index')->name('help.index');
});

require __DIR__.'/documents.php';
require __DIR__.'/panels.php';
require __DIR__.'/settings.php';
