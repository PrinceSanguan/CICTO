<?php

namespace App\Enums;

/**
 * Spec §12's four triggers. Two are events off the ledger (assigned,
 * forwarded); two are states that need a sweep (pending, overdue).
 *
 * Stored as notifications.type string(32) -- this is a hand-written table, not
 * Laravel's database-channel table. See docs/implementation/phase-2 for why.
 */
enum NotificationType: string
{
    case Assigned = 'document_assigned';
    case Forwarded = 'document_forwarded';
    case Returned = 'document_returned';
    case Pending = 'document_pending';
    case Overdue = 'document_overdue';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'New document assigned',
            self::Forwarded => 'Document forwarded to your office',
            self::Returned => 'Document returned',
            self::Pending => 'Document due soon',
            self::Overdue => 'Document overdue',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Assigned => 'inbox',
            self::Forwarded => 'send',
            self::Returned => 'undo-2',
            self::Pending => 'clock',
            self::Overdue => 'triangle-alert',
        };
    }

    /**
     * Emitted by the deadline sweep rather than by a ledger transition. These
     * are the two that stop firing when the host has no cron -- the states they
     * describe stay queryable either way.
     */
    public function isSwept(): bool
    {
        return in_array($this, [self::Pending, self::Overdue], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
