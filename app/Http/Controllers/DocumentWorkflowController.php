<?php

namespace App\Http\Controllers;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Http\Requests\Documents\TransitionDocumentRequest;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;

/**
 * §9 workflow and approval.
 *
 * Deliberately thin. The controller never branches on document status: it hands
 * the action to TransitionDocument and lets an illegal transition throw, which
 * bootstrap/app.php renders as a validation error. One code path, one guard.
 */
class DocumentWorkflowController extends Controller
{
    public function store(
        TransitionDocumentRequest $request,
        Document $document,
        TransitionDocument $transition,
    ): RedirectResponse {
        $action = $request->enum('action', MovementAction::class);

        $transition->handle(
            document: $document,
            action: $action,
            actor: $request->user(),
            remarks: $request->input('remarks'),
            toOfficeId: $request->integer('to_office_id') ?: null,
            expectedMovementId: $request->integer('expected_movement_id') ?: null,
            request: $request,
        );

        return back()->with('toast', ['type' => 'success', 'message' => $this->confirmation($action, $document)]);
    }

    private function confirmation(MovementAction $action, Document $document): string
    {
        return match ($action) {
            MovementAction::Approved => "{$document->control_number} approved. You can now send it to another office.",
            MovementAction::Rejected => "{$document->control_number} rejected.",
            MovementAction::Returned => "{$document->control_number} returned for correction.",
            MovementAction::Forwarded => "{$document->control_number} forwarded.",
            MovementAction::Completed => "{$document->control_number} marked complete.",
            default => "{$document->control_number} updated.",
        };
    }
}
