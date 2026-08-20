<?php

namespace App\Actions\Users;

/**
 * What actually happened when an administrator set someone's password.
 *
 * Returned rather than inferred, because two of these three facts cannot be
 * worked out by the caller and both change what the screen is allowed to
 * claim. "No sessions were ended" and "sessions cannot be ended on this
 * deployment" look identical from the outside and are not the same thing.
 */
final readonly class PasswordResetOutcome
{
    public function __construct(
        /**
         * How many live sessions were destroyed, or NULL when this deployment
         * has no session store the application can reach -- see
         * ResetAccountPassword::endLiveSessions.
         */
        public ?int $sessionsEnded,

        /**
         * What was actually removed, named rather than assumed.
         *
         * "Two-factor and passkeys were removed" is the wrong sentence for an
         * account that only had a passkey: it contradicts the checkbox the
         * administrator just read, and promises them a two-factor re-enrolment
         * that was never in play. An empty list means nothing was removed --
         * either because it was not asked for, or because there was nothing
         * there.
         *
         * @var list<string>
         */
        public array $secondFactorsRevoked,

        /**
         * Whether the account is closed. A password on a deactivated account
         * still will not sign in -- EnsureAccountIsActive refuses it whatever
         * the credential -- so saying so is the difference between a fixed
         * problem and a second support call.
         */
        public bool $accountIsDeactivated,
    ) {}
}
