<?php

namespace Tests\Feature;

use App\Enums\MovementAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Controllers confirm their work with back()->with('toast', [...]).
 *
 * That reaches the user only if the session key is bridged onto Inertia's own
 * flash channel: the client listens on router.on('flash'), which Inertia fires
 * from the top-level `page.flash` key, NOT from props. Without the bridge every
 * confirmation in the application -- registered, signed, archived, uploaded,
 * backup complete -- was written to the session and discarded unread.
 *
 * Tested as two halves rather than one round trip, because the HTTP test client
 * does not age flash data across calls the way a browser does, and a test that
 * depends on that would pass or fail for reasons unrelated to this code.
 */
class FlashToastTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_a_toast_in_the_session_reaches_the_pages_flash_channel(): void
    {
        // Half one: the bridge in HandleInertiaRequests.
        $page = $this->withSession(['toast' => ['type' => 'success', 'message' => 'Archived.']])
            ->get(route('privacy'))
            ->viewData('page');

        $this->assertArrayHasKey(
            'flash',
            $page,
            'page.flash is missing, so router.on("flash") never fires.',
        );
        $this->assertSame('success', $page['flash']['toast']['type']);
        $this->assertSame('Archived.', $page['flash']['toast']['message']);
    }

    public function test_a_page_with_nothing_to_say_carries_no_flash(): void
    {
        $page = $this->actingAs($this->admin($this->office()))
            ->get(route('dashboard'))
            ->viewData('page');

        // Inertia omits the key when the bag is empty and only fires its event
        // when it is not, so a quiet page cannot re-show the previous toast.
        $this->assertArrayNotHasKey('flash', $page);
    }

    public function test_the_controllers_actually_flash_a_toast(): void
    {
        // Half two: the confirmations exist to be bridged.
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Received->value,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast.type', 'success');

        foreach ([
            MovementAction::Completed,
        ] as $action) {
            $document->refresh();
            $this->actingAs($admin)
                ->post(route('documents.transitions.store', $document), [
                    'action' => $action->value,
                    'expected_movement_id' => $document->openMovement->id,
                ])
                ->assertSessionHas('toast.type', 'success');
        }

        $this->actingAs($admin)
            ->post(route('documents.archive', $document->fresh()))
            ->assertSessionHas('toast.type', 'success');
    }
}
