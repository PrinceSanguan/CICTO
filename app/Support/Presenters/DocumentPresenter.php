<?php

namespace App\Support\Presenters;

use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentFile;
use App\Models\DocumentMovement;
use App\Models\DocumentRouteStop;
use App\Models\DocumentSignature;
use App\Models\User;
use App\Support\Deadlines;
use App\Support\DocumentWorkflow;

/**
 * One place that decides what a document looks like on the wire.
 *
 * Explicit projections, never a raw model: Inertia serialises whatever it is
 * handed, so returning the model would ship every column -- including internal
 * remarks and the QR token -- to anyone who can open the page.
 */
class DocumentPresenter
{
    /**
     * §9's routing plan, in visiting order.
     *
     * Every stop is returned, resolved ones included, so the page can show what
     * the route WAS after a rejection cancelled the rest of it -- a route that
     * silently emptied itself would look like the send never happened.
     *
     * Returns an empty list for a document sent one office at a time, which is
     * how the panel knows not to render at all.
     *
     * @return list<array<string, mixed>>
     */
    private function route(Document $document): array
    {
        return array_values($document->routeStops
            ->map(fn (DocumentRouteStop $stop): array => [
                'id' => $stop->id,
                'position' => $stop->position,
                'office' => $stop->office->name,
                'status' => $stop->status->value,
                'status_label' => $stop->status->label(),
                'status_tone' => $stop->status->tone(),
            ])
            ->all());
    }

    /**
     * Where the route STARTED: the originating office.
     *
     * The client reported the Route panel with the originating office missing
     * from it (2026-09-13, "hindi po nakikita dito yung originating office"),
     * and the panel really was one office short of the truth. On the §5 submit
     * form the departments are picked as ONE ordered list; DocumentController
     * registers the document under the FIRST pick -- it owns the control number
     * prefix and the genesis leg -- and only the rest become
     * document_route_stops rows. So a five-department submit drew a
     * four-department route, silently missing the department it started at.
     *
     * It is a presentational row, not a stop, and deliberately so: inventing a
     * DocumentRouteStop for the originating office would put a row in the
     * routing PLAN for an office the folder has already been to, and
     * AdvanceRoute would then have a stop to reason about that nothing queued.
     * The office is read off documents.originating_office_id, which is NOT NULL
     * and is exactly what the genesis leg points at.
     *
     * Returned whatever the route looks like; the panel that renders it is
     * still gated on there being stops to show.
     *
     * @return array<string, mixed>|null
     */
    private function routeOrigin(Document $document): ?array
    {
        $office = $document->originatingOffice;

        if ($office === null) {
            return null;
        }

        return [
            'office' => $office->name,

            // NOT one of RouteStopStatus's labels, because it is not one of its
            // states. "Visited" would be true but says the folder passed
            // through; this row is where the document came into existence.
            'status_label' => 'Origin',
            'status_tone' => 'sky',
        ];
    }

    /**
     * The return a document is waiting on, while it is waiting on one.
     *
     * Read off the open leg, which for a returned document is always the
     * `returned` leg itself: its actor and remarks say who sent it back and why,
     * and its FROM office is the office that returned it -- which is exactly
     * where TransitionDocument sends a resubmit. So the page can name the
     * destination before the button is pressed, from the same column the
     * action will read.
     *
     * @return array{returned_by: string|null, returned_by_office: string|null, returned_at: string|null, remarks: string|null}|null
     */
    private function returnNotice(Document $document, ?DocumentMovement $leg): ?array
    {
        if ($document->status !== DocumentStatus::Returned
            || $leg === null
            || $leg->action !== MovementAction::Returned) {
            return null;
        }

        return [
            'returned_by' => $leg->actor?->name,
            'returned_by_office' => $leg->fromOffice?->name,
            'returned_at' => $leg->arrived_at?->toIso8601String(),
            'remarks' => $leg->remarks,
        ];
    }

    /**
     * The other documents one simultaneous submit produced.
     *
     * Legacy since 2026-09-19, when the client removed "All at the same time":
     * nothing new is ever grouped, but documents filed that way before then
     * still carry their submission_group_id and still name their batch.
     *
     * Never used to authorise anything: a viewer who cannot see a sibling still
     * cannot open it -- DocumentPolicy decides that when they click. This only
     * says the batch existed, which the person who submitted it already knows.
     *
     * @return list<array<string, mixed>>
     */
    private function submittedWith(Document $document): array
    {
        if ($document->submission_group_id === null) {
            return [];
        }

        // array_values for the same reason route() does it: a keyed collection
        // is not a list, and the payload is a JSON array.
        return array_values(Document::query()
            ->where('submission_group_id', $document->submission_group_id)
            ->whereKeyNot($document->id)
            ->with('originatingOffice:id,name')
            ->orderBy('id')
            ->get(['id', 'control_number', 'originating_office_id', 'status'])
            ->map(fn (Document $sibling): array => [
                'id' => $sibling->id,
                'control_number' => $sibling->control_number,
                'office' => $sibling->originatingOffice->name,
                'status_label' => $sibling->status->publicLabel(),
                'status_tone' => $sibling->status->tone(),
            ])
            ->all());
    }

    /**
     * Where the document is, or -- once it is finished -- where it finished.
     *
     * The open leg answers this while the document is moving. After the last
     * transition there is no open leg, and the Department column would read as
     * an em dash even though the ledger plainly records the office that closed
     * it. Falls back to the originating office for a document that was created
     * and never routed, so the column is only ever blank when the register
     * genuinely has no office to name.
     */
    public function restingOffice(Document $document, ?DocumentMovement $leg): string
    {
        // Branching on the foreign key rather than nullsafe-chaining the
        // relation: to_office_id is nullable in the schema but larastan reads
        // the relation itself as non-null, so `?->toOffice?->name` is an error
        // at level 7. originating_office_id is NOT NULL, which is why the
        // fallback needs no guard and this returns a plain string.
        $resting = $leg ?? $document->lastMovement;

        if ($resting !== null && $resting->to_office_id !== null) {
            return $resting->toOffice->name;
        }

        return $document->originatingOffice->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function listItem(Document $document): array
    {
        $leg = $document->openMovement;

        return [
            'id' => $document->id,
            'control_number' => $document->control_number,
            'title' => $document->title,
            'status' => $document->status->value,
            'status_label' => $document->status->publicLabel(),
            'status_tone' => $document->status->publicTone(),
            'priority' => $document->priority->value,
            'priority_label' => $document->priority->label(),
            'priority_tone' => $document->priority->tone(),
            'document_type' => $document->documentType?->name,
            'current_office' => $leg?->toOffice?->name,
            'resting_office' => $this->restingOffice($document, $leg),
            'due_at' => $document->due_at?->toIso8601String(),
            'due_state' => $document->dueState()->value,
            'due_state_label' => $document->dueState()->label(),
            'due_state_tone' => $document->dueState()->tone(),
            'is_archived' => $document->isArchived(),
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }

    /**
     * §10 status tracking: current stage, time at the current office, which
     * office holds it, and the expected completion window.
     *
     * @return array<string, mixed>
     */
    public function detail(Document $document, User $viewer): array
    {
        $leg = $document->openMovement;
        $minutesHere = $document->minutesAtCurrentOffice();

        return [
            ...$this->listItem($document),
            'description' => $document->description,
            'remarks' => $document->remarks,
            'originating_office' => $document->originatingOffice?->name,
            'created_by' => $document->creator?->name,
            'completed_at' => $document->completed_at?->toIso8601String(),

            'tracking' => [
                'current_office' => $leg?->toOffice?->name,
                'current_office_id' => $leg?->to_office_id,
                'resting_office' => $this->restingOffice($document, $leg),
                'is_open' => $leg !== null,
                'arrived_at' => $leg?->arrived_at?->toIso8601String(),
                'minutes_at_current_office' => $minutesHere,
                'time_at_current_office' => $this->humanMinutes($minutesHere),
                'leg_due_at' => $leg?->due_at?->toIso8601String(),
                'expected_completion_at' => $document->due_at?->toIso8601String(),
            ],

            // The action set comes from the same const map that guards the
            // server, filtered by what this viewer may actually do. A button
            // that would 403 is never rendered -- and a button that is not
            // rendered still cannot be forced.
            'available_actions' => array_values(array_map(
                fn ($action) => [
                    'value' => $action->value,
                    'label' => $action->label(),
                    'requires_remarks' => $action->requiresRemarks(),
                ],
                array_filter(
                    DocumentWorkflow::allowed($document->status),
                    fn ($action) => $viewer->can('act', [$document, $action]),
                ),
            )),

            // True when pressing Received closes the document instead of moving
            // it on: the route has run out. The page says so before the click,
            // because at the last office Received is the Completed button now.
            'receipt_completes' => $document->receiptCompletes(),

            /*
             * §9's routing plan: the offices this document is queued to visit.
             *
             * ADDITIVE, and deliberately a sibling of `tracking` rather than a
             * field inside it. `tracking` answers "where is the folder", which
             * is still exactly one office; this answers "where is it going",
             * which is a different question with a different cardinality.
             * Nothing already reading `tracking` changes shape.
             */
            'route' => $this->route($document),

            /*
             * The first office of that same plan -- see routeOrigin(). A
             * sibling of `route` rather than an element of it, so the panel's
             * "render only when there is a route" test stays a test on the
             * STOPS and a document nobody routed does not grow a one-row Route
             * card naming the office it has never left.
             */
            'route_origin' => $this->routeOrigin($document),

            /*
             * Why a returned document is back at its originating office, and
             * where Resubmit will send it. Null for every other document.
             */
            'return_notice' => $this->returnNotice($document, $leg),

            /*
             * The rest of the same submit, when it went to several departments
             * at the same time.
             *
             * These are SEPARATE documents, each with its own control number,
             * deadline and trail -- that is what "no hierarchy" costs and what
             * it buys. All this does is name them, so a submitter who pressed
             * Submit once is not left wondering where the other copies went.
             */
            'submitted_with' => $this->submittedWith($document),

            'expected_movement_id' => $leg?->id,

            /*
             * §15 handoff: has the office holding this folder already signed
             * the exact version it is holding? Drives the line above the
             * forward panel, and hides a second pad once it has.
             */
            'release_signature' => $this->releaseSignature($document, $leg),

            'can' => [
                'update' => $viewer->can('update', $document),
                'uploadVersion' => $viewer->can('uploadVersion', $document),
                'comment' => $viewer->can('comment', $document),
                'sign' => $viewer->can('sign', $document),
                'signRelease' => $viewer->can('signRelease', $document),
                'archive' => $viewer->can('archive', $document),
                'restore' => $viewer->can('restore', $document),
            ],
        ];
    }

    /**
     * §13 audit trail. Every leg, in order, with the dwell time computed in PHP
     * -- there are at most a couple of dozen legs, so there is nothing to
     * aggregate in SQL, and this keeps Carbon::setTestNow() honest in tests.
     *
     * @param  iterable<int, DocumentMovement>  $movements
     * @return array<int, array<string, mixed>>
     */
    public function timeline(iterable $movements): array
    {
        $rows = [];

        foreach ($movements as $movement) {
            $dwell = $movement->dwellMinutes();

            $rows[] = [
                'id' => $movement->id,
                'sequence' => $movement->sequence,
                'action' => $movement->action->value,
                'action_label' => $movement->action->label(),
                'verb' => $movement->action->verb(),
                'actor' => $movement->actor?->name,
                'from_office' => $movement->fromOffice?->name,
                'to_office' => $movement->toOffice?->name,
                'remarks' => $movement->remarks,
                'arrived_at' => $movement->arrived_at?->toIso8601String(),
                'departed_at' => $movement->departed_at?->toIso8601String(),
                'is_open' => $movement->departed_at === null,
                'dwell_minutes' => $dwell,
                'dwell' => $dwell === null
                    ? $this->humanMinutes($this->openLegMinutes($movement))
                    : $this->humanMinutes($dwell),
            ];
        }

        return $rows;
    }

    /**
     * The single slowest leg so far, for §10's tracking panel.
     *
     * Derived from the same movement rows the timeline uses rather than stored,
     * so it cannot go stale. The OPEN leg counts: a document that has been
     * sitting in one office for three days is exactly the case a records
     * officer is looking for, and excluding it would hide the live problem
     * while reporting a finished one.
     *
     * @param  iterable<int, DocumentMovement>  $movements
     * @return array<string, mixed>|null
     */
    public function longestStage(iterable $movements): ?array
    {
        $slowest = null;
        $slowestMinutes = -1;

        foreach ($movements as $movement) {
            $minutes = $movement->dwellMinutes() ?? $this->openLegMinutes($movement);

            if ($minutes === null || $minutes <= $slowestMinutes) {
                continue;
            }

            $slowest = $movement;
            $slowestMinutes = $minutes;
        }

        if ($slowest === null) {
            return null;
        }

        return [
            'office' => $slowest->toOffice?->name,
            'stage' => $slowest->action->label(),
            'minutes' => $slowestMinutes,
            'duration' => $this->humanMinutes($slowestMinutes),
            'is_open' => $slowest->departed_at === null,
        ];
    }

    /**
     * §13: "how long it stayed at each stage/office."
     *
     * The per-leg timeline answers "what happened"; this answers the question
     * §1 says the client actually has -- which office is slow. Grouped in PHP
     * because a document has at most a couple of dozen legs, so there is
     * nothing worth aggregating in SQL, and Carbon::setTestNow() keeps working.
     *
     * @param  iterable<int, DocumentMovement>  $movements
     * @return array<int, array<string, mixed>>
     */
    public function officeRollup(iterable $movements): array
    {
        $byOffice = [];

        foreach ($movements as $movement) {
            $office = $movement->toOffice;

            if ($office === null) {
                continue;
            }

            $byOffice[$office->id] ??= [
                'office' => $office->name,
                'visits' => 0,
                'minutes' => 0,
                'is_current' => false,
            ];

            $byOffice[$office->id]['visits']++;

            $dwell = $movement->dwellMinutes();

            if ($dwell !== null) {
                $byOffice[$office->id]['minutes'] += $dwell;
            } else {
                // Still holding it -- count the time so far so a document
                // parked for a week does not show as 0 minutes.
                $byOffice[$office->id]['is_current'] = true;
                $byOffice[$office->id]['minutes'] += $this->openLegMinutes($movement) ?? 0;
            }
        }

        return array_values(array_map(
            fn (array $row) => [...$row, 'duration' => $this->humanMinutes($row['minutes'])],
            $byOffice,
        ));
    }

    /**
     * @param  iterable<int, DocumentFile>  $files
     * @return array<int, array<string, mixed>>
     */
    public function files(iterable $files): array
    {
        $rows = [];

        foreach ($files as $file) {
            $rows[] = [
                'id' => $file->id,
                'version' => $file->version,
                'original_name' => $file->original_name,
                'size' => $file->humanSize(),
                'mime_type' => $file->mime_type,
                'uploaded_by' => $file->uploader?->name,
                'uploaded_at' => $file->created_at?->toIso8601String(),
                'replace_reason' => $file->replace_reason,
                'is_purged' => $file->isPurged(),

                // Whether this one can be shown on screen at all -- either as
                // its own bytes or, for .docx and .xlsx, as the HTML the
                // server converts it into (2026-09-20). Older .doc and .xls
                // still cannot, so the page offers them a download and says
                // why rather than opening a blank frame. The rule itself lives
                // on the model, so the button
                // and the endpoint cannot disagree about what is previewable.
                'is_previewable' => $file->isPreviewable(),
            ];
        }

        return $rows;
    }

    /**
     * The handoff signature covering the version currently on this desk, if
     * there is one.
     *
     * Matched on document_movement_id, NOT on the signer_office name snapshot.
     * The movement IS the office's custody record, so a release signature
     * carrying the open leg's id is by construction one made by the office that
     * holds the folder now -- and it keeps answering correctly after an office
     * is renamed, which a string comparison would not.
     *
     * Read from the already-loaded relation when the caller loaded it, which
     * DocumentController::show does. Falling through to a query here would put
     * one back on a page that just fetched every signature it needed.
     *
     * @return array{signer_name: string, signer_position: string|null, signed_at: string, serial: string, file_version: int|null}|null
     */
    private function releaseSignature(Document $document, ?DocumentMovement $leg): ?array
    {
        if ($leg === null) {
            return null;
        }

        $signatures = $document->relationLoaded('signatures')
            ? $document->signatures
            : $document->signatures()->get();

        $match = $signatures
            ->filter(fn (DocumentSignature $signature): bool => $signature->purpose === DocumentSignature::PURPOSE_RELEASE
                && $signature->document_movement_id === $leg->id)
            ->sortByDesc('signed_at')
            ->first();

        if (! $match instanceof DocumentSignature) {
            return null;
        }

        return [
            'serial' => $match->serial,
            'signer_name' => $match->signer_name,
            'signer_position' => $match->signer_position,
            'signed_at' => $match->signed_at->toIso8601String(),
            'file_version' => $match->file?->version,
        ];
    }

    /**
     * §15 signatures.
     *
     * `valid` and `superseded` are computed per row rather than stored, so the
     * page always shows the state as of now -- a file swapped an hour ago shows
     * as mismatched without waiting for the nightly sweep.
     *
     * $maxUploadedVersion is the newest version somebody UPLOADED, passed in by
     * the caller so this does not run one query per row -- the page ran N+1 for
     * a number it already had in memory. It must EXCLUDE versions that stamping
     * produced (see DocumentFile::lastUploadedVersion): a stamped signature
     * always sits one version below its own output, so counting those marked
     * every signature superseded the moment it was made.
     *
     * `can_undo` is the §15 undo button of 2026-09-20, answered per row by
     * DocumentSignaturePolicy rather than guessed at in the component -- it
     * depends on who is holding the folder and on what has happened since,
     * neither of which the page knows. Null $viewer means nobody may.
     *
     * @param  iterable<int, DocumentSignature>  $signatures
     * @return array<int, array<string, mixed>>
     */
    public function signatures(
        iterable $signatures,
        ?int $maxUploadedVersion = null,
        ?User $viewer = null,
    ): array {
        $rows = [];

        foreach ($signatures as $signature) {
            $rows[] = [
                'id' => $signature->id,
                'serial' => $signature->serial,
                'can_undo' => $viewer?->can('undo', $signature) ?? false,
                'signer_name' => $signature->signer_name,
                'signer_position' => $signature->signer_position,
                'signer_office' => $signature->signer_office,
                'purpose' => $signature->purpose,
                'purpose_label' => $signature->purposeLabel(),
                'method' => $signature->method->value,
                'file_version' => $signature->file?->version,
                'signed_at' => $signature->signed_at->toIso8601String(),
                'valid' => $signature->isValid(),
                'superseded' => $maxUploadedVersion === null
                    ? $signature->isSuperseded()
                    : ($signature->file === null
                        ? $maxUploadedVersion > 0
                        : $signature->file->version < $maxUploadedVersion),
            ];
        }

        return $rows;
    }

    /**
     * @param  iterable<int, DocumentComment>  $comments
     * @return array<int, array<string, mixed>>
     */
    public function comments(iterable $comments, User $viewer): array
    {
        $rows = [];

        foreach ($comments as $comment) {
            if (! $viewer->can('view', $comment)) {
                continue;
            }

            $rows[] = [
                'id' => $comment->id,
                'body' => $comment->body,
                'context' => $comment->context,
                'is_internal' => $comment->is_internal,
                'author' => $comment->author?->name,
                'created_at' => $comment->created_at?->toIso8601String(),
                'edited_at' => $comment->edited_at?->toIso8601String(),
                'can_edit' => $viewer->can('update', $comment),
                'can_delete' => $viewer->can('delete', $comment),
            ];
        }

        return $rows;
    }

    private function openLegMinutes(DocumentMovement $movement): ?int
    {
        if ($movement->arrived_at === null || $movement->departed_at !== null) {
            return null;
        }

        return (int) $movement->arrived_at->diffInMinutes(Deadlines::now());
    }

    /** "2d 19h", "6h 28m", "1m" -- never "0". */
    private function humanMinutes(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        if ($minutes < 1) {
            return 'less than a minute';
        }

        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        $parts = [];

        if ($days > 0) {
            $parts[] = $days.'d';
        }

        if ($hours > 0) {
            $parts[] = $hours.'h';
        }

        if ($mins > 0 && $days === 0) {
            $parts[] = $mins.'m';
        }

        return implode(' ', $parts);
    }
}
