<?php

namespace App\Enums;

/**
 * One step of a document type's suggested route -- a row of
 * document_type_route_steps, and a step of the template the Submit form is
 * sent (resources/js/types/documents.ts, RouteTemplateStep).
 *
 * Only `Office` steps can be added on the Document Types page. The other
 * three come from the client's routing PDF with the built-in types: a Super
 * Admin may keep, move or remove one, and change what it says, but not make
 * a new one.
 */
enum RouteStepKind: string
{
    /** A fixed office. */
    case Office = 'office';

    /** An office the person filing picks, from a short list or any. */
    case Choose = 'choose';

    /** The office an earlier Choose step was given. */
    case Same = 'same';

    /** Something the system does not do, said where it happens. Not a stop. */
    case Note = 'note';

    /** Does it become a stop on a registered document? */
    public function isStop(): bool
    {
        return $this !== self::Note;
    }
}
