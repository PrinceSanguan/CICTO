<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\Users\CreateUserAccount;
use App\Actions\Users\ResetAccountPassword;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ResetUserPasswordRequest;
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
 *
 * It also sets passwords for other people, which is the module CICTO asked for
 * in place of the SMTP credentials it declined to supply (client question B3,
 * answered 2026-08-20). With no mail service there is no reset link, so this
 * screen is the only way back into a locked-out account short of shell access.
 */
class SuperAdminUserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('q'));

        $users = User::query()
            ->with('office:id,code,name')
            /*
             * Counted, not guessed. The reset panel offers to remove second
             * factors, and an offer to remove something that is not there is
             * both noise and a lie about what the account has on it.
             */
            ->withCount('passkeys')
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

            /*
             * So the list can leave the reset CONTROL off the viewer's own row.
             * The row itself is still listed -- a Super Admin is an account
             * like any other and belongs in the register. Only the button goes,
             * because your own password is changed under Settings > Security,
             * where the current one has to be typed, and ResetAccountPassword
             * refuses this route for yourself in any case.
             */
            'viewerId' => $request->user()->id,
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
                        // The client asked on 2026-09-03 for the register to
                        // say which office each account belongs to. The code
                        // alone was already on the wire and is what the column
                        // leads with; the full name rides along because "PDC"
                        // means nothing to somebody who does not already know
                        // the answer they came here to look up.
                        'office_name' => $user->office?->name,
                        'is_active' => $user->is_active,
                        'last_login_at' => $user->last_login_at?->toIso8601String(),
                        'has_two_factor' => $user->two_factor_confirmed_at !== null,
                        'passkeys' => (int) $user->getAttribute('passkeys_count'),
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
             * Says what a Super Admin has to do next. Without that sentence the
             * obvious assumption is that an invitation went out, and the
             * account sits unused.
             *
             * Phrased as what the system DOES rather than why. It used to read
             * "Outgoing mail is not configured, so ..." -- true on every
             * deployment to date, but the reason stops being true the moment an
             * operator sets MAIL_MAILER, while the behaviour does not: this
             * application never emails a password to anybody, configured mail
             * or not, because a credential in an inbox outlives every use of
             * it.
             */
            'message' => sprintf(
                '%s can now sign in as %s. No invitation is emailed, so give them the password you just set.',
                $user->email,
                $role->label(),
            ),
        ]);
    }

    /**
     * Client question B3's answer, in one route.
     *
     * "You may develop a module that allows the system administrator to reset
     * the password of any user registered in your application" -- CICTO,
     * 2026-08-20, declining to supply SMTP credentials. With no mail service
     * there is no reset link to send, so this is the supported way back into an
     * account whose owner has forgotten the password.
     *
     * The rules live elsewhere on purpose: ResetUserPasswordRequest asks for
     * the administrator's own password, and ResetAccountPassword does the
     * refusing, the revoking and the writing to §21's log.
     */
    public function resetPassword(
        ResetUserPasswordRequest $request,
        User $user,
        ResetAccountPassword $reset,
    ): RedirectResponse {
        $outcome = $reset->handle(
            actor: $request->user(),
            target: $user,
            password: $request->string('password')->value(),
            revokeSecondFactors: $request->boolean('revoke_second_factors'),
        );

        $message = [sprintf(
            'New password set for %s. Nothing is emailed — hand it over in person and ask them to change it under Settings > Security.',
            $user->email,
        )];

        /*
         * Reported rather than assumed. A reset that could not end the other
         * sessions has not locked anybody out of anything, and an administrator
         * resetting a compromised account needs to know that before they walk
         * away from it.
         */
        if ($outcome->sessionsEnded === null) {
            $message[] = 'Sessions already open on other devices could not be ended on this deployment.';
        } elseif ($outcome->sessionsEnded > 0) {
            $message[] = sprintf(
                '%d device(s) already signed in were signed out.',
                $outcome->sessionsEnded,
            );
        }

        if ($outcome->secondFactorsRevoked !== []) {
            $message[] = sprintf(
                'Their %s %s removed; they will need to set that up again from Settings > Security.',
                implode(' and ', $outcome->secondFactorsRevoked),
                count($outcome->secondFactorsRevoked) === 1 ? 'was' : 'were',
            );
        }

        if ($outcome->accountIsDeactivated) {
            $message[] = 'This account is deactivated, so it still cannot sign in until somebody reactivates it.';
        }

        return back()->with('toast', [
            // Not a plain success when the account still cannot sign in. A
            // green tick on a problem that is not fixed is how a second support
            // call gets made.
            'type' => $outcome->accountIsDeactivated ? 'warning' : 'success',
            'message' => implode(' ', $message),
        ]);
    }
}
