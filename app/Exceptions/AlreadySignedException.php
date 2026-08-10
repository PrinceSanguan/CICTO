<?php

namespace App\Exceptions;

use DomainException;

/**
 * This person has already signed this exact file version for this purpose.
 *
 * A unique index enforces it, but reaching the database for the answer means a
 * raw QueryException reaches the user as a 500 -- and, if the mark was drawn,
 * an orphaned PNG is left on disk. This is checked first so the refusal is a
 * sentence rather than a stack trace.
 */
class AlreadySignedException extends DomainException
{
    public function __construct(string $message = 'You have already signed this version of the document.')
    {
        parent::__construct($message);
    }
}
