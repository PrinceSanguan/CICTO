<?php

namespace App\Enums;

/**
 * What happened on a document_movements leg.
 *
 * These are the only verbs the ledger records. Because document_movements is
 * both the routing record and the audit trail, this enum is effectively the
 * audit vocabulary too -- see docs/implementation/00-architecture.md §1.
 */
enum MovementAction: string
{
    case Registered = 'registered';
    case Forwarded = 'forwarded';
    case Received = 'received';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Completed = 'completed';
    case Archived = 'archived';
    case Restored = 'restored';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::Forwarded => 'Forwarded',
            self::Received => 'Received',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Returned => 'Returned',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
            self::Restored => 'Restored',
        };
    }

    /** Past-tense sentence fragment for the §13 timeline: "Maria Cruz approved this document". */
    public function verb(): string
    {
        return match ($this) {
            self::Registered => 'registered',
            self::Forwarded => 'forwarded',
            self::Received => 'received',
            self::Approved => 'approved',
            self::Rejected => 'rejected',
            self::Returned => 'returned',
            self::Completed => 'completed',
            self::Archived => 'archived',
            self::Restored => 'restored',
        };
    }

    /** Actions a reviewer performs from the document screen. */
    public function isDecision(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Returned], true);
    }

    /** Remarks are mandatory when sending a document back or refusing it. */
    public function requiresRemarks(): bool
    {
        return in_array($this, [self::Rejected, self::Returned], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
