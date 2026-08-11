<?php

namespace Tests\Feature;

use App\Actions\Documents\ArchiveDocument;
use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Controllers confirm their work with back()->with('toast', [...]).
 *
 * That only reaches the user if HandleInertiaRequests shares the session key.
 * It did not, so every confirmation in the application -- registered, signed,
 * archived, uploaded, backup complete -- was written to the session and
 * discarded unread. Nothing failed; the user simply never saw a response.
 */
class FlashToastTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_a_flashed_toast_reaches_the_page_props(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $clerk = $this->staff($office);
        $document = $this->registerDocument($office, $clerk);

        $this->actingAs($admin)->post(route('documents.transitions.store', $document), [
            'action' => MovementAction::Received->value,
            'expected_movement_id' => $document->openMovement->id,
        ])->assertRedirect();

        $flash = $this->actingAs($admin)
            ->get(route('documents.show', $document))
            ->viewData('page')['props']['flash'];

        $this->assertIsArray($flash);
        $this->assertSame('success', $flash['toast']['type']);
        $this->assertNotEmpty($flash['toast']['message']);
    }

    public function test_the_flash_key_is_always_present_even_when_empty(): void
    {
        $office = $this->office();

        // The React hook reads props.flash.toast. If the key vanished on pages
        // with nothing to say, the hook would have to guard against undefined
        // on every render.
        $props = $this->actingAs($this->admin($office))
            ->get(route('dashboard'))
            ->viewData('page')['props'];

        $this->assertArrayHasKey('flash', $props);
        $this->assertNull($props['flash']['toast']);
    }

    public function test_the_archive_confirmation_is_flashed(): void
    {
        $office = $this->office();
        $admin = $this->admin($office);
        $document = $this->registerDocument($office, $this->staff($office));

        foreach ([
            MovementAction::Received,
            MovementAction::Approved,
            MovementAction::Completed,
        ] as $action) {
            $document->refresh();
            app(TransitionDocument::class)->handle(
                document: $document,
                action: $action,
                actor: $admin,
                expectedMovementId: $document->openMovement?->id,
            );
        }

        $this->actingAs($admin)
            ->post(route('documents.archive', $document->fresh()))
            ->assertSessionHas('toast.type', 'success');

        app(ArchiveDocument::class)->restore($document->fresh(), $admin);
    }
}
