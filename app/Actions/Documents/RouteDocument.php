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
 * §9 "Send to Another Office", for several offices in one submit.
 *
 * The client asked on 2026-08-17 for "an option to select multiple offices at
 * the same time instead of sending the document one office at a time". This is
 * that: ONE submit, N offices, in the order the sender picked them.
 *
 * It is a ROUTING LIST, not parallel custody, and that is deliberate. The system
 * tracks a physical folder carrying one printed QR label; a folder cannot be in
 * three offices at once, the public scan page has exactly one "Currently at"
 * line to answer with, and document_movements enforces one open leg per document
 * in the database. So the folder still travels one office at a time -- the
 * sender just plans the whole trip in one action, and the system performs the
 * hops instead of asking for a click at each one. See AdvanceRoute.
 *
 * The ledger this produces is INDISTINGUISHABLE from a clerk forwarding by hand
 * N times: same linear sequence chain, one open leg at every instant, disjoint
 * dwell windows. Nothing already collected in UAT is invalidated, and
 * TransitionDocument -- the only writer of documents.status and
 * document_movements -- is called unchanged.
 */
final class RouteDocument
{
    public function __construct(private readonly TransitionDocument $transition) {}

    /**
     * @param  list<int>  $officeIds  destinations in visiting order; at least one
     */
    public function handle(
        Document $document,
        User $actor,
        array $officeIds,
        ?string $remarks = null,
        ?int $expectedMovementId = null,
        ?Request $request = null,
    ): DocumentMovement {
        $officeIds = array_values(array_unique(array_map('intval', $officeIds)));

        if ($officeIds === []) {
            throw new \InvalidArgumentException('Routing requires at least one destination office.');
        }

        return DB::transaction(function () use ($document, $actor, $officeIds, $remarks, $expectedMovementId, $request): DocumentMovement {
            /*
             * A new route replaces any old one. Re-routing a document that
             * still has stops queued is the sender changing their mind, and
             * leaving the previous tail in place would send the folder
             * somewhere nobody asked for two hops later.
             */
            $this->cancelPending($document);

            // The first office is an ordinary forward, performed by the
            // untouched TransitionDocument, so the stale-tab guard and the row
            // locks behave exactly as they do for a single send.
            $movement = $this->transition->handle(
                document: $document,
                action: MovementAction::Forwarded,
                actor: $actor,
                remarks: $remarks,
                toOfficeId: $officeIds[0],
                expectedMovementId: $expectedMovementId,
                request: $request,
            );

            // Everything after the first is the plan.
            $position = $this->nextPosition($document);

            foreach (array_slice($officeIds, 1) as $officeId) {
                DocumentRouteStop::create([
                    'document_id' => $document->id,
                    'position' => $position++,
                    'office_id' => $officeId,
                    'status' => RouteStopStatus::Pending,
                    'created_by_id' => $actor->id,
                ]);
            }

            return $movement;
        }, 3);
    }

    /**
     * Positions are monotonic per document and never reused, so a second route
     * started after the first finished cannot collide with the unique index.
     */
    private function nextPosition(Document $document): int
    {
        return (int) DocumentRouteStop::query()
            ->where('document_id', $document->id)
            ->max('position') + 1;
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
