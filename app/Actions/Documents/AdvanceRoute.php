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
 * WHICH ACTIONS ADVANCE, and why RECEIVED is the one that matters:
 *
 *  - RECEIVED advances. This is the client's flow of 2026-09-03: "once
 *    mag-received na, walang approved, proceed na sa next office". The office
 *    holding the folder acknowledges it arrived, and that receipt is what
 *    releases it to the next office on the list. Nothing else is asked of them.
 *
 *    It replaces APPROVED, which is what the route used to wait for and is why
 *    the client's documents kept dying at the third department: approving is
 *    Admin-only and, with self-approval off, forbidden to the document's own
 *    author, so any office in the queue without a qualifying approver held the
 *    folder forever while every stop behind it read "Waiting". Receiving is
 *    neither -- see MovementAction::isDecision().
 *
 *  - APPROVED still advances, and only for documents already mid-route when
 *    this shipped. DocumentWorkflow no longer offers the action anywhere, so
 *    nothing new can arrive here through it.
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
 * THE END OF THE LINE. When the last office on the route receives the folder
 * there is nothing left to forward to, and the client asked for the document to
 * close itself there rather than wait for one more click. So a receipt with no
 * pending stop left COMPLETES a document that was travelling a route.
 *
 * "Was travelling a route" is the load-bearing half of that sentence. A
 * document nobody routed has no stops at all, and completing it the instant its
 * own originating office acknowledged it would close it before any work had
 * been done. Those stay open, and are completed by hand.
 *
 * The advance is an ordinary forward through the untouched TransitionDocument,
 * performed by the SAME actor who received. That is honest: their receipt is
 * what released the folder, exactly as it would be if they had then clicked
 * "Send to Another Office" themselves -- which is the only thing this replaces.
 */
final class AdvanceRoute
{
    public function __construct(private readonly TransitionDocument $transition) {}

    /**
     * @return DocumentMovement|null the leg that moved it on -- or closed it --
     *                               if the receipt did either
     */
    public function handle(
        Document $document,
        MovementAction $action,
        User $actor,
        ?Request $request = null,
    ): ?DocumentMovement {
        if (! $this->advancesTheRoute($action)) {
            if ($this->tearsDownTheRoute($action)) {
                $this->cancelPending($document);
            }

            return null;
        }

        $stop = DB::transaction(function () use ($document): ?DocumentRouteStop {
            /*
             * Locked and re-read inside the transaction: two clerks receiving
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

        // expectedMovementId is deliberately NULL on both calls below. The guard
        // exists to catch a human acting from a stale form; these are issued by
        // the server a microsecond ago against the leg it just created, and
        // passing an id here would only invent a way for the system to 409
        // itself.
        if ($stop === null) {
            return $this->closeFinishedRoute($document, $actor, $request);
        }

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

    /**
     * The last office on the list has just received the folder: there is
     * nowhere left to send it, so the document closes here.
     *
     * Only for a document that actually travelled a route. `routeStops()`
     * counts every stop whatever its status, so a route whose tail was
     * cancelled still counts as one -- the folder did travel, it just stopped
     * early, and the office holding it is still the end of the line.
     *
     * A document with no stops at all was never routed. Completing that one on
     * receipt would close it the moment its own originating office
     * acknowledged it, before anybody had done anything, so it is left open for
     * a human to complete.
     */
    private function closeFinishedRoute(
        Document $document,
        User $actor,
        ?Request $request,
    ): ?DocumentMovement {
        if ($document->routeStops()->count() === 0) {
            return null;
        }

        return $this->transition->handle(
            document: $document,
            action: MovementAction::Completed,
            actor: $actor,
            remarks: null,
            toOfficeId: null,
            expectedMovementId: null,
            request: $request,
        );
    }

    /**
     * `received` is the client's flow. `approved` is kept only so a document
     * left mid-route by the old workflow can still finish its trip -- nothing
     * can perform that action any more.
     */
    private function advancesTheRoute(MovementAction $action): bool
    {
        return in_array($action, [
            MovementAction::Received,
            MovementAction::Approved,
        ], true);
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
