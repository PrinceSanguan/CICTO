<?php

namespace App\Exceptions;

use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use DomainException;

/**
 * Thrown when an action is not permitted from the document's current stage.
 *
 * Raised inside the transition transaction, so nothing is half-written: the
 * movement insert rolls back with it.
 */
class IllegalTransitionException extends DomainException
{
    public function __construct(
        public readonly DocumentStatus $from,
        public readonly MovementAction $action,
    ) {
        parent::__construct(sprintf(
            'A document that is %s cannot be %s.',
            mb_strtolower($from->label()),
            $action->verb(),
        ));
    }
}
