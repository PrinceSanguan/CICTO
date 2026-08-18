<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\Users\CreateUserAccount;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreUserRequest;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §4's Manage Users screen.
 *
 * Unlike the Admin panel's read-only Users list, this one can create accounts
 * -- the client's design puts an Add New Admin button here and only here, which
 * matches the rule AssignUserRole already enforces: an Admin arranges people
 * inside their own office, a Super Admin mints access.
 */
class SuperAdminUserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('q'));

        $users = User::query()
            ->with('office:id,code,name')
            ->when($search !== '', function ($query) use ($search) {
                $term = User::likeTerm($search);

                // lower() on both sides and an explicit ESCAPE: neither the
                // collation nor the default escape character is the same across
                // SQLite, MySQL and PostgreSQL.
                $query->where(function ($inner) use ($term) {
                    $inner->whereRaw("lower(users.name) like ? escape '!'", [$term])
                        ->orWhereRaw("lower(users.email) like ? escape '!'", [$term]);
                });
            })
            // Nulls last on every driver: an account that has never signed in
            // belongs at the bottom of "Last Login", not the top.
            ->orderByRaw('case when users.last_login_at is null then 1 else 0 end')
            ->orderByDesc('users.last_login_at')
            ->paginate(perPage: 15)
            ->withQueryString();

        return Inertia::render('super-admin/users/index', [
            'filters' => ['q' => $search],
            'roles' => collect(Role::cases())
                ->map(fn (Role $role) => ['value' => $role->value, 'label' => $role->label()])
                ->all(),
            'offices' => Office::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name'])
                ->map(fn (Office $office) => [
                    'value' => (string) $office->id,
                    'label' => $office->code.' — '.$office->name,
                ])
                ->all(),
            'users' => [
                'data' => collect($users->items())
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
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
                'total' => $users->total(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request, CreateUserAccount $create): RedirectResponse
    {
        $role = $request->enum('role', Role::class);

        $user = $create->handle(
            actor: $request->user(),
            name: $request->string('name')->value(),
            email: $request->string('email')->value(),
            password: $request->string('password')->value(),
            role: $role,
            office: $role === Role::SuperAdmin
                ? null
                : Office::query()->find($request->integer('office_id')),
        );

        return back()->with('toast', [
            'type' => 'success',
            /*
             * Says what a Super Admin has to do next, because this deployment
             * cannot email the new account its credentials (client question
             * B3). Without that sentence the obvious assumption is that an
             * invitation went out, and the account sits unused.
             */
            'message' => sprintf(
                '%s can now sign in as %s. Outgoing mail is not configured, so give them the password you just set.',
                $user->email,
                $role->label(),
            ),
        ]);
    }
}
