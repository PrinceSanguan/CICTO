<?php

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * §21's audit half, for events that have no document.
 *
 * Most of the security log comes free from framework events -- subscribing here
 * is far more reliable than remembering to log at each call site, which is how
 * audit trails end up with holes exactly where they matter.
 *
 * Role changes, user CRUD and settings edits are NOT here: they have no
 * framework event, so they call SecurityEvent::log() explicitly.
 *
 * The methods are named record*, NOT handle*, and that is load-bearing.
 * Laravel's event discovery scans app/Listeners for any public method matching
 * `handle*` with a typed first parameter and registers it automatically. This
 * class is ALSO registered explicitly through subscribe(), so handle* names
 * meant every auth event was recorded twice -- 50 rows in the §21 security log
 * for 25 actual events, each pair sharing a timestamp to the second. An audit
 * log that double-counts sign-ins is worse than one that misses them, because
 * it looks complete.
 */
class RecordSecurityEvents
{
    public function recordLogin(Login $event): void
    {
        $user = $event->user;

        SecurityEvent::log(
            SecurityEventType::LoginSucceeded,
            sprintf('%s signed in.', $user->email ?? 'A user'),
            $user instanceof User ? $user : null,
        );
    }

    public function recordLogout(Logout $event): void
    {
        $user = $event->user;

        SecurityEvent::log(
            SecurityEventType::LoggedOut,
            sprintf('%s signed out.', $user->email ?? 'A user'),
            $user instanceof User ? $user : null,
        );
    }

    /**
     * Records the attempted identifier, never the attempted password.
     */
    public function recordFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? 'unknown';

        SecurityEvent::log(
            SecurityEventType::LoginFailed,
            sprintf('Failed sign-in for %s.', is_string($email) ? $email : 'unknown'),
            $event->user instanceof User ? $event->user : null,
        );
    }

    public function recordLockout(Lockout $event): void
    {
        SecurityEvent::log(
            SecurityEventType::Lockout,
            sprintf(
                'Too many sign-in attempts for %s. Locked out.',
                (string) ($event->request->input('email') ?? 'unknown'),
            ),
        );
    }

    public function recordPasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        SecurityEvent::log(
            SecurityEventType::PasswordReset,
            sprintf('%s reset their password.', $user->email ?? 'A user'),
            $user instanceof User ? $user : null,
        );
    }

    public function recordTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        SecurityEvent::log(
            SecurityEventType::TwoFactorEnabled,
            sprintf('%s enabled two-factor authentication.', $event->user->email ?? 'A user'),
            $event->user,
        );
    }

    public function recordTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        SecurityEvent::log(
            SecurityEventType::TwoFactorDisabled,
            sprintf('%s disabled two-factor authentication.', $event->user->email ?? 'A user'),
            $event->user,
        );
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'recordLogin',
            Logout::class => 'recordLogout',
            Failed::class => 'recordFailed',
            Lockout::class => 'recordLockout',
            PasswordReset::class => 'recordPasswordReset',
            TwoFactorAuthenticationConfirmed::class => 'recordTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'recordTwoFactorDisabled',
        ];
    }
}
