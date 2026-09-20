<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Support\QrToken;
use Illuminate\Console\Command;

/**
 * Does this document exist, and what is its scan URL?
 *
 * WHY THIS EXISTS. A label was scanned on 2026-09-20 and the page answered
 * "Document not found" for OCM-2026-00014, and there was no way to tell from
 * the outside which of three very different things had happened:
 *
 *   1. the document genuinely is not in this database (wrong environment --
 *      the register on cicto.site is not the one on a laptop);
 *   2. it exists, but the thing typed was its CONTROL NUMBER rather than its
 *      QR token, and the public scan path only ever resolves tokens;
 *   3. it exists and the token is wrong -- a reprinted or damaged label.
 *
 * Answering that from a Cloud shell used to mean writing a tinker one-liner
 * against a live municipal register, which is a lot of typing at the one
 * moment nobody wants to be improvising SQL.
 *
 * READ-ONLY, deliberately. It writes nothing and changes nothing, so it is
 * safe to hand to whoever is on the phone with the office that reported the
 * problem.
 *
 * It prints the QR TOKEN, which is the unguessable half of the scan URL. That
 * is the point -- recovering the URL for a damaged label is the job -- but it
 * means the output is as sensitive as the label itself and belongs in a
 * terminal, not a chat thread.
 */
class FindDocumentCommand extends Command
{
    protected $signature = 'cicto:find
                            {code : A control number (OCM-2026-00014) or a QR token}';

    protected $description = 'Look up a document by control number or QR token, and print its scan URL';

    public function handle(): int
    {
        $code = trim((string) $this->argument('code'));

        /*
         * A scanned label is a whole URL, and a wedge scanner types all of it.
         * Taking the last path segment means the operator can paste exactly
         * what the scanner produced without editing it first.
         */
        $segments = explode('/', rtrim($code, '/'));
        $tail = (string) end($segments);

        $document = Document::query()
            ->withTrashed()
            ->where('control_number', $code)
            ->orWhere('qr_token', $tail)
            ->first();

        if ($document === null) {
            $this->components->error("No document matches {$code} in this database.");

            $this->line('');
            $this->components->bulletList([
                'Check you are on the right environment -- a control number that exists on the live site will not exist on a laptop.',
                QrToken::isValid($tail)
                    ? 'That looks like a QR token, so the label may have been printed before a scan-domain change.'
                    : 'That is not a QR token, so it was read as a control number. Control numbers are exact: OCM-2026-00014, not ocm-2026-14.',
            ]);

            return self::FAILURE;
        }

        $document->load(['originatingOffice:id,name', 'openMovement.toOffice:id,name']);

        $this->components->info("Found {$document->control_number}");

        $this->components->twoColumnDetail('Title', $document->title);
        $this->components->twoColumnDetail('Status', $document->status->value);
        // originating_office_id is not nullable, so this relation is always
        // there; the open leg genuinely is not.
        $this->components->twoColumnDetail(
            'Originating office',
            $document->originatingOffice->name,
        );

        // openMovement is null once a document is closed or archived.
        $leg = $document->openMovement;

        $this->components->twoColumnDetail(
            'Currently at',
            $leg === null || $leg->to_office_id === null
                ? 'nobody (closed or archived)'
                : $leg->toOffice->name,
        );
        $this->components->twoColumnDetail('Registered', (string) $document->created_at);

        // The two states that make a document look missing while it is not.
        if ($document->trashed()) {
            $this->components->warn('This document is DELETED. It will not resolve from a scan.');
        }

        if ($document->archived_at !== null) {
            $this->components->warn('This document is ARCHIVED.');
        }

        /*
         * WHO CAN OPEN IT, because "the document exists but my screen says it
         * does not" is the question that actually brings people here -- and on
         * 2026-09-21 the answer turned out to be the view policy, not a
         * missing row. Printing the two offices that satisfy it saves the next
         * person from reading DocumentPolicy::view to find that out.
         */
        $this->line('');
        $this->components->twoColumnDetail(
            'Who can open it',
            sprintf(
                'Super Admins, %s, anyone at an office it has passed through, and whoever filed it',
                $document->originatingOffice->name,
            ),
        );

        $this->line('');
        $this->components->twoColumnDetail('QR token', $document->qr_token);
        $this->components->twoColumnDetail(
            'Scan URL',
            rtrim((string) config('cicto.scan_base_url'), '/').'/s/'.$document->qr_token,
        );

        /*
         * The most likely cause of the report that brought somebody here, said
         * plainly rather than left for them to work out.
         */
        if (! QrToken::isValid($tail)) {
            $this->line('');
            $this->components->warn(
                'You looked this up by CONTROL NUMBER. The public scan page resolves QR tokens only, '
                .'so typing the control number into it answers "Document not found" even though the '
                .'document exists. Use the Scan URL above, or the staff scan console.',
            );
        }

        return self::SUCCESS;
    }
}
