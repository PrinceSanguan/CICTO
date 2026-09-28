<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Enums\Role;
use App\Enums\RouteStopStatus;
use App\Models\Document;
use App\Models\DocumentRouteStop;
use App\Models\DocumentSignature;
use App\Models\Office;
use App\Models\User;
use App\Support\Confidential;
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
     * May this person READ the document?
     *
     * Everyone involved() with it, plus -- once it has been BROADCAST to every
     * office (client, 2026-09-25) -- anybody who belongs to an office. That
     * second group reads and nothing more: every ability that does something
     * asks involved(), not this.
     *
     * Mirrors DocumentBuilder::readableBy. The two must agree -- a document a
     * user can find in a list must be one they can open.
     */
    public function view(User $user, Document $document): bool
    {
        if ($this->involved($user, $document)) {
            return true;
        }

        return $user->is_active
            && $user->office_id !== null
            && $document->broadcast_at !== null
            && ! $document->is_confidential;
    }

    /**
     * Is this document part of this person's work -- filed by them, or at, from
     * or through their office? What view() was before broadcasting, and what
     * every ability that ACTS on a document still requires: an Executive Order
     * broadcast to all 52 offices is readable by all of them, and archivable,
     * commentable and signable by none of them that it did not pass through.
     *
     * Mirrors DocumentBuilder::visibleTo for a single record.
     */
    public function involved(User $user, Document $document): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if (! $this->confidentialityAllows($user, $document)) {
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

        if (! $this->confidentialityAllows($user, $document)) {
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

        if (! $this->confidentialityAllows($user, $document)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        // Same rule as act(): holding the folder is not permission to read it,
        // so it cannot be permission to append a version to it either.
        if (! $this->involved($user, $document)) {
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
        // onward -- moving a record they are not allowed to open. involved(),
        // not view(): reading a broadcast is not a part in its workflow.
        if (! $this->involved($user, $document)) {
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
         * AND NEITHER IS SEND TO ANOTHER OFFICE, WHILE THE ROUTE IS RUNNING --
         * the client's decision of 2026-09-20, widening the one below.
         *
         * A hand-picked send does not sit alongside a route; it DESTROYS one.
         * AdvanceRoute cancels every remaining stop on a Forwarded, on the
         * reasoning that "silently keeping a queue that no longer matches what
         * they typed is worse than dropping it" -- so one press at the second
         * of six offices throws the other four away, with nothing on the page
         * saying so. The offices in the middle of a route were the ones most
         * able to do that and least likely to mean it.
         *
         * With the plan running, the only two things an office holding the
         * folder needs are RECEIVED (pass it on, which the route does for
         * them) and RETURN (send it back for correction, which deliberately
         * leaves the queue intact). That is the panel the client asked for:
         * "only received or returned".
         *
         * THIS IS A REVERSAL, and worth saying so plainly. A first version hid
         * Send at every stop; on 2026-09-19 the client narrowed it to the last
         * office only ("applicable only on the last route office") and the
         * broad version was reverted. On 2026-09-20 they asked for the broad
         * one back. Both are recorded because the next report of "Send is
         * still showing" needs to be read against the CURRENT rule, not the
         * one before it.
         *
         * Unchanged, and all of it deliberate:
         *
         *  - an UNROUTED document has no stops, so it keeps Send -- it is the
         *    only way to move one at all;
         *
         *  - a document whose route was already abandoned by a hand-picked
         *    send has no pending stops left, so this rule does not reach it;
         *    the office it landed on is off the plan and keeps every button,
         *    exactly as it did before;
         *
         *  - RETURN, RESUBMIT and REJECT are untouched. Refusing a bad folder
         *    mid-route is the whole point of being able to refuse it.
         *
         * TWO ESCAPE HATCHES, and neither is optional -- without them this rule
         * builds documents that can never move again:
         *
         *  - RECEIVED MUST ACTUALLY BE AVAILABLE. "Only received or returned"
         *    assumes there is a Received to press. A legacy `approved`
         *    document cannot be received at all, so hiding Send there leaves
         *    it with no exit whatsoever: not received, not completed (stops
         *    are pending), not forwarded. Its only way out is a hand-picked
         *    send, and it keeps one.
         *
         *  - THE HOLDING OFFICE MUST BE ABLE TO RECEIVE. Same test and same
         *    reason as the rule below: an office that was deactivated, or has
         *    no active Admin left, can neither take the folder in nor pass it
         *    on, so Send comes back for a Super Admin to redirect it.
         */
        if ($action === MovementAction::Forwarded
            && $this->hasPendingStops($document)
            && DocumentWorkflow::allows($document->status, MovementAction::Received)
            && $this->holdingOfficeCanReceive($document)) {
            return false;
        }

        /*
         * AT THE LAST OFFICE ON A ROUTE, RECEIVED AND RETURN ARE THE ONLY
         * BUTTONS -- the client's decision of 2026-09-19. Looking at that
         * office's Actions panel ("Send to Another Office", "Received",
         * "Completed", "Return") they asked for Completed to go "kasi same
         * function lang sila ng receive", and Send to Another Office with it:
         * "ang matitira lang po na button is yung received and return". Only
         * there: "applicable only on the last route office".
         *
         * "The last route office" is Document::isAtLastRouteStop(): nothing on
         * the route is still waiting, AND the folder is at the stop the route
         * ended at. There, AdvanceRoute COMPLETES the document on receipt, so
         * Received is Completed and a hand-picked send would start a second
         * journey after the first had ended.
         *
         * Everywhere else nothing changes:
         *
         *  - the originating office and the middle of a route keep Send to
         *    Another Office, and with it the ability to re-route; Completed was
         *    already hidden there by the pending-stops rule above;
         *
         *  - an office the folder was sent to BY HAND, off the plan, is not on
         *    the route at all, so it keeps every button it had before;
         *
         *  - unrouted documents keep both, because Received completes nothing
         *    for them; and legacy `approved` documents keep both, because they
         *    cannot be received at all.
         *
         * ONE EXCEPTION, for recovery only: a last stop that nobody can
         * receive at -- the office was deactivated, or has no active Admin
         * (the same test as Office::withReceiver) -- may still be sent on.
         * Without it a Super Admin could only close a document that office
         * never took in, or return it to an originating office whose one way
         * back is to that same dead desk. An office that can receive never sees
         * it, so the client's panel is exactly the one they asked for.
         *
         * AdvanceRoute is untouched by this: it calls TransitionDocument
         * directly, and never asks the policy.
         */
        if (in_array($action, [MovementAction::Forwarded, MovementAction::Completed], true)
            && DocumentWorkflow::allows($document->status, MovementAction::Received)
            && $document->isAtLastRouteStop()
            && ! ($action === MovementAction::Forwarded && ! $this->holdingOfficeCanReceive($document))) {
            return false;
        }

        /*
         * Return sends the document back to an office it has already been at --
         * the returning office's choice since 2026-09-25, the originating office
         * before that (Document::returnDestinations). With nowhere else it has
         * been, there is nowhere to send it: a document still at the office
         * that filed it can simply be corrected where it sits, and a
         * return-to-self would park it in `returned` with its resubmit pointing
         * back at the same desk.
         */
        if ($action === MovementAction::Returned
            && $document->returnDestinations()->isEmpty()) {
            return false;
        }

        /*
         * Receiving and forwarding are an Admin's job, and that is the client's
         * decision of 2026-09-15: "nakakapag esign po yung user tsaka nakakapag
         * recieve ng documents, dapat po sa admin lang yon". It is also what the
         * role table in their testing guide has always said -- a User files,
         * searches and tracks; receiving and sending to another office are
         * listed under Admin. Clerks gained both as a side effect of row access
         * following office_id (see DocumentBuilder::visibleTo), not because
         * anybody asked for it.
         *
         * Forward goes with Received because either one moves the folder: a
         * clerk who could not receive but could still send it on would move a
         * document their office never took in.
         *
         * NOT subject to the §A6 switch below. Acknowledging a folder on your
         * desk is a receipt, not a decision, so an Admin still receives and
         * forwards a document they filed themselves.
         *
         * Resubmitted is deliberately not on this list: it is the originating
         * office sending back the correction it was asked for, open to clerk
         * and Admin alike (2026-09-15).
         *
         * What it costs: an office with no active Admin cannot take a folder
         * in. Office::withReceiver counts only Admins, so the route picker
         * already warns about exactly those offices before the send.
         */
        if (in_array($action, [MovementAction::Received, MovementAction::Forwarded], true)
            && ! $user->atLeast(Role::Admin)) {
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

        if (! $this->involved($user, $document)) {
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
     *  - Role::Admin, since 2026-09-15. This started out open to whoever held
     *    the folder, clerks included; the client asked for e-signing to be the
     *    Admin's alone ("dapat po sa admin lang yon"), together with receiving
     *    -- see act(). The office's Admin is the one releasing the folder, so
     *    theirs is the name that belongs on it.
     *
     *  - No self-approval gate. Releasing a folder your office holds is custody,
     *    not assent, so an Admin still signs the release of a document they
     *    filed themselves.
     *
     * Nothing here blocks forwarding. Signing before a handoff is offered, not
     * required; DocumentWorkflowController forwards with or without it.
     */
    public function signRelease(User $user, Document $document): bool
    {
        if (! $user->is_active || $document->isArchived() || $document->status->isTerminal()) {
            return false;
        }

        if (! $this->involved($user, $document)) {
            return false;
        }

        // Admin-only, the client's decision of 2026-09-15 -- see the docblock.
        if (! $user->atLeast(Role::Admin)) {
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

    /**
     * involved(), not view(): a broadcast is sent to be read. Fifty-two
     * offices' worth of replies under an Executive Order is not a thread
     * anybody can work from.
     */
    public function comment(User $user, Document $document): bool
    {
        return $user->is_active && ! $document->isArchived() && $this->involved($user, $document);
    }

    /** §20: only completed or rejected documents can be filed away. */
    public function archive(User $user, Document $document): bool
    {
        return $user->is_active
            && $user->atLeast(Role::Admin)
            && ! $document->isArchived()
            && $document->status->isTerminal()
            && $this->involved($user, $document);
    }

    public function restore(User $user, Document $document): bool
    {
        return $user->is_active
            && $user->atLeast(Role::Admin)
            && $document->isArchived()
            && $this->involved($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->is_active
            && $user->isSuperAdmin()
            && $this->confidentialityAllows($user, $document);
    }

    /**
     * "Broadcast to ALL offices" (client, DTS_Office_Routing_Paths.pdf,
     * 2026-09-25): an Executive Order or a Memorandum Circular told to every
     * office and readable by all of them, while the folder itself carries on.
     *
     * Once per document, by an Admin of the office holding it or of the office
     * that issued it -- after it has been completed and nobody holds it, the
     * issuing office still may -- or by a Super Admin. Only for the types the
     * seeder marks, never for a Confidential one, and not while it is out for
     * correction.
     */
    public function broadcast(User $user, Document $document): bool
    {
        if (! $user->is_active
            || $document->isArchived()
            || $document->is_confidential
            || $document->broadcast_at !== null
            || in_array($document->status, [DocumentStatus::Returned, DocumentStatus::Rejected], true)) {
            return false;
        }

        $type = $document->relationLoaded('documentType')
            ? $document->documentType
            : $document->documentType()->first(['id', 'allows_broadcast']);

        if ($type === null || ! $type->allows_broadcast) {
            return false;
        }

        if (! $this->involved($user, $document)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->atLeast(Role::Admin)
            && ($this->holdsDocument($user, $document) || $user->actsForOffice($document->originating_office_id));
    }

    /**
     * A Confidential document is for the person who filed it and for the City
     * Mayor's and HRMO's people -- nobody else, a Super Admin included. Asked
     * first by every ability, including the Super Admin escapes, so no grant
     * below reaches past it. See App\Support\Confidential.
     */
    private function confidentialityAllows(User $user, Document $document): bool
    {
        return ! $document->is_confidential
            || $document->created_by_id === $user->id
            || Confidential::trustsUser($user);
    }

    /**
     * Could anybody at the office holding this document take it in? Active,
     * with at least one active Admin -- Office::withReceiver's definition,
     * because only an Admin may press Received.
     */
    private function holdingOfficeCanReceive(Document $document): bool
    {
        $officeId = $document->openMovement?->to_office_id;

        return $officeId !== null && Office::query()
            ->whereKey($officeId)
            ->where('is_active', true)
            ->whereHas('users', fn ($users) => $users
                ->where('users.role', Role::Admin->value)
                ->where('users.is_active', true))
            ->exists();
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
     *
     * Public since 2026-09-20 so DocumentSignaturePolicy can ask the same
     * question when withdrawing a signature. One definition of "holding it",
     * not two that drift.
     */
    public function holdsDocument(User $user, Document $document): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $leg = $document->openMovement;

        return $leg !== null && $user->actsForOffice($leg->to_office_id);
    }
}
