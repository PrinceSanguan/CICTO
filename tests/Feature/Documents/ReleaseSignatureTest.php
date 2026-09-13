<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Actions\Documents\StoreDocumentFile;
use App\Actions\Documents\TransitionDocument;
use App\Enums\DocumentPriority;
use App\Enums\MovementAction;
use App\Enums\SignatureMethod;
use App\Events\DocumentTransitioned;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentSignature;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §15 + §9. The handoff signature: an office signing the exact version it is
 * releasing, at the moment it releases it.
 *
 * The rule the whole feature hangs on is that signing is OFFERED, never
 * required -- so half of this file is about a forward with no signature
 * behaving exactly as it did before any of this existed.
 */
class ReleaseSignatureTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /** A 1x1 PNG, as the canvas would hand it over. */
    private function drawnMark(): string
    {
        return 'data:image/png;base64,'.base64_encode(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );
    }

    /**
     * A document sitting on the MPDO admin's desk with a real file attached,
     * plus a second office to send it to.
     *
     * @return array{Document, User, Office}
     */
    private function onADesk(): array
    {
        Storage::fake('documents');

        $office = $this->office('MPDO');
        $next = $this->office('TREA', 'Treasury Office');
        $clerk = $this->staff($office);
        $admin = $this->admin($office);

        $document = app(RegisterDocument::class)->handle(
            title: 'Purchase request',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $clerk,
            upload: UploadedFile::fake()->createWithContent('pr.pdf', '%PDF-1.4 original'),
        );

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        return [$document->refresh(), $admin, $next];
    }

    /**
     * password.confirm guards the signing paths. Every test here is about what
     * the signature does, not about that gate -- which has its own test below.
     */
    private function signedIn(User $user): self
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    /**
     * Walk the folder back to the office it came from.
     *
     * `returned` would read better, but DocumentWorkflow no longer allows it --
     * the client removed everything except forward, receive and complete -- so
     * a round trip is now literally a forward back.
     */
    private function sendBack(Document $document, Office $from, User $to): void
    {
        app(TransitionDocument::class)->handle(
            document: $document->refresh(),
            action: MovementAction::Forwarded,
            actor: $this->admin($from),
            toOfficeId: $to->office_id,
            expectedMovementId: $document->refresh()->openMovement->id,
        );
    }

    /** @return array<string, mixed> */
    private function forwardPayload(Document $document, Office $to, bool $sign): array
    {
        return array_merge([
            'action' => MovementAction::Forwarded->value,
            'to_office_ids' => [$to->id],
            'expected_movement_id' => $document->openMovement->id,
        ], $sign ? [
            'signature_method' => SignatureMethod::Drawn->value,
            'signature_image' => $this->drawnMark(),
        ] : []);
    }

    public function test_an_office_can_sign_the_version_it_is_releasing_and_send_it_in_one_submit(): void
    {
        [$document, $admin, $next] = $this->onADesk();
        $file = $document->currentFile()->first();

        $this->signedIn($admin)
            ->post(route('documents.transitions.store', $document), $this->forwardPayload($document, $next, sign: true))
            ->assertRedirect();

        $signature = DocumentSignature::query()->where('document_id', $document->id)->sole();

        $this->assertSame(DocumentSignature::PURPOSE_RELEASE, $signature->purpose);
        $this->assertSame($admin->id, $signature->user_id);

        // The binding the whole feature exists for.
        $this->assertSame($file->id, $signature->document_file_id);
        $this->assertSame($file->checksum_sha256, $signature->document_hash_sha256);
        $this->assertTrue($signature->isValid());

        // And the folder actually moved.
        $this->assertSame($next->id, $document->refresh()->openMovement->to_office_id);
    }

    /**
     * The signature has to name the office that RELEASED the document, not the
     * one it landed in -- which is what signing before the forward buys, and
     * what the document page reads to answer "has this office signed?".
     */
    public function test_the_signature_binds_to_the_leg_that_was_open_at_the_releasing_office(): void
    {
        [$document, $admin, $next] = $this->onADesk();
        $releasingLeg = $document->openMovement;

        $this->signedIn($admin)
            ->post(route('documents.transitions.store', $document), $this->forwardPayload($document, $next, sign: true))
            ->assertRedirect();

        $signature = DocumentSignature::query()->where('document_id', $document->id)->sole();

        $this->assertSame($releasingLeg->id, $signature->document_movement_id);

        $leg = DocumentMovement::query()->findOrFail($signature->document_movement_id);
        $this->assertSame($admin->office_id, $leg->to_office_id);
        $this->assertNotSame($next->id, $leg->to_office_id);
    }

    public function test_forwarding_without_a_signature_still_works_and_records_none(): void
    {
        [$document, $admin, $next] = $this->onADesk();

        $this->signedIn($admin)
            ->post(route('documents.transitions.store', $document), $this->forwardPayload($document, $next, sign: false))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($next->id, $document->refresh()->openMovement->to_office_id);
        $this->assertSame(0, DocumentSignature::query()->where('document_id', $document->id)->count());
    }

    /**
     * A release signature is a statement about a handoff, so there has to BE a
     * handoff. Attaching one to an approval would write a row claiming the
     * folder was released to an office it never went to.
     */
    public function test_a_signature_cannot_ride_along_with_an_action_that_is_not_a_forward(): void
    {
        [$document, $admin] = $this->onADesk();

        $this->signedIn($admin)
            ->post(route('documents.transitions.store', $document), [
                // A receipt, not a handoff. `approved` would have made the same
                // point but is unreachable -- DocumentWorkflow dropped the
                // approval step -- so `act` would refuse it before validation.
                'action' => MovementAction::Received->value,
                'expected_movement_id' => $document->openMovement->id,
                'signature_method' => SignatureMethod::Drawn->value,
                'signature_image' => $this->drawnMark(),
            ])
            ->assertSessionHasErrors('signature_method');

        $this->assertSame(0, DocumentSignature::query()->count());
    }

    public function test_a_drawn_signature_with_no_mark_is_refused_before_anything_moves(): void
    {
        [$document, $admin, $next] = $this->onADesk();

        $this->signedIn($admin)
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Forwarded->value,
                'to_office_ids' => [$next->id],
                'expected_movement_id' => $document->openMovement->id,
                'signature_method' => SignatureMethod::Drawn->value,
            ])
            ->assertSessionHasErrors('signature_image');

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame($admin->office_id, $document->refresh()->openMovement->to_office_id);
    }

    /**
     * One submit, one transaction. If the forward fails after the signature was
     * written, the signature must not survive -- otherwise the document page
     * would show a release signature for a release that never happened.
     */
    public function test_a_failed_forward_takes_the_signature_down_with_it(): void
    {
        [$document, $admin, $next] = $this->onADesk();

        // The event fires inside TransitionDocument's transaction, so a
        // listener that throws is a failure at exactly the point that matters:
        // after SignDocument has committed its row to the outer transaction.
        Event::listen(DocumentTransitioned::class, function (): void {
            throw new RuntimeException('the ledger write failed');
        });

        try {
            $this->signedIn($admin)->post(
                route('documents.transitions.store', $document),
                $this->forwardPayload($document, $next, sign: true),
            );
        } catch (RuntimeException) {
            // Whether the harness renders this or rethrows it, the database is
            // what the test is about.
        }

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame($admin->office_id, $document->refresh()->openMovement->to_office_id);
    }

    /**
     * Signing while forwarding is the same legal act as signing on its own, so
     * it cannot be the cheaper one to make -- otherwise this is simply the way
     * to sign without proving who you are.
     */
    public function test_signing_while_forwarding_needs_a_confirmed_password(): void
    {
        [$document, $admin, $next] = $this->onADesk();

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), $this->forwardPayload($document, $next, sign: true))
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame($admin->office_id, $document->refresh()->openMovement->to_office_id);
    }

    /** ...and an ordinary forward must not be dragged through that gate. */
    public function test_forwarding_without_a_signature_does_not_ask_for_a_password(): void
    {
        [$document, $admin, $next] = $this->onADesk();

        $this->actingAs($admin)
            ->post(route('documents.transitions.store', $document), $this->forwardPayload($document, $next, sign: false))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($next->id, $document->refresh()->openMovement->to_office_id);
    }

    /**
     * The same person may approve AND release the same version. They are
     * different attestations, which is why release is its own purpose rather
     * than a second `approval` row the (file, user, purpose) unique index would
     * refuse.
     */
    public function test_approval_and_release_can_both_be_signed_on_the_same_version(): void
    {
        [$document, $admin, $next] = $this->onADesk();

        $this->signedIn($admin)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Typed->value,
                'typed_name' => $admin->name,
            ])
            ->assertSessionHasNoErrors();

        $this->signedIn($admin)
            ->post(route('documents.transitions.store', $document), $this->forwardPayload($document, $next, sign: true))
            ->assertSessionHasNoErrors();

        $purposes = DocumentSignature::query()
            ->where('document_id', $document->id)
            ->orderBy('id')
            ->pluck('purpose')
            ->all();

        $this->assertSame(
            [DocumentSignature::PURPOSE_APPROVAL, DocumentSignature::PURPOSE_RELEASE],
            $purposes,
        );
    }

    /** The same version cannot be released twice by the same person. */
    public function test_a_version_cannot_be_released_twice_by_the_same_person(): void
    {
        [$document, $admin, $next] = $this->onADesk();

        $this->signedIn($admin)
            ->post(route('documents.transitions.store', $document), $this->forwardPayload($document, $next, sign: true))
            ->assertSessionHasNoErrors();

        $this->sendBack($document, $next, $admin);

        // Same desk, same file, same person -- and already released once.
        $this->assertFalse($admin->can('signRelease', $document->refresh()));
    }

    /** A new version is a new thing to attest to, so it can be released again. */
    public function test_a_new_version_can_be_released_again(): void
    {
        [$document, $admin, $next] = $this->onADesk();

        $this->signedIn($admin)
            ->post(route('documents.transitions.store', $document), $this->forwardPayload($document, $next, sign: true))
            ->assertSessionHasNoErrors();

        $this->sendBack($document, $next, $admin);

        app(StoreDocumentFile::class)->handle(
            document: $document->refresh(),
            upload: UploadedFile::fake()->createWithContent('pr-v2.pdf', '%PDF-1.4 corrected'),
            uploader: $admin,
            replaceReason: 'Canvass attached.',
        );

        $this->assertTrue($admin->can('signRelease', $document->refresh()));
    }

    /**
     * Client decision, 2026-09-10: the handoff signature is not gated on
     * Role::Admin the way approval is. In practice DocumentPolicy::view still
     * narrows it to the office's Admins plus the document's own submitter.
     */
    public function test_a_plain_user_holding_their_own_document_may_sign_the_release(): void
    {
        Storage::fake('documents');

        $office = $this->office('MPDO');
        $clerk = $this->staff($office);

        $document = app(RegisterDocument::class)->handle(
            title: 'Purchase request',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $clerk,
            upload: UploadedFile::fake()->createWithContent('pr.pdf', '%PDF-1.4 original'),
        );

        // The folder is still parked at the submitter's own office.
        $this->assertTrue($clerk->can('signRelease', $document->refresh()));

        // Approval remains an Admin-only decision, and stays refused.
        $this->assertFalse($clerk->can('sign', $document));
    }

    /** Nobody signs for an office that is not holding the folder. */
    public function test_an_office_that_does_not_hold_the_document_cannot_sign_its_release(): void
    {
        [$document, , $next] = $this->onADesk();

        $this->assertFalse($this->admin($next)->can('signRelease', $document));
    }

    /** With no file there is no hash, so there is nothing to bind a signature to. */
    public function test_a_document_with_no_file_cannot_be_released_with_a_signature(): void
    {
        $office = $this->office('MPDO');
        $clerk = $this->staff($office);
        $document = $this->registerDocument($office, $clerk);

        $this->assertFalse($clerk->can('signRelease', $document->refresh()));
    }

    /**
     * Client report, 2026-09-13: "dapat makaka perma ako then papunta sa next
     * na office". On a routed document every office moves the folder on by
     * pressing Received, so the pad inside the Forward panel never opens. The
     * release has to be signable on its own, at the origin and at every stop.
     */
    public function test_each_office_on_a_route_can_sign_the_release_before_receiving_it_onward(): void
    {
        Storage::fake('documents');

        $origin = $this->office('OCCR', 'Office of the City Civil Registrar');
        $audit = $this->office('COA', 'Commission on Audit');
        $pio = $this->office('PIO', 'Public Information Office');
        $clerk = $this->staff($origin);

        $document = app(RegisterDocument::class)->handle(
            title: 'Birth certificate endorsement',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $origin,
            creator: $clerk,
            upload: UploadedFile::fake()->createWithContent('endorsement.pdf', '%PDF-1.4 original'),
            routeOfficeIds: [$audit->id, $pio->id],
        );

        $genesis = $document->openMovement;

        $this->signedIn($clerk)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Drawn->value,
                'image' => $this->drawnMark(),
                'purpose' => DocumentSignature::PURPOSE_RELEASE,
            ])
            ->assertSessionHasNoErrors();

        $signature = DocumentSignature::query()->where('document_id', $document->id)->sole();
        $this->assertSame(DocumentSignature::PURPOSE_RELEASE, $signature->purpose);
        $this->assertSame($genesis->id, $signature->document_movement_id);

        $this->actingAs($clerk)
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Received->value,
                'expected_movement_id' => $genesis->id,
            ])
            ->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame($audit->id, $document->openMovement->to_office_id);

        // The folder has left the origin, so the origin cannot sign for it any
        // more -- and the office now holding it can.
        $this->assertFalse($clerk->can('signRelease', $document));

        $auditor = $this->staff($audit);

        $this->signedIn($auditor)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Drawn->value,
                'image' => $this->drawnMark(),
                'purpose' => DocumentSignature::PURPOSE_RELEASE,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, DocumentSignature::query()->where('document_id', $document->id)->count());
    }
}
