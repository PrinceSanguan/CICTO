<?php

namespace App\Actions\Documents;

use App\Enums\MovementAction;
use App\Enums\RouteStopStatus;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentRouteStop;
use App\Models\User;
use App\Support\Deadlines;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Moves a document to the next office on its routing plan, or tears the plan
 * down when the document stops travelling.
 *
 * Called after a transition has already succeeded, never instead of one.
 *
 * WHICH ACTIONS ADVANCE, and why only one does:
 *
 *  - APPROVED advances. §9's chain is approved -> forwarded -> under_review,
 *    which DocumentWorkflow's own comment calls "the multi-signatory chain (the
 *    Mayor's office signing after a department head)". A routing list is that
 *    chain, issued once instead of clicked N times.
 *
 *  - REJECTED and COMPLETED are terminal, so the remaining stops are cancelled.
 *    Leaving them pending would make "a completed document is held by nobody"
 *    a lie about a document still listed as travelling.
 *
 *  - RETURNED sends the folder BACKWARDS for correction. The rest of the route
 *    was planned from a document that no longer exists in that form, so it is
 *    cancelled; the correcting office picks a fresh route when it re-sends.
 *
 *  - FORWARDED cancels the rest too. A human choosing a destination by hand has
 *    overridden the plan, and silently keeping a queue that no longer matches
 *    what they typed is worse than dropping it. (RouteDocument does its own
 *    forward and writes the queue afterwards, so it never routes through here.)
 *
 * The advance is an ordinary forward through the untouched TransitionDocument,
 * performed by the SAME actor who approved. That is honest: their approval is
 * what released the folder, exactly as it would be if they had then clicked
 * "Send to Another Office" themselves -- which is the only thing this replaces.
 */
final class AdvanceRoute
{
    public function __construct(private readonly TransitionDocument $transition) {}

    /**
     * @return DocumentMovement|null the leg that moved it on, if it moved
     */
    public function handle(
        Document $document,
        MovementAction $action,
        User $actor,
        ?Request $request = null,
    ): ?DocumentMovement {
        if ($action !== MovementAction::Approved) {
            if ($this->tearsDownTheRoute($action)) {
                $this->cancelPending($document);
            }

            return null;
        }

        $stop = DB::transaction(function () use ($document): ?DocumentRouteStop {
            /*
             * Locked and re-read inside the transaction: two admins approving
             * the same document in the same second would otherwise both claim
             * the same stop and try to forward the folder twice. The loser's
             * forward is refused by TransitionDocument's own guards, but taking
             * the stop under a lock means the loser never gets that far.
             */
            $next = DocumentRouteStop::query()
                ->where('document_id', $document->id)
                ->where('status', RouteStopStatus::Pending)
                ->orderBy('position')
                ->lockForUpdate()
                ->first();

            $next?->forceFill([
                'status' => RouteStopStatus::Visited,
                'resolved_at' => Deadlines::now(),
            ])->save();

            return $next;
        }, 3);

        if ($stop === null) {
            return null;
        }

        // expectedMovementId is deliberately NULL. The guard exists to catch a
        // human acting from a stale form; this call was issued by the server a
        // microsecond ago against the leg it just created, and passing an id
        // here would only invent a way for the system to 409 itself.
        return $this->transition->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $actor,
            remarks: null,
            toOfficeId: $stop->office_id,
            expectedMovementId: null,
            request: $request,
        );
    }

    private function tearsDownTheRoute(MovementAction $action): bool
    {
        return in_array($action, [
            MovementAction::Rejected,
            MovementAction::Returned,
            MovementAction::Completed,
            MovementAction::Forwarded,
        ], true);
    }

    private function cancelPending(Document $document): void
    {
        DocumentRouteStop::query()
            ->where('document_id', $document->id)
            ->where('status', RouteStopStatus::Pending)
            ->update([
                'status' => RouteStopStatus::Cancelled,
                'resolved_at' => Deadlines::now(),
                'updated_at' => Deadlines::now(),
            ]);
    }
}
