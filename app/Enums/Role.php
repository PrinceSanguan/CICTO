<?php

namespace App\Enums;

/**
 * Spec §2. Three fixed roles, stored as users.role string(32).
 *
 * Verbs are governed by level(). Rows are governed by office_id -- an Admin at
 * level 2 in Office A still cannot read Office B. Keeping those two axes
 * separate is why this does not need spatie/laravel-permission.
 */
enum Role: string
{
    case User = 'user';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Admin => 'Admin',
            self::SuperAdmin => 'Super Admin',
        };
    }

    public function level(): int
    {
        return match ($this) {
            self::User => 1,
            self::Admin => 2,
            self::SuperAdmin => 3,
        };
    }

    public function atLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Where this role lands after login. Bound through RoleAwareLoginResponse so
     * every entry point -- password, two-factor, passkey, registration -- agrees.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::User => 'dashboard',
            self::Admin => 'admin.dashboard',
            self::SuperAdmin => 'super-admin.dashboard',
        };
    }

    /**
     * The URL prefix this role's exclusive screens live under. Document routes
     * stay top-level for every role so a QR code never has to encode the
     * scanner's role at print time.
     */
    public function panelPrefix(): ?string
    {
        return match ($this) {
            self::User => null,
            self::Admin => 'admin',
            self::SuperAdmin => 'super-admin',
        };
    }

    /**
     * Coarse UI hints shared to Inertia. A free array -- no Gate call, no query.
     *
     * These drive rendering only. Every key has a named server-side twin in a
     * Policy; hiding a button is not authorization.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return [
            'viewAnyOfficeDocuments' => $this->atLeast(self::Admin),
            'viewAllDocuments' => $this === self::SuperAdmin,
            'approveDocuments' => $this->atLeast(self::Admin),
            'manageOfficeUsers' => $this->atLeast(self::Admin),
            'manageAllUsers' => $this === self::SuperAdmin,
            'manageSystemSettings' => $this === self::SuperAdmin,
            'archiveDocuments' => $this->atLeast(self::Admin),
            'viewReports' => $this->atLeast(self::Admin),
        ];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
