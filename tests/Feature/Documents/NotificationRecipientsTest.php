<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Enums\NotificationType;
use App\Models\Document;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * The invariant a notification system is easiest to get wrong on: telling
 * someone about a document they are not allowed to open.
 *
 * A bell that 403s when clicked is worse than no bell at all, so this asserts
 * the recipient set is a subset of the set that can view the document -- across
 * every trigger, not just the one that happened to be written first.
 */
class NotificationRecipientsTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_recipient_of_every_notification_can_open_the_document(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        // A realistic office: one admin and several clerks.
        $this->admin($mpdo);
        $this->staff($mpdo);
        $this->staff($mpdo);
        $this->admin($mto);
        $this->staff($mto);
        $this->staff($mto);

        $clerk = $this->staff($mpdo);
        $document = $this->registerDocument($mpdo, $clerk, $this->documentType(1));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        // Drive the swept triggers too.
        Carbon::setTestNow(now()->addDays(30));
        $this->artisan('cicto:notify-deadlines')->assertSuccessful();

        /*
         * And a return, then the resubmit that answers it, LAST.
         *
         * A return notifies the submitter BY NAME as well as the originating
         * office -- a different recipient set reached by a different code path,
         * which makes it exactly the kind of trigger this invariant exists to
         * catch. The resubmit then tells the office it goes back to.
         */
        app(TransitionDocument::class)->handle(
            document: $document->refresh(),
            action: MovementAction::Returned,
            actor: $this->admin($mto),
            remarks: 'Unsigned quotation.',
            expectedMovementId: $document->refresh()->openMovement->id,
        );

        app(TransitionDocument::class)->handle(
            document: $document->refresh(),
            action: MovementAction::Resubmitted,
            actor: $clerk,
            remarks: 'Signed now.',
            expectedMovementId: $document->refresh()->openMovement->id,
        );

        $notifications = Notification::query()->with(['user', 'document'])->get();

        foreach ([NotificationType::Returned, NotificationType::Resubmitted] as $type) {
            $this->assertTrue(
                $notifications->contains(fn (Notification $row) => $row->type === $type),
                "The {$type->value} trigger must have notified somebody.",
            );
        }

        $this->assertGreaterThan(0, $notifications->count(), 'Expected some notifications to exist.');

        foreach ($notifications as $notification) {
            $this->assertTrue(
                $notification->user->can('view', $notification->document),
                sprintf(
                    'User #%d (%s) was notified about %s but cannot open it.',
                    $notification->user->id,
                    $notification->user->role->value,
                    $notification->control_number,
                ),
            );
        }
    }

    public function test_a_plain_clerk_in_the_receiving_office_is_notified_too(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $clerkAtDestination = $this->staff($mto);
        $adminAtDestination = $this->admin($mto);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        /*
         * REVERSED DELIBERATELY, and the old name of this test is the point.
         *
         * It used to assert the clerk gets nothing, because row access followed
         * role and a clerk could not open a document they had not filed -- so a
         * notification would have been a bell that 403s. Row access now follows
         * office_id, and a clerk can open the folder that arrives for their
         * office. Receiving it is the Admin's since 2026-09-15, but the office
         * shares one view of its documents, so the clerk is still told.
         *
         * The invariant this file exists for is untouched: every recipient is
         * still someone who can open what they were told about, asserted
         * wholesale in the test above.
         */
        $this->assertTrue($clerkAtDestination->can('view', $document));
        $this->assertSame(1, Notification::query()->where('user_id', $clerkAtDestination->id)->count());

        $this->assertTrue($adminAtDestination->can('view', $document));
        $this->assertSame(1, Notification::query()->where('user_id', $adminAtDestination->id)->count());

        // Still bounded by office: a clerk somewhere else hears nothing.
        $elsewhere = $this->staff($this->office('HRMO', 'Human Resource'));

        $this->assertFalse($elsewhere->can('view', $document));
        $this->assertSame(0, Notification::query()->where('user_id', $elsewhere->id)->count());
    }

    public function test_following_a_notification_link_never_lands_on_a_403(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $this->staff($mto);
        $receiver = $this->admin($mto);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        foreach (Notification::query()->where('user_id', $receiver->id)->get() as $notification) {
            $user = User::query()->findOrFail($notification->user_id);

            $this->actingAs($user)
                ->get(route('notifications.go', $notification))
                ->assertRedirect(route('documents.show', $notification->document_id));

            $this->actingAs($user)
                ->get(route('documents.show', $notification->document_id))
                ->assertOk();
        }
    }
}
