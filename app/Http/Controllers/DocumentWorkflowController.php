<?php

namespace App\Http\Controllers;

use App\Actions\Documents\AdvanceRoute;
use App\Actions\Documents\RouteDocument;
use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Http\Requests\Documents\TransitionDocumentRequest;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use Illuminate\Http\RedirectResponse;

/**
 * §9 workflow and approval.
 *
 * Deliberately thin. The controller never branches on document status: it hands
 * the action to TransitionDocument and lets an illegal transition throw, which
 * bootstrap/app.php renders as a validation error. One code path, one guard.
 *
 * The one branch it does carry is forwarding: a forward may name SEVERAL
 * offices, and a routing list is a different action from a single hop even
 * though it produces the same first leg. Everything else goes straight through.
 */
class DocumentWorkflowController extends Controller
{
    public function store(
        TransitionDocumentRequest $request,
        Document $document,
        TransitionDocument $transition,
        RouteDocument $route,
        AdvanceRoute $advance,
    ): RedirectResponse {
        $action = $request->enum('action', MovementAction::class);

        /** @var list<int> $destinations */
        $destinations = array_map('intval', (array) $request->input('to_office_ids', []));

        if ($action === MovementAction::Forwarded && $destinations !== []) {
            $route->handle(
                document: $document,
                actor: $request->user(),
                officeIds: $destinations,
                remarks: $request->input('remarks'),
                expectedMovementId: $request->integer('expected_movement_id') ?: null,
                request: $request,
            );

            return back()->with('toast', [
                'type' => 'success',
                'message' => $this->routeConfirmation($document, $destinations),
            ]);
        }

        $transition->handle(
            document: $document,
            action: $action,
            actor: $request->user(),
            remarks: $request->input('remarks'),
            toOfficeId: null,
            expectedMovementId: $request->integer('expected_movement_id') ?: null,
            request: $request,
        );

        /*
         * The plan moves only after the ledger did. A receipt that fails leaves
         * the queue exactly where it was, and a document with no route is a
         * no-op here -- so this is safe to call unconditionally.
         */
        $moved = $advance->handle($document, $action, $request->user(), $request);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $moved === null
                ? $this->confirmation($action, $document)
                : $this->advanceConfirmation($document, $moved),
        ]);
    }

    /**
     * "Sent to X." for one office, "Sent to X, then queued for Y and Z." for a
     * route. The plural sentence is the client's proof the multi-select worked.
     *
     * @param  list<int>  $destinations
     */
    private function routeConfirmation(Document $document, array $destinations): string
    {
        $names = $this->officeNames($destinations);
        $first = array_shift($names);

        if ($names === []) {
            return "{$document->control_number} sent to {$first}.";
        }

        return "{$document->control_number} sent to {$first}, then queued for "
            .$this->list($names).'.';
    }

    /**
     * What the receipt did, in the sentence the person who pressed it needs.
     *
     * Two outcomes, and they read differently on purpose: a receipt in the
     * middle of a route releases the folder onward, and a receipt at the last
     * office on the route closes the document. Reporting the second as "sent
     * on to ..." would name an office the folder never went to.
     */
    private function advanceConfirmation(Document $document, DocumentMovement $moved): string
    {
        if ($moved->action === MovementAction::Completed) {
            return "{$document->control_number} received. That was the last office on the route, so it is now complete.";
        }

        $name = $this->officeNames(array_filter([$moved->to_office_id]))[0] ?? 'the next office';

        return "{$document->control_number} received and sent on to {$name}.";
    }

    /**
     * Names in the order the sender picked them, not in id or alphabetical
     * order -- the sentence has to read back the route they built.
     *
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function officeNames(array $ids): array
    {
        $names = Office::query()->whereKey($ids)->pluck('name', 'id');

        return array_values(array_filter(array_map(
            static fn (int $id) => $names[$id] ?? null,
            $ids,
        )));
    }

    /** @param  list<string>  $names */
    private function list(array $names): string
    {
        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    private function confirmation(MovementAction $action, Document $document): string
    {
        return match ($action) {
            // No next office on the list, so the folder stays put. Says so,
            // rather than leaving a receipt that looks like it did nothing.
            MovementAction::Received => "{$document->control_number} received. It stays with your office until you send it on.",
            MovementAction::Approved => "{$document->control_number} approved. You can now send it to another office.",
            MovementAction::Rejected => "{$document->control_number} rejected.",
            MovementAction::Returned => "{$document->control_number} returned for correction.",
            MovementAction::Forwarded => "{$document->control_number} forwarded.",
            MovementAction::Completed => "{$document->control_number} marked complete.",
            default => "{$document->control_number} updated.",
        };
    }
}
