<?php

use App\Enums\Role;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\SuperAdmin\SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\SystemController;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

/*
| §4's Admin and Super Admin panels.
|
| ONLY role-exclusive screens live under these prefixes. Documents do not --
| see routes/documents.php for why.
|
| EnsureRole::using(...) rather than a stringly-typed 'role:admin' alias, so
| renaming a Role case breaks the build instead of silently opening a route.
*/

Route::middleware(['auth', 'verified', EnsureRole::using(Role::Admin, Role::SuperAdmin)])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

        // §4 names these four sidebar items. Documents and Reports route to the
        // shared screens; Users and Settings are Phase 3 and ship as honest,
        // labelled placeholders rather than dead links.
        Route::inertia('users', 'admin/users/index')->name('users.index');
        Route::inertia('settings', 'admin/settings/index')->name('settings.edit');
    });

Route::middleware(['auth', 'verified', EnsureRole::using(Role::SuperAdmin)])
    ->prefix('super-admin')
    ->name('super-admin.')
    ->group(function () {
        Route::get('dashboard', [SuperAdminDashboardController::class, 'index'])->name('dashboard');

        Route::inertia('users', 'super-admin/users/index')->name('users.index');

        // §2 system settings, §22 backup console, §21 security log.
        Route::get('settings', [SystemController::class, 'index'])->name('settings.edit');
        Route::post('settings/backup', [SystemController::class, 'backup'])->name('settings.backup');
        Route::post('settings/backups/{run}/restored', [SystemController::class, 'recordRestore'])->name('settings.backup.restored');
        Route::post('settings/verify-signatures', [SystemController::class, 'verifySignatures'])->name('settings.verify-signatures');
    });
