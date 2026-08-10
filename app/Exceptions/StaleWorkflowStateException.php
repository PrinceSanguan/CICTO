<?php

namespace App\Exceptions;

use DomainException;

/**
 * The document moved between the page rendering and the form submitting.
 *
 * Almost always a double-click on a slow connection, or a tab left open while a
 * colleague acted. Either way the second request must not silently apply to a
 * state its author never saw.
 */
class StaleWorkflowStateException extends DomainException
{
    public function __construct(string $message = 'This document has already been acted on. Refresh to see its current state.')
    {
        parent::__construct($message);
    }
}
