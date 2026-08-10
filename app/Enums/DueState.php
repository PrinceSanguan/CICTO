<?php

namespace App\Enums;

/**
 * Spec §11. Derived, never stored.
 *
 * The PHP twin of the overdue/approachingDeadline query scopes. Both read their
 * boundary from App\Support\Deadlines, so the badge on a row can never disagree
 * with the query that selected it.
 */
enum DueState: string
{
    /** Terminal document -- the clock has stopped. */
    case Closed = 'closed';

    /** No expected completion date was ever set. */
    case None = 'none';

    case OnTrack = 'on_track';
    case Approaching = 'approaching';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Closed => 'Closed',
            self::None => 'No deadline',
            self::OnTrack => 'On track',
            self::Approaching => 'Due soon',
            self::Overdue => 'Overdue',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Closed => 'slate',
            self::None => 'slate',
            self::OnTrack => 'emerald',
            self::Approaching => 'amber',
            self::Overdue => 'red',
        };
    }

    /** Whether this state should be surfaced as a badge at all. */
    public function isFlagged(): bool
    {
        return in_array($this, [self::Approaching, self::Overdue], true);
    }
}
