<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
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

        $notifications = Notification::query()->with(['user', 'document'])->get();

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

    public function test_a_plain_clerk_in_the_receiving_office_is_not_notified(): void
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

        // §2 limits a plain User to their own documents, so they have nothing to
        // do with a folder arriving for the office.
        $this->assertFalse($clerkAtDestination->can('view', $document));
        $this->assertSame(0, Notification::query()->where('user_id', $clerkAtDestination->id)->count());

        $this->assertTrue($adminAtDestination->can('view', $document));
        $this->assertSame(1, Notification::query()->where('user_id', $adminAtDestination->id)->count());
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
