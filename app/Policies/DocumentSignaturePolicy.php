<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentSignature;
use App\Models\User;

class DocumentSignaturePolicy
{
    public function __construct(private readonly DocumentPolicy $documents) {}

    /**
     * May this person WITHDRAW a signature -- the client's "undo" of
     * 2026-09-20?
     *
     * They asked for it in the same breath as one-signature-per-office, and
     * the two go together: if an office gets one mark, it has to be able to
     * take a wrong one back. Their own condition was the scope of it --
     * "kapag in-undo habang nasa office pa, ma-rereremove yung pirma" -- undo
     * while the folder is still on your desk.
     *
     * A signature is an attestation, so withdrawing one is deliberately
     * narrow. Every condition below is a way the register could otherwise be
     * made to lie:
     *
     *  1. IT MUST BE YOUR OFFICE'S. An office signs with one voice, so anyone
     *     at that office may take its mark back -- but nobody else's.
     *  2. THE FOLDER MUST STILL BE HERE. Once it has moved on, the next office
     *     is reading a document your signature is part of. Pulling it out from
     *     under them changes what they are looking at without telling them.
     *  3. NOBODY MAY HAVE SIGNED AFTER YOU. A later signature was made against
     *     a file your stamp is on; removing yours would leave theirs attesting
     *     to a document that never existed.
     *  4. NOTHING MAY HAVE BEEN UPLOADED SINCE. A corrected file means your
     *     signature is already superseded and is now history, not a draft.
     *  5. THE DOCUMENT MUST STILL BE OPEN. Archived or finished, the register
     *     is closed.
     *
     * Deliberately NOT limited to the person who signed: the client's rule is
     * about offices, and an office whose only signer is on leave must still be
     * able to correct its own mark.
     */
    public function undo(User $user, DocumentSignature $signature): bool
    {
        $document = $signature->document;

        if (! $user->is_active || $document === null) {
            return false;
        }

        if ($document->isArchived() || $document->status->isTerminal()) {
            return false;
        }

        if (! $this->documents->view($user, $document)) {
            return false;
        }

        // (1) Yours to withdraw. A Super Admin belongs to no office and signs
        // as themselves, so they are matched as themselves -- which also means
        // they cannot quietly withdraw an office's mark.
        $mine = $signature->office_id !== null
            ? $signature->office_id === $user->office_id
            : $signature->user_id === $user->id;

        if (! $mine) {
            return false;
        }

        // (2) Still on this desk. holdsDocument() lets a Super Admin act
        // anywhere, which is why (1) is checked first and separately.
        if (! $this->documents->holdsDocument($user, $document)) {
            return false;
        }

        // (3) Nobody signed after you.
        if ($this->signedAfter($document, $signature)) {
            return false;
        }

        // (4) Nothing uploaded since.
        return ! $this->uploadedAfter($document, $signature);
    }

    /*
     * THE THREE READS BELOW ANSWER FROM LOADED RELATIONS WHERE THEY EXIST.
     *
     * This policy is asked once per signature row on documents.show, so a
     * query in here is a query per signature -- measured at two per row in QA
     * on 2026-09-20, on a page that had already loaded every signature and
     * every file it needed to answer without asking the database anything.
     *
     * The same shape DocumentPolicy::hasPendingStops() and
     * Document::loadedRouteStops() use: prefer what is in memory, fall back to
     * a query, and never require the caller to have loaded anything. A
     * one-off `can()` from a controller or a console command still works; it
     * just pays for itself.
     */

    /** Did anybody else sign this document at or after this signature? */
    private function signedAfter(Document $document, DocumentSignature $signature): bool
    {
        if ($document->relationLoaded('signatures')) {
            return $document->signatures->contains(
                fn (DocumentSignature $other): bool => $other->getKey() !== $signature->getKey()
                    && $other->signed_at >= $signature->signed_at,
            );
        }

        return DocumentSignature::query()
            ->where('document_id', $document->id)
            ->whereKeyNot($signature->getKey())
            ->where('signed_at', '>=', $signature->signed_at)
            ->exists();
    }

    /**
     * Has a version arrived since the one this signature was made against?
     *
     * The version this signature STAMPED is not one: it is the signature's own
     * output, and counting it would make every stamped mark look superseded by
     * itself the moment it was made. The version that was SIGNED is excluded
     * for the obvious reason.
     */
    private function uploadedAfter(Document $document, DocumentSignature $signature): bool
    {
        $signedVersion = $this->signedVersion($signature);

        $isNewer = fn (DocumentFile $file): bool => $file->getKey() !== $signature->document_file_id
            && $file->getKey() !== $signature->stamped_file_id
            && $file->version > $signedVersion;

        if ($document->relationLoaded('files')) {
            return $document->files->contains($isNewer);
        }

        return DocumentFile::query()
            ->where('document_id', $document->id)
            ->when(
                $signature->document_file_id !== null,
                fn ($query) => $query->where('id', '!=', $signature->document_file_id),
            )
            ->when(
                $signature->stamped_file_id !== null,
                fn ($query) => $query->where('id', '!=', $signature->stamped_file_id),
            )
            ->where('version', '>', $signedVersion)
            ->exists();
    }

    /** The version number this signature was made against, or 0 if none. */
    private function signedVersion(DocumentSignature $signature): int
    {
        if ($signature->document_file_id === null) {
            return 0;
        }

        // documents.show eager-loads signatures.file, so this is free there.
        if ($signature->relationLoaded('file')) {
            return (int) $signature->file?->version;
        }

        return (int) DocumentFile::query()
            ->whereKey($signature->document_file_id)
            ->value('version');
    }
}
