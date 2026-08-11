<?php

namespace App\Support\Presenters;

use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentFile;
use App\Models\DocumentMovement;
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

            'expected_movement_id' => $leg?->id,
            'can' => [
                'update' => $viewer->can('update', $document),
                'uploadVersion' => $viewer->can('uploadVersion', $document),
                'comment' => $viewer->can('comment', $document),
                'sign' => $viewer->can('sign', $document),
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
            ];
        }

        return $rows;
    }

    /**
     * §15 signatures.
     *
     * `valid` and `superseded` are computed per row rather than stored, so the
     * page always shows the state as of now -- a file swapped an hour ago shows
     * as mismatched without waiting for the nightly sweep.
     *
     * $maxVersion is passed in from the already-loaded files collection.
     * Without it every row called isSuperseded(), which is one COUNT query per
     * signature -- the page ran N+1 for a number it already had in memory.
     *
     * @param  iterable<int, DocumentSignature>  $signatures
     * @return array<int, array<string, mixed>>
     */
    public function signatures(iterable $signatures, ?int $maxVersion = null): array
    {
        $rows = [];

        foreach ($signatures as $signature) {
            $rows[] = [
                'id' => $signature->id,
                'serial' => $signature->serial,
                'signer_name' => $signature->signer_name,
                'signer_position' => $signature->signer_position,
                'signer_office' => $signature->signer_office,
                'purpose' => $signature->purpose,
                'method' => $signature->method->value,
                'file_version' => $signature->file?->version,
                'signed_at' => $signature->signed_at->toIso8601String(),
                'valid' => $signature->isValid(),
                'superseded' => $maxVersion === null
                    ? $signature->isSuperseded()
                    : ($signature->file === null
                        ? $maxVersion > 0
                        : $signature->file->version < $maxVersion),
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
