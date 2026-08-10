<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\TransitionDocument;
use App\Enums\MovementAction;
use App\Models\Document;
use App\Models\DocumentFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * A write path must never be weaker than the read path on the same record.
 *
 * Adversarial review found two places where it was: act() and uploadVersion()
 * both authorised on "is your office holding this folder", which is plain
 * office_id equality and therefore true for every clerk in the office --
 * including the ones DocumentPolicy::view() refuses. A clerk could re-route or
 * append a version to a confidential document they could not open, walking
 * sequential ids to reach every folder currently in their office.
 */
class WritePathAuthorizationTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    public function test_a_clerk_cannot_reroute_a_document_they_cannot_open(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $hrmo = $this->office('HRMO', 'HR');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        // Bob is a plain clerk in the office now holding it, and did not
        // create it.
        $bob = $this->staff($mto);
        $document->refresh();

        $this->assertFalse($bob->can('view', $document));
        $this->assertFalse(
            $bob->can('act', [$document, MovementAction::Forwarded]),
            'A user who cannot read the document must not be able to move it.',
        );

        $this->actingAs($bob)
            ->get(route('documents.show', $document))
            ->assertForbidden();

        $this->actingAs($bob)
            ->post(route('documents.transitions.store', $document), [
                'action' => 'forwarded',
                'to_office_id' => $hrmo->id,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertForbidden();

        // The folder has not moved.
        $this->assertSame($mto->id, $document->fresh()->openMovement->to_office_id);
    }

    public function test_a_clerk_cannot_mark_received_a_document_they_cannot_open(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $bob = $this->staff($mto);

        // Received is not a "decision", so it never hit the Admin gate.
        $this->assertFalse($bob->can('act', [$document->fresh(), MovementAction::Received]));
    }

    public function test_a_clerk_cannot_upload_a_version_to_a_document_they_cannot_open(): void
    {
        Storage::fake('documents');

        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $bob = $this->staff($mto);
        $document->refresh();

        $this->assertFalse($bob->can('uploadVersion', $document));

        $this->actingAs($bob)
            ->post(route('documents.files.store', $document), [
                'file' => UploadedFile::fake()->create('planted.pdf', 20, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertSame(0, DocumentFile::query()->where('uploaded_by_id', $bob->id)->count());
    }

    public function test_the_office_admin_can_still_do_both(): void
    {
        Storage::fake('documents');

        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');
        $mtoAdmin = $this->admin($mto);

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        // The fix must not lock out the people who are supposed to act.
        $this->assertTrue($mtoAdmin->can('view', $document));
        $this->assertTrue($mtoAdmin->can('act', [$document, MovementAction::Approved]));
        $this->assertTrue($mtoAdmin->can('uploadVersion', $document));

        $this->actingAs($mtoAdmin)
            ->post(route('documents.files.store', $document), [
                'file' => UploadedFile::fake()->create('revised.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect();
    }

    public function test_the_read_path_and_the_write_path_agree_for_every_role(): void
    {
        $mpdo = $this->office('MPDO');
        $mto = $this->office('MTO', 'Treasury');

        $document = $this->registerDocument($mpdo, $this->staff($mpdo));

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Forwarded,
            actor: $this->admin($mpdo),
            toOfficeId: $mto->id,
            expectedMovementId: $document->openMovement->id,
        );

        $document->refresh();

        $candidates = [
            $this->staff($mto),
            $this->admin($mto),
            $this->staff($mpdo),
            $this->admin($mpdo),
            $this->superAdmin(),
        ];

        foreach ($candidates as $user) {
            if ($user->can('view', $document)) {
                continue;
            }

            // Cannot read => must not be able to write, in any form.
            foreach (MovementAction::cases() as $action) {
                $this->assertFalse(
                    $user->can('act', [$document, $action]),
                    "A user who cannot view the document was allowed to {$action->value} it.",
                );
            }

            $this->assertFalse($user->can('uploadVersion', $document));
            $this->assertFalse($user->can('comment', $document));

            // And the list must not surface it either.
            $this->assertFalse(
                Document::query()->visibleTo($user)->whereKey($document->id)->exists(),
                'A document that cannot be opened must not appear in the list.',
            );
        }
    }
}
