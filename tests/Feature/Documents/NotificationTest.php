<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Enums\NotificationType;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_forwarding_notifies_the_receiving_office_but_not_the_actor(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $sender = $this->admin($mpdo);
        $receiverA = $this->admin($mto);
        $receiverB = $this->admin($mto);
        $clerkAtDestination = $this->staff($mto);
        $bystander = $this->staff($this->office('HRMO', 'HR'));

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $sender,
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $forwarded = Notification::query()->where('type', NotificationType::Forwarded);

        $this->assertSame(1, (clone $forwarded)->where('user_id', $receiverA->id)->count());
        $this->assertSame(1, (clone $forwarded)->where('user_id', $receiverB->id)->count());
        $this->assertSame(0, (clone $forwarded)->where('user_id', $sender->id)->count());
        $this->assertSame(0, Notification::query()->where('user_id', $bystander->id)->count());

        // A plain clerk cannot open another person's document (§2), so telling
        // them about it would only produce a bell that 403s.
        $this->assertSame(0, Notification::query()->where('user_id', $clerkAtDestination->id)->count());

        $notification = (clone $forwarded)->where('user_id', $receiverA->id)->first();
        $this->assertSame(NotificationType::Forwarded, $notification->type);
        $this->assertSame($document->control_number, $notification->control_number);
    }

    public function test_a_same_office_decision_does_not_spam_your_own_colleagues(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $this->staff($office);

        $document = $this->registerDocument($office, $this->staff($office));

        // Registration legitimately notifies the office. What must NOT happen is
        // a second wave when someone in that same office acts on it.
        $baseline = Notification::query()->count();

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        $this->assertSame($baseline, Notification::query()->count());
    }

    public function test_an_inactive_user_receives_nothing(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $inactive = $this->staff($mto);
        $inactive->forceFill(['is_active' => false])->save();

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $this->assertSame(0, Notification::query()->where('user_id', $inactive->id)->count());
    }

    public function test_the_deadline_sweep_is_idempotent(): void
    {
        $office = $this->office();
        $this->admin($office);

        Carbon::setTestNow('2026-08-01 08:00:00');
        $this->registerDocument($office, $this->staff($office), $this->documentType(1));

        Carbon::setTestNow('2026-08-10 08:00:00');

        $this->artisan('cicto:notify-deadlines')->assertSuccessful();
        $afterFirst = Notification::query()->count();

        $this->artisan('cicto:notify-deadlines')->assertSuccessful();

        // unique(user_id, dedupe_key) plus insertOrIgnore means re-running the
        // sweep is a no-op rather than a duplicate or a constraint violation.
        $this->assertGreaterThan(0, $afterFirst);
        $this->assertSame($afterFirst, Notification::query()->count());
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $office = $this->office();
        $this->admin($office);

        Carbon::setTestNow('2026-08-01 08:00:00');
        $this->registerDocument($office, $this->staff($office), $this->documentType(1));
        Carbon::setTestNow('2026-08-10 08:00:00');

        $baseline = Notification::query()->count();

        $this->artisan('cicto:notify-deadlines --dry-run')->assertSuccessful();

        $this->assertSame($baseline, Notification::query()->count());
        $this->assertSame(0, Notification::query()->where('type', NotificationType::Overdue)->count());
    }

    public function test_a_notification_failure_does_not_roll_back_the_movement(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $this->staff($mto);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        // The writer is what the listener calls; make it explode.
        $this->swap(NotificationWriter::class, new class extends NotificationWriter
        {
            public function fanOutToOffice(
                NotificationType $type,
                Document $document,
                ?int $officeId,
                ?DocumentMovement $movement = null,
                ?User $except = null,
                ?string $dedupeSuffix = null,
            ): int {
                throw new \RuntimeException('notification backend is down');
            }
        });

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        // The movement is the business record. A broken bell must never undo it.
        $document->refresh();
        $this->assertSame($mto->id, $document->openMovement->to_office_id);
        $this->assertSame(2, DocumentMovement::query()->where('document_id', $document->id)->count());
    }

    public function test_the_unread_count_is_shared_and_the_deep_link_marks_it_read(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $receiver = $this->admin($mto);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $notification = Notification::query()->where('user_id', $receiver->id)->firstOrFail();
        $this->assertFalse($notification->isRead());

        $this->actingAs($receiver)
            ->get(route('notifications.go', $notification))
            ->assertRedirect(route('documents.show', $document));

        $this->assertTrue($notification->fresh()->isRead());
    }

    public function test_a_notification_belonging_to_someone_else_is_refused(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $receiver = $this->admin($mto);
        $stranger = $this->admin($this->office('HRMO', 'HR'));

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $notification = Notification::query()->where('user_id', $receiver->id)->firstOrFail();

        $this->actingAs($stranger)
            ->get(route('notifications.go', $notification))
            ->assertForbidden();
    }

    public function test_registering_a_document_notifies_the_receiving_office(): void
    {
        $office = $this->office('MPDO');
        $clerk = $this->staff($office);
        $officeAdmin = $this->admin($office);

        $document = $this->registerDocument($office, $clerk);

        // §12's first trigger: "newly assigned".
        $notification = Notification::query()->where('user_id', $officeAdmin->id)->first();

        $this->assertNotNull($notification, 'Registration must notify the office that now holds the document.');
        $this->assertSame(NotificationType::Assigned, $notification->type);
        $this->assertSame($document->control_number, $notification->control_number);

        // The submitter does not need telling about their own submission.
        $this->assertSame(0, Notification::query()->where('user_id', $clerk->id)->count());
    }
}
