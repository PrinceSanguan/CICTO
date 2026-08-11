<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Office;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Create a user, or change an existing one's role, office or active state.
 *
 * Feature #11 (Role-Based Access Control) ships the ENFORCEMENT half -- roles,
 * policies, EnsureRole, office scoping. The ADMINISTRATION half has no screen:
 * Manage Users is still a routed placeholder. Without something like this,
 * following the deployment runbook produces a system with exactly one usable
 * account and no way to add a clerk, so the LGU cannot start using it.
 *
 * This is the smallest honest unblock, not a replacement for that screen. It is
 * a console command, so only somebody with server access can grant a role --
 * which is the correct default anyway, but it means day-to-day staff changes
 * need a developer until the UI exists.
 */
class ManageUserCommand extends Command
{
    protected $signature = 'cicto:user
                            {email : The account to create or change}
                            {--name= : Full name, required when creating}
                            {--role= : user|admin|super_admin}
                            {--office= : Office CODE, or "none" to unassign}
                            {--activate : Re-enable a closed account}
                            {--deactivate : Close the account without deleting it}';

    protected $description = 'Create a user, or change their role, office or active state';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::firstWhere('email', $email);

        if ($user === null) {
            $user = $this->create($email);

            if ($user === null) {
                return self::FAILURE;
            }
        }

        if (! $this->applyRole($user)) {
            return self::FAILURE;
        }

        if (! $this->applyOffice($user)) {
            return self::FAILURE;
        }

        if ($this->option('activate')) {
            $user->forceFill(['is_active' => true])->save();
        }

        if ($this->option('deactivate')) {
            // Deactivate, never delete: document_movements.actor_id is
            // nullOnDelete, so deleting a user rewrites the audit trail.
            $user->forceFill(['is_active' => false])->save();
        }

        $user->refresh();

        $this->info(sprintf(
            '%s — role %s, office %s, %s',
            $user->email,
            $user->role->value,
            // Branched on the FK: office_id is unambiguously int|null, while
            // static analysis resolves the relation itself as non-null.
            $user->office_id === null ? 'none' : $user->office->code,
            $user->is_active ? 'active' : 'CLOSED',
        ));

        return self::SUCCESS;
    }

    private function create(string $email): ?User
    {
        $name = (string) ($this->option('name') ?: $this->ask('Full name'));
        $password = (string) $this->secret('Password');

        if ($password !== (string) $this->secret('Confirm password')) {
            $this->error('The passwords do not match.');

            return null;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                // No uncompromised() check: it calls an external API, and a
                // deployment on a municipal network may have no outbound
                // access at the moment accounts are being created.
                'password' => ['required', Password::min(12)->letters()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // Verified on creation: staff accounts are made by an administrator who
        // already knows the address, and there may be no mail service yet.
        $user->forceFill([
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        $this->info("Created {$user->email}.");

        return $user;
    }

    private function applyRole(User $user): bool
    {
        $role = $this->option('role');

        if ($role === null) {
            return true;
        }

        $resolved = Role::tryFrom((string) $role);

        if ($resolved === null) {
            $this->error(sprintf(
                'Unknown role "%s". Use one of: %s',
                $role,
                implode(', ', array_column(Role::cases(), 'value')),
            ));

            return false;
        }

        // role is deliberately not fillable -- no request may ever set it.
        $user->forceFill(['role' => $resolved->value])->save();

        return true;
    }

    private function applyOffice(User $user): bool
    {
        $code = $this->option('office');

        if ($code === null) {
            return true;
        }

        if (mb_strtolower((string) $code) === 'none') {
            $user->forceFill(['office_id' => null])->save();

            return true;
        }

        $office = Office::firstWhere('code', $code);

        if ($office === null) {
            $this->error(sprintf(
                'No office with code "%s". Known codes: %s',
                $code,
                Office::query()->orderBy('code')->pluck('code')->implode(', '),
            ));

            return false;
        }

        $user->forceFill(['office_id' => $office->id])->save();

        return true;
    }
}
