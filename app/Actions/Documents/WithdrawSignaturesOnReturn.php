<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentSignature;
use App\Models\User;

/**
 * A RETURN takes the signatures off the document.
 *
 * The client's rule of 2026-09-20: "pag na-return, babalik yung version ng
 * office before" -- when a document goes back for correction, the marks made
 * on it come off with their stamped versions, so what comes back is the clean
 * copy. Every office signs again once the correction has been made.
 *
 * WHY THIS IS RIGHT AND NOT MERELY REQUESTED. A signature attests to one
 * exact file. A return says that file was wrong. Leaving the marks in place
 * would leave offices attesting to a document everybody agrees is being
 * replaced -- and on a STAMPED document it would leave their signatures
 * printed on the page of the version the corrected one is built from.
 *
 * WHICH MARKS. The live ones: signatures bound to a version at or after the
 * last one somebody UPLOADED. Anything older was already superseded by a
 * previous correction and is history -- it describes a file that was replaced
 * long before this return, and rewriting history is not what a return does.
 *
 * NEWEST FIRST, and that ordering is load-bearing. UndoSignature refuses to
 * delete a stamped version that has a newer version above it, so withdrawing
 * two marks in the wrong order would silently leave the first one's version
 * behind -- a page still showing a signature the register no longer has.
 *
 * Applies to RETURN only. Reject is terminal: the document is closed, nobody
 * will sign again, and the marks are the record of what happened before it
 * was refused. The client was asked and said to use return.
 */
final class WithdrawSignaturesOnReturn
{
    public function __construct(private readonly UndoSignature $undo) {}

    /** @return int How many signatures were withdrawn. */
    public function handle(Document $document, User $actor): int
    {
        $baseline = DocumentFile::lastUploadedVersion($document->id);

        $live = DocumentSignature::query()
            ->where('document_id', $document->id)
            ->with(['file', 'stampedFile'])
            ->get()
            ->filter(fn (DocumentSignature $signature): bool => $signature->file === null
                || $signature->file->version >= $baseline)
            /*
             * By the version each mark PRODUCED, descending -- and by signing
             * time for the ones that produced none, so the order is total. A
             * partial ordering here would be a different sequence on different
             * database engines, which is how a test passes on sqlite and the
             * bug ships on PostgreSQL.
             */
            ->sortByDesc(fn (DocumentSignature $signature): string => sprintf(
                '%010d-%s',
                $signature->stamped_file_id === null ? 0 : $signature->stampedFile->version,
                $signature->signed_at->toIso8601String(),
            ));

        foreach ($live as $signature) {
            $this->undo->handle($signature, $actor, 'the document was returned for correction');
        }

        return $live->count();
    }
}
