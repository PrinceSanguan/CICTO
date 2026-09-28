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

    /*
     * Five wrong sign-in codes (2026-09-25). Somebody got past the password
     * and not the email -- the password should be treated as known.
     */
    case LoginOtpLockout = 'auth.otp_lockout';
    case PasswordReset = 'auth.password_reset';
    case TwoFactorEnabled = 'auth.two_factor_enabled';
    case TwoFactorDisabled = 'auth.two_factor_disabled';

    case UserCreated = 'user.created';

    /*
     * Distinct from PasswordReset above, and it has to be.
     *
     * RecordSecurityEvents::recordPasswordReset writes "<email> reset their
     * password" and names the account holder as the actor, which is true of
     * the forgot-password flow and false of this one. Filing an
     * administrator-set password under the same case would put the wrong
     * person's name against the one operation in the system that hands over
     * someone else's account.
     */
    case PasswordResetByAdmin = 'user.password_reset';

    case RoleChanged = 'user.role_changed';

    /*
     * An administrator moving an account to a new address from the console
     * (2026-09-25). Since the emailed sign-in code, the address IS a way in:
     * whoever controls it can finish a sign-in, so a change must be findable.
     */
    case EmailChangedByAdmin = 'user.email_changed';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';

    /*
     * The Security PIN (2026-09-25). Created and changed are the owner's own
     * acts; reset is an administrator clearing somebody else's, kept apart for
     * the same reason PasswordResetByAdmin is. The lockout -- five wrong PINs,
     * session signed out -- is the one worth a Super Admin's attention: it is
     * what somebody trying PINs at an unattended desk looks like.
     */
    case SecurityPinCreated = 'pin.created';
    case SecurityPinChanged = 'pin.changed';
    case SecurityPinReset = 'pin.reset';
    case SecurityPinLockout = 'pin.lockout';

    case SettingChanged = 'settings.changed';

    /** Reads must never touch document_movements -- they belong here. */
    case FileDownloaded = 'file.downloaded';

    /*
     * Distinct from FileDownloaded, for the same reason PasswordResetByAdmin is
     * distinct from PasswordReset: the two are different acts and the log has to
     * say which one happened. Someone who opened a document on screen did not
     * take a copy away, and an investigation into a leak cares about the
     * difference.
     */
    case FilePreviewed = 'file.previewed';

    case DocumentSigned = 'signature.created';

    /*
     * Its own type, not a bare delete. A signature that was withdrawn is the
     * one thing in this log a dispute is most likely to turn on -- "it was
     * signed and then it was not" -- and it must be findable without reading
     * every row. The summary names the office, so the trail survives the row
     * it describes being gone.
     */
    case SignatureUndone = 'signature.undone';

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
            self::LoginOtpLockout => 'Too many wrong sign-in codes',
            self::PasswordReset => 'Password reset',
            self::TwoFactorEnabled => 'Two-factor enabled',
            self::TwoFactorDisabled => 'Two-factor disabled',
            self::UserCreated => 'Account created',
            self::PasswordResetByAdmin => 'Password set by an administrator',
            self::RoleChanged => 'Role changed',
            self::EmailChangedByAdmin => 'Email address changed by an administrator',
            self::UserDeactivated => 'Account deactivated',
            self::UserReactivated => 'Account reactivated',
            self::SecurityPinCreated => 'Security PIN created',
            self::SecurityPinChanged => 'Security PIN changed',
            self::SecurityPinReset => 'Security PIN reset by an administrator',
            self::SecurityPinLockout => 'Signed out after wrong PINs',
            self::SettingChanged => 'Setting changed',
            self::FileDownloaded => 'File downloaded',
            self::FilePreviewed => 'File previewed',
            self::DocumentSigned => 'Document signed',
            self::SignatureUndone => 'Signature withdrawn',
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
            self::LoginOtpLockout,
            self::SecurityPinLockout,
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
