<?php

namespace App\Policies;

use App\Models\DocumentComment;
use App\Models\User;

class DocumentCommentPolicy
{
    public function __construct(private readonly DocumentPolicy $documents) {}

    public function view(User $user, DocumentComment $comment): bool
    {
        if (! $this->documents->view($user, $comment->document)) {
            return false;
        }

        // Nor between every office a document was broadcast to: reading an
        // Executive Order is not a seat in the discussion behind it.
        if ($comment->is_internal && ! $this->documents->involved($user, $comment->document)) {
            return false;
        }

        // Internal notes stay between staff -- the submitter must not read the
        // office's private deliberation about their own document.
        if ($comment->is_internal && $comment->document->created_by_id === $user->id && ! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        return true;
    }

    /**
     * Decision remarks are ledger entries with an immutable copy on the
     * movement. Allowing an edit here would let the two diverge.
     */
    public function update(User $user, DocumentComment $comment): bool
    {
        if (! $comment->isEditable()) {
            return false;
        }

        return $user->is_active
            && $comment->user_id === $user->id
            && ! $comment->document->isArchived();
    }

    public function delete(User $user, DocumentComment $comment): bool
    {
        if (! $comment->isEditable()) {
            return false;
        }

        return $user->is_active
            && ($user->isSuperAdmin() || $comment->user_id === $user->id);
    }
}
