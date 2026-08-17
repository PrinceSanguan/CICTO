<?php

namespace App\Enums;

/**
 * The state of one office in a document's routing plan.
 *
 * Resolved stops are kept, never deleted: after a rejection the document page
 * still has to be able to show the route the sender chose and where it stopped,
 * which is the whole point of showing a route at all.
 */
enum RouteStopStatus: string
{
    /** Still queued. The document has not reached this office yet. */
    case Pending = 'pending';

    /** The document was sent here, and this stop is what sent it. */
    case Visited = 'visited';

    /**
     * Dropped without being visited -- the document was rejected, completed,
     * returned for correction, or manually sent somewhere off the route.
     */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Visited => 'Visited',
            self::Cancelled => 'Cancelled',
        };
    }

    /** One of resources/js/types/documents.ts `Tone`, for ToneBadge. */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Visited => 'emerald',
            self::Cancelled => 'slate',
        };
    }
}
