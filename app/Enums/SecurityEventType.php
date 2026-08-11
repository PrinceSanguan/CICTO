<?php

namespace App\Enums;

/**
 * A CLOSED vocabulary, deliberately.
 *
 * security_events is the one non-document log this design permits (see the D1
 * amendment). Keeping the type list finite and enumerated is what stops it
 * drifting into a general-purpose activity log with a json blob.
 */
enum SecurityEventType: string
{
    case LoginSucceeded = 'auth.login';
    case LoginFailed = 'auth.failed';
    case LoggedOut = 'auth.logout';
    case Lockout = 'auth.lockout';
    case PasswordReset = 'auth.password_reset';
    case TwoFactorEnabled = 'auth.two_factor_enabled';
    case TwoFactorDisabled = 'auth.two_factor_disabled';

    case UserCreated = 'user.created';
    case RoleChanged = 'user.role_changed';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';

    case SettingChanged = 'settings.changed';

    /** Reads must never touch document_movements -- they belong here. */
    case FileDownloaded = 'file.downloaded';

    case DocumentSigned = 'signature.created';
    case SignatureTampered = 'signature.tampered';

    case BackupCompleted = 'backup.completed';
    case BackupFailed = 'backup.failed';
    case BackupRestored = 'backup.restored';

    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Signed in',
            self::LoginFailed => 'Failed sign-in',
            self::LoggedOut => 'Signed out',
            self::Lockout => 'Locked out',
            self::PasswordReset => 'Password reset',
            self::TwoFactorEnabled => 'Two-factor enabled',
            self::TwoFactorDisabled => 'Two-factor disabled',
            self::UserCreated => 'Account created',
            self::RoleChanged => 'Role changed',
            self::UserDeactivated => 'Account deactivated',
            self::UserReactivated => 'Account reactivated',
            self::SettingChanged => 'Setting changed',
            self::FileDownloaded => 'File downloaded',
            self::DocumentSigned => 'Document signed',
            self::SignatureTampered => 'Signature mismatch detected',
            self::BackupCompleted => 'Backup completed',
            self::BackupFailed => 'Backup failed',
            self::BackupRestored => 'Backup restored',
        };
    }

    /** Events worth surfacing to a Super Admin without them going looking. */
    public function isAlarming(): bool
    {
        return in_array($this, [
            self::Lockout,
            self::SignatureTampered,
            self::BackupFailed,
        ], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
