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
 */
class RecordSecurityEvents
{
    public function handleLogin(Login $event): void
    {
        $user = $event->user;

        SecurityEvent::log(
            SecurityEventType::LoginSucceeded,
            sprintf('%s signed in.', $user->email ?? 'A user'),
            $user instanceof User ? $user : null,
        );
    }

    public function handleLogout(Logout $event): void
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
    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? 'unknown';

        SecurityEvent::log(
            SecurityEventType::LoginFailed,
            sprintf('Failed sign-in for %s.', is_string($email) ? $email : 'unknown'),
            $event->user instanceof User ? $event->user : null,
        );
    }

    public function handleLockout(Lockout $event): void
    {
        SecurityEvent::log(
            SecurityEventType::Lockout,
            sprintf(
                'Too many sign-in attempts for %s. Locked out.',
                (string) ($event->request->input('email') ?? 'unknown'),
            ),
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        SecurityEvent::log(
            SecurityEventType::PasswordReset,
            sprintf('%s reset their password.', $user->email ?? 'A user'),
            $user instanceof User ? $user : null,
        );
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        SecurityEvent::log(
            SecurityEventType::TwoFactorEnabled,
            sprintf('%s enabled two-factor authentication.', $event->user->email ?? 'A user'),
            $event->user,
        );
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
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
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            Lockout::class => 'handleLockout',
            PasswordReset::class => 'handlePasswordReset',
            TwoFactorAuthenticationConfirmed::class => 'handleTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'handleTwoFactorDisabled',
        ];
    }
}
