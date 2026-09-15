<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Enums\Role;
use App\Enums\RouteStopStatus;
use App\Models\Document;
use App\Models\DocumentRouteStop;
use App\Models\DocumentSignature;
use App\Models\User;
use App\Support\DocumentWorkflow;
use App\Support\SystemSettings;

/**
 * Row-level authorization for documents.
 *
 * There is deliberately no Gate::before super-admin bypass. Every method states
 * its own `isSuperAdmin()` escape, so every grant is greppable and a future
 * ability added here is not silently granted to super admins before anyone has
 * thought about it.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    /**
     * Mirrors DocumentBuilder::visibleTo for a single record. The two must agree
     * -- a document a user can find in a list must be one they can open.
     */
    public function view(User $user, Document $document): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($document->created_by_id === $user->id) {
            return true;
        }

        /*
         * office_id, not role -- mirroring DocumentBuilder::visibleTo, which
         * carries the full reasoning. Gating the ROW on Role::Admin here is what
         * stopped a clerk opening the folder on their own desk, and act() calls
         * this first, so it stopped them receiving it too.
         */
        if ($user->office_id === null) {
            return false;
        }

        $officeId = $user->office_id;

        if ($document->originating_office_id === $officeId) {
            return true;
        }

        return $document->movements()
            ->where(function ($query) use ($officeId): void {
                $query->where('to_office_id', $officeId)->orWhere('from_office_id', $officeId);
            })
            ->exists();
    }

    public function create(User $user): bool
    {
        // A user with no office has no originating office to register against.
        return $user->is_active && ! $user->isQuarantined();
    }

    /** Only the submitter, and only before anyone has acted on it. */
    public function update(User $user, Document $document): bool
    {
        if (! $user->is_active || $document->isArchived()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $document->created_by_id === $user->id
            && $document->status === DocumentStatus::Initiated;
    }

    /** Uploading a corrected version. */
    public function uploadVersion(User $user, Document $document): bool
    {
        if (! $user->is_active || $document->isArchived() || $document->status->isTerminal()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        // Same rule as act(): holding the folder is not permission to read it,
        // so it cannot be permission to append a version to it either.
        if (! $this->view($user, $document)) {
            return false;
        }

        // The office that sent it keeps the right to correct it after it has
        // left, not only the one person who pressed Submit: an office's clerk
        // and its Admin share one view of their documents (2026-09-13), so an
        // Admin must not lose the upload form the clerk still sees.
        return $document->created_by_id === $user->id
            || $user->actsForOffice($document->originating_office_id)
            || $this->holdsDocument($user, $document);
    }

    /**
     * May this user perform this workflow action right now?
     *
     * Three separate questions, and all three must pass: is the transition
     * legal from the current stage, does this user's office hold the document,
     * and does their role permit deciding.
     */
    public function act(User $user, Document $document, MovementAction $action): bool
    {
        if (! $user->is_active || $document->isArchived()) {
            return false;
        }

        // You cannot act on what you cannot read. Without this, a clerk whose
        // office happens to hold a colleague's document could forward it
        // onward -- moving a record they are not allowed to open.
        if (! $this->view($user, $document)) {
            return false;
        }

        if (! DocumentWorkflow::allows($document->status, $action)) {
            return false;
        }

        if (! $this->holdsDocument($user, $document)) {
            return false;
        }

        /*
         * A document still travelling a route is not finished, whoever is
         * holding it.
         *
         * The workflow map has to allow `completed` from under_review -- with
         * approval gone that is the only stage it could hang off, and a
         * document that can never complete can never be archived. But offering
         * the button at every stop would put "Completed" beside "Received" for
         * the whole trip, and the client asked for those offices to see
         * "received lang, wala nang iba". AdvanceRoute closes the document by
         * itself when the LAST office receives it, so nobody has to reach for
         * this on a routed document at all.
         *
         * Reject is deliberately NOT gated this way. Closing a document early
         * is a mistake the route would have prevented; refusing one is the
         * whole point of being able to refuse it, and an office that has to
         * pass a bad folder along to the last stop before anybody may say no
         * is exactly the workflow the reject button exists to avoid.
         */
        if ($action === MovementAction::Completed && $this->hasPendingStops($document)) {
            return false;
        }

        /*
         * Return sends the document to its originating office. When that office
         * is already holding it there is nowhere to send it -- they can upload a
         * corrected version where it sits -- and a return-to-self would park it
         * in `returned` with its resubmit pointing back at the same desk.
         */
        if ($action === MovementAction::Returned
            && $document->openMovement?->to_office_id === $document->originating_office_id) {
            return false;
        }

        /*
         * Returning a document is a DECISION, so it is gated exactly as
         * approving was: Admin-only, and subject to the §A6 separation-of-duties
         * switch below. That is safe in a way approval never was -- a route
         * advances on `received`, so an office that cannot return simply does
         * not return, receives the folder, and the queue keeps moving. Nothing
         * downstream waits on a decision that is never taken.
         *
         * Resubmitting is not a decision. It is the originating office sending
         * back the correction it was asked for, so any member of that office
         * may do it, clerk or Admin.
         */
        if ($action->isDecision() || $action === MovementAction::Completed) {
            if (! $user->atLeast(Role::Admin)) {
                return false;
            }

            // Client question A6: separation of duties. Blocking self-approval
            // is the safe default; in a two-person municipal office it may
            // block real work, so it is switchable rather than hard-coded --
            // by a Super Admin at runtime, not only at deploy time.
            $selfApproval = $document->created_by_id === $user->id;

            if ($selfApproval && ! SystemSettings::allowSelfApproval()) {
                return false;
            }
        }

        return true;
    }

    public function forward(User $user, Document $document): bool
    {
        return $this->act($user, $document, MovementAction::Forwarded);
    }

    /**
     * §15: "Authorized users can digitally sign documents as part of the
     * approval process."
     *
     * "Authorized" is read as: someone who could approve it. Signing is an
     * attestation of assent, so anyone who cannot decide on the document has
     * nothing to attest to.
     */
    public function sign(User $user, Document $document): bool
    {
        if (! $user->is_active || $document->isArchived() || $document->status->isTerminal()) {
            return false;
        }

        if (! $this->view($user, $document)) {
            return false;
        }

        if (! $user->atLeast(Role::Admin)) {
            return false;
        }

        // Same separation-of-duties switch as approval (client question A6),
        // read through the same helper so the two rules cannot drift apart.
        if ($document->created_by_id === $user->id
            && ! SystemSettings::allowSelfApproval()) {
            return false;
        }

        // There must be something to sign. A signature is defined as a binding
        // to one exact file version; with no file there is no hash, so the
        // certificate printed "fingerprint: --" directly above a green "the
        // signed file still matches the fingerprint recorded above". Attach any
        // PDF afterwards and that green verdict was permanent -- the signature
        // appeared to cover content that did not exist when it was made.
        $file = $document->relationLoaded('currentFile')
            ? $document->currentFile
            : $document->currentFile()->first();

        if ($file === null) {
            return false;
        }

        // Already signed this exact version for this purpose. The unique index
        // says so too, but reaching it means a 500 and an orphaned PNG; this
        // makes can.sign false so the pad never renders in the first place.
        $signed = DocumentSignature::query()
            ->where('document_file_id', $file->id)
            ->where('user_id', $user->id)
            ->where('purpose', DocumentSignature::PURPOSE_APPROVAL)
            ->exists();

        if ($signed) {
            return false;
        }

        return $this->holdsDocument($user, $document);
    }

    /**
     * §9 + §15. The handoff signature: the office holding the folder attesting
     * to the exact file version it is about to send to the next office.
     *
     * Deliberately NOT the same rule as sign(), because it is not the same act.
     * An approval signature is a DECISION, so it is gated on Role::Admin and on
     * the §A6 separation-of-duties switch. A release signature is a statement of
     * CUSTODY -- "this is the version that left our desk" -- so:
     *
     *  - No Role::Admin gate. Whoever actually releases the folder is the person
     *    whose name belongs on it. In practice view() still narrows this to the
     *    office's Admins plus the document's own submitter, because a plain user
     *    cannot read a colleague's document in the first place; widening THAT is
     *    a separate decision with a much larger blast radius.
     *
     *  - No self-approval gate. The submitter is the one role that can always
     *    see their own document, so applying §A6 here would mean the most common
     *    plain-user case could never sign the handoff at all -- it would refuse
     *    exactly the people the rule above just admitted.
     *
     * Nothing here blocks forwarding. Signing before a handoff is offered, not
     * required; DocumentWorkflowController forwards with or without it.
     */
    public function signRelease(User $user, Document $document): bool
    {
        if (! $user->is_active || $document->isArchived() || $document->status->isTerminal()) {
            return false;
        }

        if (! $this->view($user, $document)) {
            return false;
        }

        // You can only release what is on your desk.
        if (! $this->holdsDocument($user, $document)) {
            return false;
        }

        // Same reason as sign(): a signature is a binding to one exact file
        // version, so with no file there is no hash and nothing to bind to.
        $file = $document->relationLoaded('currentFile')
            ? $document->currentFile
            : $document->currentFile()->first();

        if ($file === null) {
            return false;
        }

        // Already released this exact version. The unique index says so too,
        // but reaching it means a 500 and an orphaned PNG.
        $signed = DocumentSignature::query()
            ->where('document_file_id', $file->id)
            ->where('user_id', $user->id)
            ->where('purpose', DocumentSignature::PURPOSE_RELEASE)
            ->exists();

        return ! $signed;
    }

    public function comment(User $user, Document $document): bool
    {
        return $user->is_active && ! $document->isArchived() && $this->view($user, $document);
    }

    /** §20: only completed or rejected documents can be filed away. */
    public function archive(User $user, Document $document): bool
    {
        return $user->is_active
            && $user->atLeast(Role::Admin)
            && ! $document->isArchived()
            && $document->status->isTerminal()
            && $this->view($user, $document);
    }

    public function restore(User $user, Document $document): bool
    {
        return $user->is_active
            && $user->atLeast(Role::Admin)
            && $document->isArchived()
            && $this->view($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->is_active && $user->isSuperAdmin();
    }

    /** Is there still an office queued after this one? */
    private function hasPendingStops(Document $document): bool
    {
        if ($document->relationLoaded('routeStops')) {
            return $document->routeStops
                ->contains(fn (DocumentRouteStop $stop) => $stop->status === RouteStopStatus::Pending);
        }

        return $document->routeStops()
            ->where('status', RouteStopStatus::Pending)
            ->exists();
    }

    /**
     * Whose desk is it on? A super admin acts anywhere; everyone else needs the
     * open leg to point at their office.
     */
    private function holdsDocument(User $user, Document $document): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $leg = $document->openMovement;

        return $leg !== null && $user->actsForOffice($leg->to_office_id);
    }
}
