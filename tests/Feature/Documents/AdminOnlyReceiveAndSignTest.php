<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Enums\SignatureMethod;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Client report, 2026-09-15, testing as clerk@cicto.test: "nakakapag esign po
 * yung user tsaka nakakapag recieve ng documents, dapat po sa admin lang yon".
 *
 * A User files, searches and tracks. Receiving, forwarding and signing are the
 * office Admin's -- which is also what the role table in the client's testing
 * guide has always said. The refusals go through HTTP, because a hidden button
 * is not a refusal.
 */
class AdminOnlyReceiveAndSignTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /** A 1x1 PNG, as the canvas would hand it over. */
    private function drawnMark(): string
    {
        return 'data:image/png;base64,'.base64_encode(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );
    }

    /** A document with a real file, still parked at the office that filed it. */
    private function filedBy(User $creator, Office $office): Document
    {
        return app(RegisterDocument::class)->handle(
            title: 'Barangay drainage clearance',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $creator,
            upload: UploadedFile::fake()->createWithContent('clearance.pdf', '%PDF-1.4 original'),
        )->refresh();
    }

    /** @return array<string, mixed> */
    private function pageFor(User $user, Document $document): array
    {
        return $this->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->viewData('page')['props']['document'];
    }

    public function test_a_clerk_is_shown_neither_the_receive_button_nor_the_signature_pad(): void
    {
        Storage::fake('documents');

        $office = $this->office('OCM', 'Office of the City Mayor');
        $clerk = $this->staff($office);
        $admin = $this->admin($office);
        $document = $this->filedBy($clerk, $office);

        $page = $this->pageFor($clerk, $document);
        $offered = array_column($page['available_actions'], 'value');

        $this->assertNotContains(MovementAction::Received->value, $offered);
        $this->assertNotContains(MovementAction::Forwarded->value, $offered);
        $this->assertFalse($page['can']['sign']);
        $this->assertFalse($page['can']['signRelease']);

        // The same document at the same moment, seen by the office's Admin.
        $page = $this->pageFor($admin, $document);
        $offered = array_column($page['available_actions'], 'value');

        $this->assertContains(MovementAction::Received->value, $offered);
        $this->assertContains(MovementAction::Forwarded->value, $offered);
        $this->assertTrue($page['can']['signRelease']);
    }

    public function test_a_clerk_is_refused_every_way_of_receiving_forwarding_or_signing(): void
    {
        Storage::fake('documents');

        $office = $this->office('OCM', 'Office of the City Mayor');
        $next = $this->office('MTO', 'Treasury');
        $this->admin($next);
        $clerk = $this->staff($office);
        $document = $this->filedBy($clerk, $office);
        $leg = $document->openMovement->id;

        $this->actingAs($clerk)
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Received->value,
                'expected_movement_id' => $leg,
            ])
            ->assertForbidden();

        $this->actingAs($clerk)
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Forwarded->value,
                'to_office_ids' => [$next->id],
                'expected_movement_id' => $leg,
            ])
            ->assertForbidden();

        // Sign & send, in one submit.
        $this->actingAs($clerk)
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Forwarded->value,
                'to_office_ids' => [$next->id],
                'expected_movement_id' => $leg,
                'signature_method' => SignatureMethod::Drawn->value,
                'signature_image' => $this->drawnMark(),
            ])
            ->assertForbidden();

        foreach ([DocumentSignature::PURPOSE_RELEASE, DocumentSignature::PURPOSE_APPROVAL] as $purpose) {
            $this->actingAs($clerk)
                ->post(route('documents.signatures.store', $document), [
                    'method' => SignatureMethod::Drawn->value,
                    'image' => $this->drawnMark(),
                    'purpose' => $purpose,
                ])
                ->assertForbidden();
        }

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame($leg, $document->fresh()->openMovement->id, 'Nothing the clerk sent may move the folder.');
    }

    /**
     * Not the §A6 separation-of-duties switch: receiving and releasing are
     * custody, not assent, so an Admin still does both on a document they filed
     * themselves -- with self-approval left at its default.
     */
    public function test_an_admin_still_receives_and_signs_the_release_of_their_own_document(): void
    {
        Storage::fake('documents');

        $office = $this->office('OCM', 'Office of the City Mayor');
        $admin = $this->admin($office);
        $document = $this->filedBy($admin, $office);

        $this->actingAs($admin)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Drawn->value,
                'image' => $this->drawnMark(),
                'purpose' => DocumentSignature::PURPOSE_RELEASE,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DocumentSignature::query()->where('user_id', $admin->id)->count());

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Received->value,
                'expected_movement_id' => $document->openMovement->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::UnderReview, $document->fresh()->status);
    }
}
