<?php

namespace App\Http\Controllers;

use App\Actions\Documents\AdvanceRoute;
use App\Actions\Documents\RouteDocument;
use App\Actions\Documents\SignDocument;
use App\Actions\Documents\StoreDocumentFile;
use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Enums\SignatureMethod;
use App\Http\Requests\Documents\TransitionDocumentRequest;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentSignature;
use App\Models\Office;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

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
 *
 * §15 rides along on that same branch. An office may sign the version it is
 * releasing at the moment it releases it -- one submit, one transaction, both
 * or neither. It is offered, never required: a forward with no signature block
 * behaves exactly as it did before the feature existed.
 */
class DocumentWorkflowController extends Controller
{
    public function store(
        TransitionDocumentRequest $request,
        Document $document,
        TransitionDocument $transition,
        RouteDocument $route,
        AdvanceRoute $advance,
        SignDocument $sign,
        StoreDocumentFile $store,
    ): RedirectResponse {
        $action = $request->enum('action', MovementAction::class);

        /*
         * Resubmitting a returned document, with the corrected file riding
         * along when one was attached -- one submit, one transaction, both or
         * neither. The client's point on 2026-09-15 was that the correction
         * lands on the SAME document, so it is appended to this document's
         * version history rather than filed as anything new.
         *
         * The file is stored AFTER the transition, so a stale tab that the
         * transition refuses never writes an upload at all, and the new version
         * is recorded against the leg that carried it back.
         */
        if ($action === MovementAction::Resubmitted) {
            [$moved, $file] = DB::transaction(function () use ($request, $document, $transition, $store): array {
                $moved = $transition->handle(
                    document: $document,
                    action: MovementAction::Resubmitted,
                    actor: $request->user(),
                    remarks: $request->input('remarks'),
                    toOfficeId: null,
                    expectedMovementId: $request->integer('expected_movement_id') ?: null,
                    request: $request,
                );

                $upload = $request->file('file');

                $file = $upload instanceof UploadedFile
                    ? $store->handle(
                        document: $document,
                        upload: $upload,
                        uploader: $request->user(),
                        movement: $moved,
                        replaceReason: $request->input('replace_reason') ?: 'Corrected after being returned.',
                    )
                    : null;

                return [$moved, $file];
            });

            $office = $this->officeNames(array_filter([$moved->to_office_id]))[0] ?? 'the office that returned it';
            $message = "{$document->control_number} resubmitted to {$office}.";

            return back()->with('toast', [
                'type' => 'success',
                'message' => $file === null ? $message : $message." Corrected file saved as version {$file->version}.",
            ]);
        }

        /** @var list<int> $destinations */
        $destinations = array_map('intval', (array) $request->input('to_office_ids', []));

        if ($action === MovementAction::Forwarded && $destinations !== []) {
            $signature = DB::transaction(function () use ($request, $document, $route, $sign, $destinations): ?DocumentSignature {
                /*
                 * Signed BEFORE the forward, and that order is the whole point.
                 *
                 * SignDocument binds the row to the document's open leg, so
                 * signing first attaches it to the leg that is still parked at
                 * the releasing office -- which is what makes "did the office
                 * holding this sign it?" answerable later by joining on the
                 * movement instead of matching an office name. Sign afterwards
                 * and the row would name the office the folder had already
                 * moved to.
                 */
                $signature = $request->carriesSignature()
                    ? $sign->handle(
                        document: $document,
                        signer: $request->user(),
                        method: $request->enum('signature_method', SignatureMethod::class),
                        drawnPng: $request->input('signature_image'),
                        purpose: DocumentSignature::PURPOSE_RELEASE,
                        request: $request,
                    )
                    : null;

                $route->handle(
                    document: $document,
                    actor: $request->user(),
                    officeIds: $destinations,
                    remarks: $request->input('remarks'),
                    expectedMovementId: $request->integer('expected_movement_id') ?: null,
                    request: $request,
                );

                return $signature;
            }, 3);

            return back()->with('toast', [
                'type' => 'success',
                'message' => $this->routeConfirmation($document, $destinations, $signature),
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
     * A signature adds its serial rather than a bare "and signed": the serial
     * is what the certificate and the QR verification page are looked up by, so
     * it is the one part of the receipt worth writing down.
     *
     * @param  list<int>  $destinations
     */
    private function routeConfirmation(
        Document $document,
        array $destinations,
        ?DocumentSignature $signature = null,
    ): string {
        $names = $this->officeNames($destinations);
        $first = array_shift($names);

        $sent = $names === []
            ? "{$document->control_number} sent to {$first}."
            : "{$document->control_number} sent to {$first}, then queued for ".$this->list($names).'.';

        if ($signature === null) {
            return $sent;
        }

        return $sent." Signed on release — certificate serial {$signature->serial}.";
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

    private function originatingOfficeName(Document $document): string
    {
        return $this->officeNames([$document->originating_office_id])[0] ?? 'the originating office';
    }

    private function confirmation(MovementAction $action, Document $document): string
    {
        return match ($action) {
            // No next office on the list, so the folder stays put. Says so,
            // rather than leaving a receipt that looks like it did nothing.
            MovementAction::Received => "{$document->control_number} received. It stays with your office until you send it on.",
            MovementAction::Approved => "{$document->control_number} approved. You can now send it to another office.",
            MovementAction::Rejected => "{$document->control_number} rejected.",
            MovementAction::Returned => "{$document->control_number} returned to {$this->originatingOfficeName($document)} for correction.",
            MovementAction::Forwarded => "{$document->control_number} forwarded.",
            MovementAction::Completed => "{$document->control_number} marked complete.",
            default => "{$document->control_number} updated.",
        };
    }
}
