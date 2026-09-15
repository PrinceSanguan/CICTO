<?php

namespace App\Listeners;

use App\Enums\MovementAction;
use App\Enums\NotificationType;
use App\Events\DocumentTransitioned;
use App\Services\NotificationWriter;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * §12 triggers that are events: assigned, forwarded, returned.
 *
 * ShouldHandleEventsAfterCommit is mandatory, not stylistic. The event is
 * dispatched inside TransitionDocument's transaction; on PostgreSQL a listener
 * running there can read rows about to be rolled back, and worse, a failed
 * statement poisons the whole transaction even when the exception is caught, so
 * the later COMMIT fails anyway.
 *
 * Dispatched synchronously rather than queued. QUEUE_CONNECTION=database with
 * no worker means a queued job is written and never executed -- the worst
 * failure mode available, because it looks exactly like success.
 */
class DispatchDocumentNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly NotificationWriter $writer) {}

    public function handle(DocumentTransitioned $event): void
    {
        // A notification failure must never surface as a failed forward. The
        // movement is the business record; the bell is a convenience.
        try {
            $this->dispatch($event);
        } catch (\Throwable $e) {
            Log::error('Failed to write document notifications', [
                'document_id' => $event->document->id,
                'movement_id' => $event->movement->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function dispatch(DocumentTransitioned $event): void
    {
        // Both go back to the people who filed the document. Rejected is
        // legacy -- nothing can reject since 2026-09-15 -- but stays wired so a
        // stray one still reaches the submitter.
        if (in_array($event->action, [MovementAction::Rejected, MovementAction::Returned], true)) {
            $this->dispatchToOriginator(
                $event,
                $event->action === MovementAction::Returned ? NotificationType::Returned : NotificationType::Rejected,
            );

            return;
        }

        $movement = $event->movement;

        $type = match ($event->action) {
            MovementAction::Registered => NotificationType::Assigned,
            MovementAction::Forwarded => NotificationType::Forwarded,
            MovementAction::Resubmitted => NotificationType::Resubmitted,
            default => null,
        };

        if ($type === null) {
            return;
        }

        // A same-office decision is not an arrival. Notifying on it means
        // approving a document spams your own colleagues.
        //
        // The genesis leg is exempt: from_office_id is NULL there, so it never
        // matches, and a newly registered document does need to reach the
        // office that now holds it.
        if ($movement->from_office_id === $movement->to_office_id) {
            return;
        }

        $this->writer->fanOutToOffice(
            type: $type,
            document: $event->document,
            officeId: $movement->to_office_id,
            movement: $movement,
            except: $event->actor,
        );
    }

    /**
     * A return (and, on older documents, a rejection) notifies the people who
     * FILED the document, not merely the office the folder lands at.
     *
     * A return moves the folder to the originating office, so the arrival rule
     * would reach that office anyway -- but the person who has to upload the
     * correction is the submitter, and a clerk can file against an office they
     * do not belong to. So it goes to the ORIGINATING office plus the submitter
     * by name. Both are guaranteed to pass DocumentPolicy::view (originating
     * office, and author), so neither gets the dead bell fanOutToOffice exists
     * to avoid. A submitter who is a member of the originating office is covered
     * by both calls and notified once: the two writes share a dedupe key, and
     * unique(user_id, dedupe_key) collapses them.
     *
     * A rejection needed this for a stronger reason -- it moved the folder
     * nowhere, so `from_office_id === to_office_id` and the arrival guard below
     * would have dropped it entirely.
     *
     * The office that pressed the button is not told -- they already know.
     */
    private function dispatchToOriginator(DocumentTransitioned $event, NotificationType $type): void
    {
        $this->writer->fanOutToOffice(
            type: $type,
            document: $event->document,
            officeId: $event->document->originating_office_id,
            movement: $event->movement,
            except: $event->actor,
        );

        $submitter = $event->document->creator;

        if ($submitter !== null && $submitter->id !== $event->actor->id && $submitter->is_active) {
            $this->writer->toUser(
                type: $type,
                document: $event->document,
                user: $submitter,
                movement: $event->movement,
            );
        }
    }
}
