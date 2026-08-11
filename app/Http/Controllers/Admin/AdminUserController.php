<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §4's Users screen.
 *
 * Read-only, and deliberately so. Accounts are created and role-changed from
 * the console (`cicto:user`), because those two operations are the ones an
 * attacker wants and the ones an LGU auditor will ask about; putting them
 * behind SSH rather than a session is the cheapest defence available. This
 * screen answers "who has access, in what role, and when were they last here",
 * which is the question a department head actually asks.
 *
 * Scoped to the admin's own office for the same reason every other list is:
 * an office admin has no business enumerating another department's staff. A
 * Super Admin sees everyone.
 */
class AdminUserController extends Controller
{
    /** Matches the mockup's four filter controls. */
    private const SORTS = ['last_login', 'name', 'email', 'role'];

    public function index(Request $request): Response
    {
        $viewer = $request->user();

        $search = trim((string) $request->string('q'));
        $role = $request->string('role')->toString();
        $status = $request->string('status')->toString();
        $sort = $request->string('sort')->toString();

        $role = Role::tryFrom($role);
        $status = in_array($status, ['active', 'inactive'], true) ? $status : null;
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'last_login';

        $query = User::query()
            ->with('office:id,code,name')
            // A Super Admin sees every account; an office admin sees their own
            // office. office_id is null for Super Admins, and `whereNull` would
            // silently match them into every office's list, so the branch is
            // explicit rather than a `when()` on a nullable value.
            ->when(
                $viewer->role !== Role::SuperAdmin,
                fn ($q) => $q->where('users.office_id', $viewer->office_id),
            )
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)).'%';

                // lower() on both sides and an explicit ESCAPE: neither the
                // collation nor the default escape character is guaranteed to
                // be the same across SQLite, MySQL and PostgreSQL.
                $q->where(function ($inner) use ($term) {
                    $inner->whereRaw("lower(users.name) like ? escape '\'", [$term])
                        ->orWhereRaw("lower(users.email) like ? escape '\'", [$term]);
                });
            })
            ->when($role !== null, fn ($q) => $q->where('users.role', $role))
            ->when($status !== null, fn ($q) => $q->where('users.is_active', $status === 'active'));

        $query = match ($sort) {
            'name' => $query->orderByRaw('lower(users.name) asc'),
            'email' => $query->orderByRaw('lower(users.email) asc'),
            'role' => $query->orderBy('users.role'),
            // Nulls last on both drivers: an account that has never signed in
            // sorts to the bottom rather than the top of "Last Login".
            default => $query
                ->orderByRaw('case when users.last_login_at is null then 1 else 0 end')
                ->orderByDesc('users.last_login_at'),
        };

        $page = $query->paginate(perPage: 15)->withQueryString();

        return Inertia::render('admin/users/index', [
            'filters' => [
                'q' => $search,
                'role' => $role?->value,
                'status' => $status,
                'sort' => $sort,
            ],
            'roles' => collect(Role::cases())
                ->map(fn (Role $case) => ['value' => $case->value, 'label' => $case->label()])
                ->all(),
            'users' => [
                'data' => collect($page->items())
                    ->map(fn (User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role->value,
                        'role_label' => $user->role->label(),
                        'office' => $user->office?->code,
                        'is_active' => $user->is_active,
                        'last_login_at' => $user->last_login_at?->toIso8601String(),
                    ])
                    ->all(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
