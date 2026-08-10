<?php

namespace App\Actions\Documents;

use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Events\DocumentTransitioned;
use App\Exceptions\StaleWorkflowStateException;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Support\Deadlines;
use App\Support\DocumentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of documents.status and document_movements.
 *
 * Enforce this in review: no controller may call
 * $document->update(['status' => ...]) or insert a movement directly. That
 * single rule is what keeps the ledger honest, because every §10 duration and
 * every §13 timeline entry is derived from timestamps captured here.
 */
final class TransitionDocument
{
    /**
     * @param  int|null  $expectedMovementId  the open leg the form was rendered from
     * @param  int|null  $toOfficeId  required when forwarding
     */
    public function handle(
        Document $document,
        MovementAction $action,
        User $actor,
        ?string $remarks = null,
        ?int $toOfficeId = null,
        ?int $expectedMovementId = null,
        ?Request $request = null,
    ): DocumentMovement {
        return DB::transaction(function () use (
            $document, $action, $actor, $remarks, $toOfficeId, $expectedMovementId, $request
        ): DocumentMovement {
            // Lock order is fixed: the document row first, then its open leg.
            // Always that order, so two concurrent forwards of the same document
            // cannot deadlock against each other.
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            $leg = DocumentMovement::query()
                ->where('document_id', $locked->id)
                ->whereNull('departed_at')
                ->lockForUpdate()
                ->first();

            // Double-click and stale-tab guard. Request B blocks on the lock
            // above rather than reading stale data, then finds the open leg is
            // no longer the one its form was rendered from.
            if ($expectedMovementId !== null && $leg?->id !== $expectedMovementId) {
                throw new StaleWorkflowStateException;
            }

            $from = $locked->status;
            $next = DocumentWorkflow::next($from, $action);
            $now = Deadlines::now();

            if ($action === MovementAction::Forwarded && $toOfficeId === null) {
                throw new \InvalidArgumentException('Forwarding requires a destination office.');
            }

            // Close the open leg. is_open goes NULL in the same statement as
            // departed_at so the unique index and the semantic truth cannot
            // disagree even for an instant.
            //
            // Only the timestamps change. A leg's `action` and `actor_id`
            // describe what CREATED it, and overwriting them with the action
            // that ends it destroys history: the genesis leg would stop saying
            // "registered" the moment the document was first forwarded, and
            // §13's "who processed it, what action was taken" would silently
            // lose its first entry. The ending action is recorded on the new
            // leg instead.
            if ($leg !== null) {
                $leg->forceFill([
                    'departed_at' => $now,
                    'is_open' => null,
                ])->save();
            }

            $destination = match ($action) {
                // Explicit destination chosen by the reviewer.
                MovementAction::Forwarded => $toOfficeId,

                // §9 "return a document with remarks" means send it BACK for
                // correction. The open leg records where it came from, so that
                // is where it goes. Leaving it at the returning office would
                // make Return indistinguishable from Reject, and the document
                // would sit on the reviewer's own desk waiting for a fix only
                // the previous office can make.
                //
                // A document still on its genesis leg has no previous office,
                // so it falls back to where it started.
                MovementAction::Returned => $leg->from_office_id
                    ?? $locked->originating_office_id,

                // Decisions that keep custody where it already is.
                default => $leg->to_office_id ?? $locked->originating_office_id,
            };

            $isTerminal = $next->isTerminal();

            $new = DocumentMovement::create([
                'document_id' => $locked->id,
                'sequence' => $leg === null ? 1 : $leg->sequence + 1,
                'from_office_id' => $leg?->to_office_id,
                'to_office_id' => $destination,
                'actor_id' => $actor->id,
                // The action that caused this leg to exist -- "approved",
                // "forwarded", "returned" -- not a generic "received". This is
                // the row §13's timeline reads to say what happened.
                'action' => $action->value,
                'from_status' => $from->value,
                'to_status' => $next->value,
                'remarks' => $remarks,
                'arrived_at' => $now,
                'departed_at' => $isTerminal ? $now : null,
                'due_at' => $isTerminal
                    ? null
                    : Deadlines::legDueAt($locked->documentType, $locked->due_at, $now),
                'is_open' => $isTerminal ? null : 1,
                'ip_address' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 191) ?: null,
            ]);

            $locked->forceFill([
                'status' => $next->value,
                'completed_at' => $next === DocumentStatus::Completed ? $now : $locked->completed_at,
            ])->save();

            // The decision remark is mirrored into document_comments in the same
            // transaction. The movement's copy is immutable; the comment is what
            // the panel renders. A test asserts the two match.
            if (filled($remarks)) {
                DocumentComment::create([
                    'document_id' => $locked->id,
                    // Attached to the leg that carries the same remarks text,
                    // so the mirror pair is a single row lookup.
                    'document_movement_id' => $new->id,
                    'user_id' => $actor->id,
                    'context' => $this->contextFor($action),
                    'body' => $remarks,
                    'is_internal' => false,
                ]);
            }

            $document->setRawAttributes($locked->getAttributes(), true);

            // Listeners run after commit -- see DispatchDocumentNotifications.
            DocumentTransitioned::dispatch($locked, $new, $action, $actor);

            return $new;
        }, 3); // retries deadlocks: MySQL 1213, PostgreSQL 40P01
    }

    /**
     * Every remark written here is a ledger entry with an immutable copy on the
     * movement, so none of them may fall through to CONTEXT_COMMENT -- that is
     * the one context DocumentCommentPolicy lets an author edit, and an edit
     * would let the panel and the ledger disagree.
     */
    private function contextFor(MovementAction $action): string
    {
        return match ($action) {
            MovementAction::Approved => DocumentComment::CONTEXT_APPROVAL,
            MovementAction::Rejected => DocumentComment::CONTEXT_REJECTION,
            MovementAction::Returned => DocumentComment::CONTEXT_RETURN,
            default => DocumentComment::CONTEXT_MOVEMENT,
        };
    }
}
