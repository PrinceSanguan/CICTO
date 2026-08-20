<?php

namespace App\Console\Commands;

use App\Actions\Users\ResetAccountPassword;
use App\Enums\Role;
use App\Models\Office;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Create a user, or change an existing one's role, office, password or active
 * state.
 *
 * Feature #11 (Role-Based Access Control) ships the ENFORCEMENT half -- roles,
 * policies, EnsureRole, office scoping. This is the administration half for the
 * operations that have no screen: role, office and active state are still
 * console-only, and putting them behind SSH rather than a session is the
 * cheapest defence available for the three operations an attacker would want.
 * Accounts and passwords now also have a screen -- §4's Manage Users -- so for
 * those this is the fallback rather than the only door.
 *
 * --reset-password is the fallback that matters, and it exists for exactly one
 * situation: every Super Admin is locked out, so there is nobody left who can
 * sign in and press the button on the screen. With no mail service (client
 * question B3) there is no reset link either, which would otherwise leave a
 * live municipal register with no way back in at all.
 */
class ManageUserCommand extends Command
{
    protected $signature = 'cicto:user
                            {email : The account to create or change}
                            {--name= : Full name, required when creating}
                            {--role= : user|admin|super_admin}
                            {--office= : Office CODE, or "none" to unassign}
                            {--activate : Re-enable a closed account}
                            {--deactivate : Close the account without deleting it}
                            {--reset-password : Generate a new password, print it once, and sign the account out everywhere}
                            {--revoke-second-factors : With --reset-password, also remove two-factor and passkeys}';

    protected $description = 'Create a user, or change their role, office, password or active state';

    public function handle(ResetAccountPassword $reset): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::firstWhere('email', $email);

        if ($user === null) {
            /*
             * A reset of an account that does not exist is a typo, not a
             * request to make one.
             *
             * This command creates on miss, which is right for every other
             * option it takes and wrong for this one. Somebody rescuing a
             * locked-out system types the address from memory under pressure;
             * `supe@baliwag.gov.ph` would otherwise mint a SECOND account,
             * print a password for it, and report success -- leaving the
             * operator holding working credentials for an account that is not
             * the one they meant, and the real one still locked.
             */
            if ($this->option('reset-password')) {
                $this->error("No account with the address {$email}. Nothing was changed.");
                $this->line('Check the spelling -- this command will not create an account for a password reset.');

                return self::FAILURE;
            }

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

        if ($this->option('reset-password')) {
            $this->resetPassword($reset, $user);
        }

        $user->refresh();

        $this->info(sprintf(
            '%s — role %s, office %s, %s',
            $user->email,
            $user->role->value,
            // Branched on the FK: office_id is unambiguously int|null, while
            // static analysis resolves the relation itself as non-null.
            // "active" below describes the ACCOUNT. Say so about the office
            // separately, or an operator auditing accounts after a re-seed
            // reads "office MPDO, active" and cannot tell MPDO is dead.
            $user->office_id === null
                ? 'none'
                : $user->office->code.($user->office->is_active ? '' : ' (INACTIVE)'),
            $user->is_active ? 'active' : 'CLOSED',
        ));

        return self::SUCCESS;
    }

    private function create(string $email): ?User
    {
        $interactive = $this->input->isInteractive();

        $name = (string) ($this->option('name') ?: ($interactive ? $this->ask('Full name') : ''));

        if (! $interactive && $name === '') {
            $this->error('Without a terminal, --name is required when creating an account.');

            return null;
        }

        /*
         * Same reasoning as cicto:create-super-admin. A managed hosting panel
         * runs artisan through a web form with no TTY, where a hidden prompt
         * reads back empty and the command fails opaquely. With no terminal,
         * generate the password and print it once.
         */
        $generated = ! $interactive;

        if ($generated) {
            $password = Str::password(24);
        } else {
            $password = (string) $this->secret('Password');

            if ($password !== (string) $this->secret('Confirm password')) {
                $this->error('The passwords do not match.');

                return null;
            }
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

        if ($generated) {
            $this->newLine();
            $this->line('  Password: '.$password);
            $this->newLine();
            $this->warn('  Give this to them privately and ask them to change it under');
            $this->warn('  Settings > Security on first sign-in. Then delete this command');
            $this->warn('  from your panel history -- the password is in the output above.');
            $this->newLine();
        }

        return $user;
    }

    /**
     * Generate a password, print it once, and hand the rest to the action.
     *
     * The generation is here and the WRITE is not: App\Actions\Users\
     * ResetAccountPassword owns rotating the remember-me token, deleting any
     * outstanding reset token, ending live sessions and writing the §21 audit
     * line, and it does the same things whether the request came from the
     * screen or from here. Two implementations of "give somebody a new
     * password" is how one of them ends up skipping a step, and every step it
     * takes is one somebody would only notice the absence of during an
     * incident.
     *
     * Generated rather than asked for, and never accepted as an option: a
     * --password flag would be sitting in the hosting panel's command history
     * long after the deployment it was typed on. AccountCommandsTest asserts
     * that neither of these commands has one.
     */
    private function resetPassword(ResetAccountPassword $reset, User $user): void
    {
        $password = Str::password(24);
        $revoke = (bool) $this->option('revoke-second-factors');

        /*
         * Said BEFORE the password is printed, because it is the difference
         * between a rescue and a wasted one. This command is the last resort --
         * it is run when nobody can sign in at all -- and a two-factor secret
         * or a passkey will stop the account at the next screen whatever
         * password is handed over. Warned rather than assumed: clearing
         * somebody's authenticator without being asked is its own kind of
         * damage.
         */
        $remaining = [];

        if (! $revoke && $user->two_factor_confirmed_at !== null) {
            $remaining[] = 'two-factor';
        }

        if (! $revoke && $user->passkeys()->count() > 0) {
            $remaining[] = 'passkeys';
        }

        $outcome = $reset->fromConsole($user, $password, $revoke);

        $this->newLine();
        $this->line('  New password for '.$user->email.': '.$password);
        $this->newLine();
        $this->warn('  Give this to them privately and ask them to change it under');
        $this->warn('  Settings > Security on first sign-in. Then delete this command');
        $this->warn('  from your panel history -- the password is in the output above.');

        if ($outcome->secondFactorsRevoked !== []) {
            $this->line(sprintf(
                '  Removed: %s. They set that up again from Settings > Security.',
                implode(' and ', $outcome->secondFactorsRevoked),
            ));
        }

        if ($remaining !== []) {
            $this->newLine();
            $this->warn('  This account still has '.implode(' and ', $remaining).'.');
            $this->warn('  The password above will NOT get them in on its own. If that is what');
            $this->warn('  is blocking them, re-run with --revoke-second-factors.');
        }

        if ($outcome->sessionsEnded === null) {
            $this->warn('  Sessions on other devices could NOT be ended: SESSION_DRIVER is not database.');
        } elseif ($outcome->sessionsEnded > 0) {
            $this->line(sprintf('  %d device(s) already signed in were signed out.', $outcome->sessionsEnded));
        }

        if ($outcome->accountIsDeactivated) {
            $this->warn('  This account is CLOSED and still cannot sign in. Re-run with --activate.');
        }

        $this->newLine();
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
                // Active only. The retired placeholder codes still exist so old
                // control numbers resolve, but offering them here is how a
                // stranded account gets "fixed" into another dead office.
                Office::query()->active()->orderBy('code')->pluck('code')->implode(', '),
            ));

            return false;
        }

        $user->forceFill(['office_id' => $office->id])->save();

        /*
         * Assigning into a deactivated office is allowed -- it is occasionally
         * what you want while sorting out a migration -- but it must not be
         * silent. A user in an inactive office cannot file anything: their
         * office is not offered on the Submit form and the request is refused.
         * This command is what the deployment runbook points at for fixing
         * exactly that, so it is the last place that should do it quietly.
         */
        if (! $office->is_active) {
            $this->warn(sprintf(
                'Office %s is INACTIVE. %s cannot submit documents until they are moved to an active office.',
                $office->code,
                $user->email,
            ));
        }

        return true;
    }
}
