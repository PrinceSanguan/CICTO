<?php

namespace App\Events;

use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted by TransitionDocument after every ledger write.
 *
 * Dispatched inside the transaction, so listeners MUST implement
 * ShouldHandleEventsAfterCommit. On PostgreSQL a listener that runs inside the
 * transaction can read rows that are about to be rolled back -- and worse, a
 * failed statement there poisons the whole transaction even if the exception is
 * caught.
 */
class DocumentTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly DocumentMovement $movement,
        public readonly MovementAction $action,
        public readonly User $actor,
    ) {}
}
