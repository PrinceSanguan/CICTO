<?php

namespace App\Actions\Users;

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\SecurityPin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;

/**
 * Every write to a user's Security PIN (client request, 2026-09-25).
 *
 * One class for the screen, the modal and the console, for the same reason
 * ResetAccountPassword is one class: two implementations of "change somebody's
 * PIN" is how one of them ends up skipping the hash or the audit line.
 *
 * Only the HASH is ever stored. The digits are never logged, never echoed back
 * and never put in a notification.
 */
final class ManageSecurityPin
{
    /**
     * The owner chooses a PIN -- their first one, or a replacement.
     *
     * Callers have already proved who is asking: a first PIN comes from a
     * signed-in session that has none, and a replacement requires the account
     * password (SecurityPinController::update). Validation of the digits
     * themselves is SecurityPin::rules().
     */
    public function set(User $user, string $pin): void
    {
        $first = ! $user->hasSecurityPin();

        $user->forceFill([
            'security_pin' => Hash::make($pin),
            'security_pin_set_at' => now(),
        ])->save();

        // Wrong guesses at the old PIN say nothing about the new one.
        SecurityPin::clearAttempts($user);

        SecurityEvent::log(
            type: $first ? SecurityEventType::SecurityPinCreated : SecurityEventType::SecurityPinChanged,
            summary: $first
                ? "{$user->email} created their Security PIN"
                : "{$user->email} changed their Security PIN",
            actor: $user,
            subjectLabel: $user->email,
        );
    }

    /** Whether $pin is this user's PIN. False, never an exception, when they have none. */
    public function check(User $user, string $pin): bool
    {
        return $user->hasSecurityPin() && Hash::check($pin, (string) $user->security_pin);
    }

    /**
     * A Super Admin clears somebody else's PIN. The next time that person opens
     * a document they are asked to create a new one.
     *
     * Clearing, not setting: an administrator who could SET a PIN would know
     * it, and the whole point is that only its owner does.
     *
     * @throws AuthorizationException
     */
    public function reset(User $actor, User $target): void
    {
        if (! $actor->is_active || ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Only a Super Admin can reset somebody\'s Security PIN.');
        }

        $this->clear($target, $actor, "{$actor->email} reset the Security PIN of {$target->email}");
    }

    /**
     * `php artisan cicto:user <email> --reset-pin`. Whoever has a shell on the
     * server is past every check this application could make, so there is no
     * actor to authorise -- the audit line says it came from the console.
     */
    public function resetFromConsole(User $target): void
    {
        $this->clear($target, null, "The Security PIN of {$target->email} was reset from the server console");
    }

    private function clear(User $target, ?User $actor, string $summary): void
    {
        $target->forceFill([
            'security_pin' => null,
            'security_pin_set_at' => null,
        ])->save();

        SecurityPin::clearAttempts($target);

        SecurityEvent::log(
            type: SecurityEventType::SecurityPinReset,
            summary: $summary,
            actor: $actor,
            subjectLabel: $target->email,
        );
    }
}
