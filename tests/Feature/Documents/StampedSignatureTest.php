<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Actions\Documents\SignDocument;
use App\Actions\Documents\StoreDocumentFile;
use App\Actions\Documents\TransitionDocument;
use App\Enums\DocumentPriority;
use App\Enums\MovementAction;
use App\Enums\SignatureMethod;
use App\Exceptions\AlreadySignedException;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §15, the 2026-09-20 change: the mark may be PRINTED ONTO THE PAGE.
 *
 * The stamped bytes are composed by the signer's browser, so nothing here can
 * test that the picture landed in the right place -- that is pdf-lib's job and
 * the browser's. What these tests pin down is the part the server owns, and
 * every one of them is a way the record could start lying:
 *
 *   - the signature keeps binding to the version that was READ, not the one
 *     stamping produced, or the tamper check ends up verifying its own output;
 *   - the produced version is appended and attributed, never written over;
 *   - a half-arrived stamp is refused rather than half-recorded;
 *   - signing twice is still refused, even though stamping now moves the
 *     current version out from under the old guard.
 */
class StampedSignatureTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /** A document under review at its own office, with a PDF attached. */
    private function reviewable(string $content = '%PDF-1.4 original'): Document
    {
        Storage::fake('documents');

        $office = $this->office('MPDO');
        $clerk = $this->staff($office);
        $admin = $this->admin($office);

        $document = app(RegisterDocument::class)->handle(
            title: 'Purchase request',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $clerk,
            upload: UploadedFile::fake()->createWithContent('pr.pdf', $content),
        );

        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        return $document->refresh();
    }

    /** The 1x1 PNG the pad would produce. */
    private function pngDataUrl(): string
    {
        return 'data:image/png;base64,'.base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ));
    }

    /** What the browser would post back after drawing the mark on page 2. */
    private function stampedUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'pr.pdf',
            '%PDF-1.4 stamped',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function placement(int $page = 2): array
    {
        return [
            'page' => $page,
            'x' => 0.6,
            'y' => 0.82,
            'width' => 0.25,
            'height' => 0.08,
        ];
    }

    public function test_stamping_appends_a_version_and_leaves_the_signed_one_alone(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);
        $signed = $document->currentFile()->first();

        $this->actingAs($admin)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Drawn->value,
                'image' => $this->pngDataUrl(),
                'stamped_pdf' => $this->stampedUpload(),
                'placement' => $this->placement(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $signature = DocumentSignature::query()->sole();
        $stamped = $signature->stampedFile;

        // The produced version is a NEW row, not a rewrite.
        $this->assertSame(2, DocumentFile::query()->count());
        $this->assertNotNull($stamped);
        $this->assertSame(2, $stamped->version);
        $this->assertSame($admin->id, $stamped->uploaded_by_id);
        $this->assertStringContainsString($signature->serial, (string) $stamped->replace_reason);
        $this->assertStringContainsString($admin->name, (string) $stamped->replace_reason);

        // And the version that was read is untouched and still on disk.
        $this->assertSame('%PDF-1.4 original', Storage::disk('documents')->get($signed->path));
        $this->assertSame('%PDF-1.4 stamped', Storage::disk('documents')->get($stamped->path));
    }

    /**
     * The binding is the whole point of §15, and stamping is the one change
     * most likely to quietly break it: bind to the stamped file and the hash
     * covers a document containing the signature that attests to it.
     */
    public function test_the_signature_still_binds_to_the_version_that_was_read(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);
        $signed = $document->currentFile()->first();

        $this->actingAs($admin)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Drawn->value,
                'image' => $this->pngDataUrl(),
                'stamped_pdf' => $this->stampedUpload(),
                'placement' => $this->placement(),
            ])
            ->assertSessionHasNoErrors();

        $signature = DocumentSignature::query()->sole();

        $this->assertSame($signed->id, $signature->document_file_id);
        $this->assertSame($signed->checksum_sha256, $signature->document_hash_sha256);
        $this->assertNotSame($signature->document_file_id, $signature->stamped_file_id);
        $this->assertTrue($signature->isValid(rehashBytes: true));
    }

    public function test_the_placement_is_recorded_as_fractions_of_the_page(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $this->actingAs($admin)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Drawn->value,
                'image' => $this->pngDataUrl(),
                'stamped_pdf' => $this->stampedUpload(),
                'placement' => $this->placement(page: 3),
            ])
            ->assertSessionHasNoErrors();

        $signature = DocumentSignature::query()->sole();

        $this->assertTrue($signature->isStamped());
        $this->assertSame(3, $signature->stamp_page);
        $this->assertSame(0.6, (float) $signature->stamp_x);
        $this->assertSame(0.82, (float) $signature->stamp_y);
        $this->assertSame(0.25, (float) $signature->stamp_width);
        $this->assertSame(0.08, (float) $signature->stamp_height);
    }

    /** A signature with no stamp is exactly what it always was. */
    public function test_signing_without_a_placement_records_no_stamp_and_no_version(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $this->actingAs($admin)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Drawn->value,
                'image' => $this->pngDataUrl(),
            ])
            ->assertSessionHasNoErrors();

        $signature = DocumentSignature::query()->sole();

        $this->assertFalse($signature->isStamped());
        $this->assertNull($signature->stamped_file_id);
        $this->assertSame(1, DocumentFile::query()->count());
    }

    /**
     * Half a stamp is worse than none: one without the other means the browser
     * failed partway, and recording either alone writes a row whose story does
     * not add up.
     */
    public function test_a_placement_without_a_file_is_refused(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $this->actingAs($admin)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Drawn->value,
                'image' => $this->pngDataUrl(),
                'placement' => $this->placement(),
            ])
            ->assertSessionHasErrors('stamped_pdf');

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame(1, DocumentFile::query()->count());
    }

    public function test_a_file_without_a_placement_is_refused(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $this->actingAs($admin)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Drawn->value,
                'image' => $this->pngDataUrl(),
                'stamped_pdf' => $this->stampedUpload(),
            ])
            ->assertSessionHasErrors('stamped_pdf');

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame(1, DocumentFile::query()->count());
    }

    /**
     * Only a real PDF may join the version list.
     *
     * A genuine UploadedFile rather than UploadedFile::fake(): the fake
     * reports a MIME type guessed from its NAME, so `pr.pdf` full of junk
     * sails through the very rule this test exists to prove. Built from a real
     * temp file, `mimetypes` sniffs the bytes, which is the behaviour
     * production gets.
     */
    public function test_a_stamped_copy_that_is_not_a_pdf_is_refused(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $path = tempnam(sys_get_temp_dir(), 'cicto-not-a-pdf');
        file_put_contents($path, 'not a pdf at all, just some text');

        try {
            $this->actingAs($admin)
                ->post(route('documents.signatures.store', $document), [
                    'method' => SignatureMethod::Drawn->value,
                    'image' => $this->pngDataUrl(),
                    'stamped_pdf' => new UploadedFile($path, 'pr.pdf', null, null, true),
                    'placement' => $this->placement(),
                ])
                ->assertSessionHasErrors('stamped_pdf');
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame(1, DocumentFile::query()->count());
    }

    /** There is nothing to draw on the page for a typed signature. */
    public function test_a_typed_signature_cannot_carry_a_stamp(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $this->actingAs($admin)
            ->post(route('documents.signatures.store', $document), [
                'method' => SignatureMethod::Typed->value,
                'typed_name' => $admin->name,
                'stamped_pdf' => $this->stampedUpload(),
                'placement' => $this->placement(),
            ])
            ->assertSessionHasErrors('stamped_pdf');

        $this->assertSame(0, DocumentSignature::query()->count());
    }

    /**
     * The guard stamping most easily defeats.
     *
     * Before this change "already signed" asked about the CURRENT version.
     * Stamping makes signing produce a new current version, so the second
     * attempt would look at a version nobody had signed and wave it through --
     * one person, two marks, same purpose.
     */
    public function test_signing_twice_is_still_refused_after_a_stamp_moved_the_current_version(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        $this->assertSame(2, DocumentFile::query()->count());

        $this->expectException(AlreadySignedException::class);

        app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );
    }

    /**
     * ...but a genuinely corrected upload is different content, and has to be
     * signable again by the same person.
     */
    public function test_a_real_new_upload_reopens_signing_for_someone_who_already_signed(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        app(StoreDocumentFile::class)->handle(
            document: $document->fresh(),
            upload: UploadedFile::fake()->createWithContent('pr.pdf', '%PDF-1.4 corrected'),
            uploader: $admin,
            replaceReason: 'Corrected figures',
        );

        $second = app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $this->assertSame(2, DocumentSignature::query()->count());
        $this->assertSame(3, $second->file->version);
    }

    /**
     * Two offices signing in turn is the ordinary case, and each one must
     * stamp the version the one before it produced -- which is how the marks
     * accumulate down the page the way they would on paper.
     */
    public function test_a_second_office_signs_the_version_the_first_one_stamped(): void
    {
        $document = $this->reviewable();
        $first = $this->admin($document->originatingOffice);

        app(SignDocument::class)->handle(
            document: $document,
            signer: $first,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        // A second Admin in the same office: a distinct person, same desk.
        $second = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $second,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(page: 1),
            stampedPdf: UploadedFile::fake()->createWithContent('pr.pdf', '%PDF-1.4 twice stamped'),
        );

        // Signed v2 -- the first office's stamped output -- and produced v3.
        $this->assertSame(2, $signature->file->version);
        $this->assertSame(3, $signature->stampedFile->version);
        $this->assertSame(3, DocumentFile::query()->count());
    }
}
