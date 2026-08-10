<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\Office;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The only place users.role and users.office_id are ever written.
 *
 * Both columns are excluded from #[Fillable] on the model, so mass assignment
 * cannot reach them. This action is the single deliberate door, and it checks
 * who is knocking.
 */
final class AssignUserRole
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, User $target, Role $role, ?Office $office = null): User
    {
        if (! $actor->is_active || ! $actor->atLeast(Role::Admin)) {
            throw new AuthorizationException('You cannot change roles.');
        }

        if (! $actor->isSuperAdmin()) {
            $this->guardNonSuperAdmin($actor, $target, $role);

            // An Admin can only ever place someone in their own office.
            if ($office !== null && $office->id !== $actor->office_id) {
                throw new AuthorizationException('You can only assign users to your own office.');
            }

            $office ??= $actor->office;
        }

        $target->forceFill([
            'role' => $role->value,
            'office_id' => $office->id ?? $target->office_id,
        ])->save();

        return $target;
    }

    /**
     * @throws AuthorizationException
     */
    private function guardNonSuperAdmin(User $actor, User $target, Role $role): void
    {
        // Only a Super Admin may mint another Super Admin, or an Admin could
        // promote themselves out of their own office scope.
        if ($role === Role::SuperAdmin) {
            throw new AuthorizationException('Only a Super Admin can grant Super Admin.');
        }

        // ...and only a Super Admin may demote one.
        //
        // This is the check that was missing. Super Admins have no office, and
        // the office guard below only fired when the target HAD one -- so any
        // Admin could reach straight past it, strip a Super Admin's role and
        // pull them into their own office, locking the system owners out of
        // their own panel.
        if ($target->isSuperAdmin()) {
            throw new AuthorizationException('Only a Super Admin can change another Super Admin.');
        }

        // An Admin manages users within their own office only (§2). A user with
        // no office yet is fair game -- that is how a self-registered account
        // gets adopted out of quarantine.
        if ($actor->office_id === null) {
            throw new AuthorizationException('You are not assigned to an office.');
        }

        if ($target->office_id !== null && $target->office_id !== $actor->office_id) {
            throw new AuthorizationException('You can only manage users in your own office.');
        }
    }
}
