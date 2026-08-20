<?php

namespace App\Actions\Users;

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Set another account's password on its owner's behalf.
 *
 * CLIENT QUESTION B3, answered 2026-08-20. Asked for SMTP credentials, CICTO
 * answered that it cannot supply them and recommended an external service; and
 * then, in the same breath, named the alternative it expects instead: "you may
 * develop a module that allows the system administrator to reset the password
 * of any user registered in your application." This is that module. It is the
 * supported way back into a locked-out account on a deployment with no mail,
 * which today is every deployment.
 *
 * Extracted into an action for the same reason CreateUserAccount was: the
 * console and the screen must share one path, because two implementations of
 * "give somebody a new password" is how one of them ends up skipping the guard
 * -- and the guard matters more here than anywhere else in the system. Setting
 * a password is a complete account takeover with a receipt; §21 says the
 * receipt is not optional.
 *
 * WHAT THIS DOES BEYOND WRITING A HASH, and why each part is not optional:
 *
 *  - the remember-me token is rotated, or a stolen "remember me" cookie keeps
 *    authenticating after the password it was issued against is gone. Fortify's
 *    own CompletePasswordReset does this; Settings > Security does not, which
 *    is a separate pre-existing gap and not one to copy;
 *  - any outstanding emailed reset token is deleted, so a link issued before
 *    the reset cannot be used to set the password back afterwards;
 *  - live sessions are destroyed. Laravel's logoutOtherDevices cannot do this:
 *    it acts on the CURRENTLY AUTHENTICATED user -- the administrator, not the
 *    account being reset -- and it needs AuthenticateSession in the middleware
 *    stack, which this application does not register. See endLiveSessions;
 *  - second factors are removed ONLY on request. A forgotten password and a
 *    compromised account want opposite answers here, so it is the caller's
 *    decision and it is recorded in the audit line either way.
 */
final class ResetAccountPassword
{
    /**
     * @throws AuthorizationException
     */
    public function handle(
        User $actor,
        User $target,
        string $password,
        bool $revokeSecondFactors = false,
    ): PasswordResetOutcome {
        /*
         * The check that cannot be bypassed by adding a new caller.
         *
         * ResetUserPasswordRequest::authorize() and the route's
         * EnsureRole::using(Role::SuperAdmin) both say the same thing, and both
         * are edits away from saying something else. This one is next to the
         * write.
         */
        if (! $actor->is_active || ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Only a Super Admin can set another account\'s password.');
        }

        /*
         * Not a formality. Settings > Security requires the old password before
         * it will accept a new one; this screen requires the administrator's
         * own password, which for their own account is the same secret. Routing
         * self-service through here would swap a deliberate check for an
         * accidental one, and would sign the actor out of the session they are
         * using to do it.
         */
        if ($actor->is($target)) {
            throw new AuthorizationException(
                'Change your own password under Settings > Security, where the current one is required.',
            );
        }

        return $this->apply($target, $password, $revokeSecondFactors, $actor);
    }

    /**
     * The same operation with no signed-in administrator behind it.
     *
     * For `cicto:user --reset-password`, which exists for the one case the
     * screen cannot serve: every Super Admin is locked out, so there is nobody
     * left to sign in and press the button. It skips the role guard because
     * there is no role to check -- the credential being presented is shell
     * access to the server, which is a stronger one than any account in the
     * system, and the same reasoning `cicto:user` already applies to granting
     * roles from the console.
     *
     * It takes the second-factor switch for a reason worth stating: this is the
     * LAST RESORT path, and a two-factor secret or a passkey is exactly what
     * keeps somebody out of an account whose password they have just been
     * given. A rescue that hands over a password and then stops at a code from
     * a phone nobody can find has not rescued anything. The command warns when
     * it is leaving one in place.
     */
    public function fromConsole(
        User $target,
        string $password,
        bool $revokeSecondFactors = false,
    ): PasswordResetOutcome {
        return $this->apply($target, $password, $revokeSecondFactors, actor: null);
    }

    private function apply(
        User $target,
        string $password,
        bool $revokeSecondFactors,
        ?User $actor,
    ): PasswordResetOutcome {
        return DB::transaction(function () use ($target, $password, $revokeSecondFactors, $actor) {
            // Read BEFORE the write, so the report names what was there rather
            // than what was asked for.
            $revoked = $revokeSecondFactors
                ? $this->secondFactorsOn($target)
                : [];

            $attributes = [
                // Hash::make rather than assigning plaintext through the
                // `hashed` cast. Both are correct here; being explicit means a
                // future refactor that drops the cast cannot quietly write a
                // plaintext password into the column.
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ];

            if ($revokeSecondFactors) {
                $attributes += [
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ];
            }

            $target->forceFill($attributes)->save();

            if ($revokeSecondFactors) {
                /*
                 * Passkeys are a complete password bypass -- laravel/passkeys
                 * signs a holder in without ever consulting users.password --
                 * so a reset that leaves them in place has not revoked
                 * anything. They go with the second factors or not at all.
                 */
                $target->passkeys()->delete();
            }

            // The forgot-password broker's outstanding token for this address,
            // if one was ever issued. Fortify deletes it on its own flow and
            // nothing deletes it on any other.
            Password::broker((string) config('fortify.passwords'))->deleteToken($target);

            $sessionsEnded = $this->endLiveSessions($target);

            SecurityEvent::log(
                type: SecurityEventType::PasswordResetByAdmin,
                summary: sprintf(
                    '%s set a new password for %s%s. %s',
                    $actor === null ? 'The console' : $actor->name,
                    $target->email,
                    $revoked === [] ? '' : ', and removed its '.implode(' and ', $revoked),
                    $sessionsEnded === null
                        ? 'Sessions on other devices could not be ended on this session driver.'
                        : sprintf('%d live session(s) ended.', $sessionsEnded),
                ),
                actor: $actor,
                subjectLabel: $target->email,
            );

            return new PasswordResetOutcome(
                sessionsEnded: $sessionsEnded,
                secondFactorsRevoked: $revoked,
                accountIsDeactivated: ! $target->is_active,
            );
        });
    }

    /**
     * The second factors this account actually carries, phrased for a sentence.
     *
     * @return list<string>
     */
    private function secondFactorsOn(User $target): array
    {
        $factors = [];

        if ($target->two_factor_confirmed_at !== null) {
            $factors[] = 'two-factor';
        }

        $passkeys = $target->passkeys()->count();

        if ($passkeys > 0) {
            $factors[] = $passkeys.' passkey'.($passkeys === 1 ? '' : 's');
        }

        return $factors;
    }

    /**
     * Destroy every live session belonging to this account.
     *
     * A direct delete against the sessions table, because nothing else works
     * here. Auth::logoutOtherDevices operates on the authenticated user and
     * relies on Illuminate\Session\Middleware\AuthenticateSession comparing a
     * password hash stored in the session -- and that middleware is not in this
     * application's web stack, so the comparison never happens. Without this,
     * somebody whose password was reset because their account was compromised
     * stays signed in on the attacker's browser until the session lifetime runs
     * out.
     *
     * Returns NULL rather than 0 when the session store is not a table this
     * application can reach (SESSION_DRIVER=file, or cookie, or redis). Those
     * are different facts and the screen says which one happened, because
     * "nobody was signed in" and "we could not sign anybody out" call for
     * different actions from the administrator.
     */
    private function endLiveSessions(User $target): ?int
    {
        if ((string) config('session.driver') !== 'database') {
            return null;
        }

        $connection = config('session.connection');

        return DB::connection(is_string($connection) ? $connection : null)
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $target->id)
            ->delete();
    }
}
