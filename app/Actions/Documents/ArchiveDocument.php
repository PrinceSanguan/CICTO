<?php

namespace App\Actions\Documents;

use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Support\Deadlines;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * §16 Archive Management. The only writer of documents.archived_at.
 *
 * Archiving is FILING, not deletion and not a workflow step. A document only
 * reaches here once it is already terminal (completed or rejected), so its last
 * leg is closed and there is nothing to route. That means archiving must NOT
 * open a leg: `is_open` stays null and the one-open-leg invariant is untouched.
 *
 * A row is still appended to document_movements, because §13 says the ledger is
 * the single history of what happened to a document and "filed away on the 3rd,
 * restored on the 9th" is part of that history.
 */
final class ArchiveDocument
{
    public function archive(Document $document, User $actor, ?string $reason = null): Document
    {
        return DB::transaction(function () use ($document, $actor, $reason): Document {
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            // Already filed. Returning quietly rather than throwing: two people
            // clicking Archive is not an error worth a stack trace, and the
            // second click has nothing to do.
            if ($locked->archived_at !== null) {
                return $locked;
            }

            $now = Deadlines::now();
            $last = $locked->movements()->reorder('sequence', 'desc')->first();

            $locked->forceFill([
                'archived_at' => $now,
                'archived_by_id' => $actor->id,
                'archive_reason' => $reason,
            ])->save();

            $this->record($locked, $actor, $last, MovementAction::Archived, $reason, $now);

            return $locked;
        });
    }

    public function restore(Document $document, User $actor, ?string $reason = null): Document
    {
        return DB::transaction(function () use ($document, $actor, $reason): Document {
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->archived_at === null) {
                return $locked;
            }

            $now = Deadlines::now();
            $last = $locked->movements()->reorder('sequence', 'desc')->first();

            $locked->forceFill([
                'archived_at' => null,
                'archived_by_id' => null,
                'archive_reason' => null,
            ])->save();

            // Restoring returns it to the same terminal status it had. It does
            // NOT reopen the workflow -- a completed document that comes out of
            // the archive is still completed.
            $this->record($locked, $actor, $last, MovementAction::Restored, $reason, $now);

            return $locked;
        });
    }

    private function record(
        Document $document,
        User $actor,
        ?DocumentMovement $last,
        MovementAction $action,
        ?string $reason,
        CarbonInterface $at,
    ): void {
        // Where the document was when it was filed. A document with no legs at
        // all (archived straight after registration) falls back to the office
        // that raised it, so the ledger row always names somewhere.
        $office = $last === null
            ? $document->originating_office_id
            : ($last->to_office_id ?? $document->originating_office_id);

        DocumentMovement::create([
            'document_id' => $document->id,
            'sequence' => $last === null ? 1 : $last->sequence + 1,
            'action' => $action->value,
            'actor_id' => $actor->id,
            'from_office_id' => $last?->to_office_id,
            'to_office_id' => $office,
            'remarks' => $reason,
            'arrived_at' => $at,
            // Closed immediately. Filing is an event, not a place a document
            // waits, and an open leg here would break the one-open-leg index.
            'departed_at' => $at,
            'is_open' => null,
            // Filing does not change the workflow state: a completed document
            // is still completed after it is archived.
            'from_status' => $document->status->value,
            'to_status' => $document->status->value,
        ]);
    }
}
