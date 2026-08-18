<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Enums\Role;
use App\Models\Document;
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

        if ($user->role !== Role::Admin || $user->office_id === null) {
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

        return $document->created_by_id === $user->id
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
