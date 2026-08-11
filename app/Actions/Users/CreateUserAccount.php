<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Enums\SecurityEventType;
use App\Models\Office;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Create an account.
 *
 * Extracted so §4's Manage Users screen and the `cicto:user` console command
 * share one path. Two implementations of "make a user" is how one of them ends
 * up skipping the role guard, and the guard is the whole point: this is the
 * operation that hands somebody access to a municipal register.
 *
 * Only a Super Admin may call it. §4 gives the Add New Admin button to the
 * Super Admin panel and to nothing else, which matches the rule already
 * enforced by AssignUserRole: an Admin can move people around inside their own
 * office, but cannot mint new accounts.
 */
class CreateUserAccount
{
    public function handle(
        User $actor,
        string $name,
        string $email,
        string $password,
        Role $role,
        ?Office $office = null,
    ): User {
        if (! $actor->is_active || ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Only a Super Admin can create accounts.');
        }

        // A Super Admin has no office, so requiring one for that role would
        // make the only account that can create accounts impossible to create.
        if ($role !== Role::SuperAdmin && $office === null) {
            throw new AuthorizationException('An office is required for this role.');
        }

        return DB::transaction(function () use ($actor, $name, $email, $password, $role, $office) {
            $user = new User;

            /*
             * forceFill, deliberately.
             *
             * `role` and `office_id` are excluded from the model's fillable
             * whitelist by construction -- that exclusion is what stops a mass
             * assignment from granting privilege -- so the one place allowed to
             * set them does it explicitly, behind the guard above.
             */
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => $role->value,
                'office_id' => $office?->id,
                'is_active' => true,

                /*
                 * Marked verified at creation.
                 *
                 * The `verified` middleware gates the whole app, and this
                 * deployment has no outgoing mail (client question B3), so an
                 * unverified account would be one nobody could sign in to. The
                 * account was created by a Super Admin who typed the address,
                 * which is a stronger assurance than a click-through link.
                 */
                'email_verified_at' => now(),
            ])->save();

            SecurityEvent::log(
                type: SecurityEventType::UserCreated,
                summary: sprintf(
                    '%s created the account %s as %s',
                    $actor->name,
                    $email,
                    $role->label(),
                ),
                actor: $actor,
                subjectLabel: $email,
            );

            return $user;
        });
    }
}
