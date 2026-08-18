<?php

use App\Enums\Role;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\SuperAdmin\SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\SuperAdminReportController;
use App\Http\Controllers\SuperAdmin\SuperAdminUserController;
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

        // §4's four sidebar items, all now real screens.
        Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('reports', [AdminReportController::class, 'index'])->name('reports.index');
        Route::get('settings', [AdminSettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('settings', [AdminSettingsController::class, 'update'])->name('settings.update');
    });

Route::middleware(['auth', 'verified', EnsureRole::using(Role::SuperAdmin)])
    ->prefix('super-admin')
    ->name('super-admin.')
    ->group(function () {
        Route::get('dashboard', [SuperAdminDashboardController::class, 'index'])->name('dashboard');

        Route::get('users', [SuperAdminUserController::class, 'index'])->name('users.index');
        Route::post('users', [SuperAdminUserController::class, 'store'])->name('users.store');

        Route::get('reports', [SuperAdminReportController::class, 'index'])->name('reports.index');

        // §2 system settings, §22 backup console, §21 security log.
        Route::get('settings', [SystemController::class, 'index'])->name('settings.edit');
        Route::post('settings/backup', [SystemController::class, 'backup'])->name('settings.backup');
        Route::post('settings/backups/{run}/restored', [SystemController::class, 'recordRestore'])->name('settings.backup.restored');
        Route::post('settings/verify-signatures', [SystemController::class, 'verifySignatures'])->name('settings.verify-signatures');
        Route::post('settings/workflow', [SystemController::class, 'updateWorkflow'])->name('settings.workflow');
    });
