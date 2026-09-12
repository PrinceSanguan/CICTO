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
        if ($event->action === MovementAction::Rejected) {
            $this->dispatchRejection($event);

            return;
        }

        $movement = $event->movement;

        $type = match ($event->action) {
            MovementAction::Registered => NotificationType::Assigned,
            MovementAction::Returned => NotificationType::Returned,
            MovementAction::Forwarded => NotificationType::Forwarded,
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
     * A rejection is the one transition that has to notify BACKWARDS.
     *
     * Every other trigger tells the office the folder just arrived at. A
     * rejection moves the folder nowhere -- TransitionDocument keeps custody
     * where it already was -- so `from_office_id === to_office_id` and the
     * arrival guard above would drop it on the floor. Meanwhile the document is
     * terminal: the route is torn down, the folder stops, and the people who
     * were waiting on it are the ones who will never hear another thing about
     * it unless they are told now.
     *
     * So it goes to the ORIGINATING office -- who filed it and own the control
     * number -- plus the submitter by name, because a clerk can file against an
     * office they do not belong to. Both are guaranteed to pass
     * DocumentPolicy::view (originating office, and author), so neither gets the
     * dead bell fanOutToOffice exists to avoid. A submitter who is a member of
     * the originating office is covered by both calls and notified once: the two
     * writes share a dedupe key, and unique(user_id, dedupe_key) collapses them.
     *
     * The rejecting office is not told -- they are the ones who pressed it.
     */
    private function dispatchRejection(DocumentTransitioned $event): void
    {
        $this->writer->fanOutToOffice(
            type: NotificationType::Rejected,
            document: $event->document,
            officeId: $event->document->originating_office_id,
            movement: $event->movement,
            except: $event->actor,
        );

        $submitter = $event->document->creator;

        if ($submitter !== null && $submitter->id !== $event->actor->id && $submitter->is_active) {
            $this->writer->toUser(
                type: NotificationType::Rejected,
                document: $event->document,
                user: $submitter,
                movement: $event->movement,
            );
        }
    }
}
