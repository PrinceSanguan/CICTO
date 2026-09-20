<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Actions\Documents\SignDocument;
use App\Actions\Documents\StoreDocumentFile;
use App\Actions\Documents\TransitionDocument;
use App\Actions\Documents\UndoSignature;
use App\Enums\DocumentPriority;
use App\Enums\MovementAction;
use App\Enums\SignatureMethod;
use App\Exceptions\AlreadySignedException;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentSignature;
use App\Support\Presenters\DocumentPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
     * A stamped copy with DISTINCT bytes, for the tests where several offices
     * sign in turn.
     *
     * StoreDocumentFile dedupes a byte-identical upload back onto the current
     * version, so handing every office the same fixture quietly produces one
     * version instead of three -- and a chain test that never built a chain.
     */
    private function stampedUploadFor(string $office): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'pr.pdf',
            '%PDF-1.4 stamped by '.$office,
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

        /*
         * A genuinely DIFFERENT office, reached the way the folder really
         * travels. It used to be a second Admin at the same desk, which the
         * one-signature-per-office rule of 2026-09-20 correctly refuses -- and
         * refusing it is what the test below this one is for.
         */
        $next = $this->office('HRMO', 'Human Resource Office');

        app(TransitionDocument::class)->handle(
            document: $document->fresh(),
            action: MovementAction::Forwarded,
            actor: $first,
            toOfficeId: $next->id,
        );

        $second = $this->admin($next);

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

    /**
     * ONE SIGNATURE PER OFFICE, the client's rule of 2026-09-20.
     *
     * An office speaks with one voice on a document. Its head signing after
     * its clerk already did is two marks for one decision, and on a stamped
     * document it is two marks on the page.
     */
    public function test_a_second_person_from_the_same_office_may_not_sign(): void
    {
        $document = $this->reviewable();
        $office = $document->originatingOffice;

        app(SignDocument::class)->handle(
            document: $document,
            signer: $this->admin($office),
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $this->expectException(AlreadySignedException::class);

        app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $this->admin($office),
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );
    }

    /**
     * ...but a CORRECTED file reopens signing for that office, exactly as it
     * does for a person. "One per office" is not "one per office, ever".
     */
    public function test_a_corrected_upload_lets_the_same_office_sign_again(): void
    {
        $document = $this->reviewable();
        $office = $document->originatingOffice;

        app(SignDocument::class)->handle(
            document: $document,
            signer: $this->admin($office),
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        app(StoreDocumentFile::class)->handle(
            document: $document->fresh(),
            upload: UploadedFile::fake()->createWithContent('pr.pdf', '%PDF-1.4 corrected'),
            uploader: $this->admin($office),
            replaceReason: 'Corrected figures',
        );

        // A DIFFERENT person from the same office, to prove the reopening is
        // the office's and not just that one signer's.
        app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $this->admin($office),
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $this->assertSame(2, DocumentSignature::query()->count());
    }

    /**
     * The office is stored as an ID, not matched on the snapshotted name --
     * renaming an office must not hand it a second signature.
     */
    public function test_renaming_an_office_does_not_reopen_its_signature(): void
    {
        $document = $this->reviewable();
        $office = $document->originatingOffice;

        app(SignDocument::class)->handle(
            document: $document,
            signer: $this->admin($office),
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $this->assertSame($office->id, DocumentSignature::query()->sole()->office_id);

        $office->forceFill(['name' => 'Renamed Planning Office'])->save();

        $this->expectException(AlreadySignedException::class);

        app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $this->admin($office->fresh()),
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );
    }

    /**
     * §15 UNDO -- the client's request of 2026-09-20.
     *
     * Withdrawing takes the signature AND the version it stamped, so the
     * document reads exactly as it did before anybody signed. The version
     * that was SIGNED is never touched.
     */
    public function test_undoing_a_signature_removes_it_and_the_version_it_stamped(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);
        $original = $document->currentFile()->first();

        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        $stampedPath = $signature->stampedFile->path;
        $imagePath = $signature->image_path;

        $this->actingAs($admin)
            ->delete(route('documents.signatures.destroy', [$document, $signature]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame(1, DocumentFile::query()->count());
        $this->assertSame($original->id, $document->fresh()->currentFile()->first()->id);

        // The bytes go too, both of them.
        Storage::disk('documents')->assertMissing($stampedPath);
        Storage::disk('documents')->assertMissing($imagePath);

        // And the file that was signed is untouched.
        Storage::disk('documents')->assertExists($original->path);
        $this->assertSame('%PDF-1.4 original', Storage::disk('documents')->get($original->path));
    }

    /** Undoing frees the office's one slot, so it can sign again. */
    public function test_an_office_may_sign_again_after_undoing(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $this->actingAs($admin)
            ->delete(route('documents.signatures.destroy', [$document, $signature]))
            ->assertSessionHasNoErrors();

        app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $this->assertSame(1, DocumentSignature::query()->count());
    }

    /**
     * The condition the client named: undo works while the folder is still on
     * your desk, and stops the moment it moves on.
     */
    public function test_a_signature_cannot_be_undone_once_the_folder_has_moved_on(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $next = $this->office('HRMO', 'Human Resource Office');

        app(TransitionDocument::class)->handle(
            document: $document->fresh(),
            action: MovementAction::Forwarded,
            actor: $admin,
            toOfficeId: $next->id,
        );

        $this->actingAs($admin)
            ->delete(route('documents.signatures.destroy', [$document, $signature]))
            ->assertForbidden();

        $this->assertSame(1, DocumentSignature::query()->count());
    }

    /** One office may not withdraw another office's mark. */
    public function test_another_office_may_not_undo_your_signature(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $stranger = $this->admin($this->office('HRMO', 'Human Resource Office'));

        $this->actingAs($stranger)
            ->delete(route('documents.signatures.destroy', [$document, $signature]))
            ->assertForbidden();

        $this->assertSame(1, DocumentSignature::query()->count());
    }

    /**
     * A COLLEAGUE at the same office may, though -- the rule is about the
     * office, and the one person who signed may be on leave.
     */
    public function test_a_colleague_at_the_same_office_may_undo_it(): void
    {
        $document = $this->reviewable();
        $office = $document->originatingOffice;

        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $this->admin($office),
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $this->actingAs($this->admin($office))
            ->delete(route('documents.signatures.destroy', [$document, $signature]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DocumentSignature::query()->count());
    }

    /** The page only offers the button where the policy would allow it. */
    public function test_the_payload_says_whether_a_signature_can_be_undone(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $this->actingAs($admin)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('signatures.0.can_undo', true));

        $stranger = $this->admin($this->office('HRMO', 'Human Resource Office'));

        // A Super Admin can see the page but has no office's mark to withdraw.
        $this->actingAs($this->superAdmin())
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('signatures.0.can_undo', false));

        $this->assertNotNull($stranger);
    }

    /**
     * THE BUG OF 2026-09-20: every stamped signature read "Superseded" the
     * instant it was made.
     *
     * "Superseded" is derived from version order, and stamping appends the
     * signed copy as the next version -- so the signature's OWN output sat
     * above it and answered "yes, a newer version exists". The register showed
     * four marks on one folder, all amber, while nothing about the document
     * had changed. A mark on the page is not new content.
     */
    public function test_a_stamped_signature_is_not_superseded_by_its_own_stamp(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        // The stamped version really is there and really is newer...
        $this->assertSame(2, DocumentFile::query()->count());
        $this->assertSame(2, $signature->stampedFile->version);

        // ...and it still does not supersede the signature that produced it.
        $this->assertTrue($signature->isValid());
        $this->assertFalse($signature->fresh()->load('file')->isSuperseded());
    }

    /**
     * The screenshot that started this: four offices, every mark amber.
     *
     * Each office signs the version the one before it stamped, so under the
     * old rule every signature but the last was buried by the next office's
     * output, and the last was buried by its own. None of it was a change to
     * the document.
     */
    public function test_a_chain_of_offices_leaves_no_signature_superseded(): void
    {
        $document = $this->reviewable();
        $signer = $this->admin($document->originatingOffice);

        app(SignDocument::class)->handle(
            document: $document,
            signer: $signer,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        foreach ([['HRMO', 'Human Resource Office'], ['BAC', 'Bids and Awards Committee']] as [$code, $name]) {
            $next = $this->office($code, $name);

            app(TransitionDocument::class)->handle(
                document: $document->fresh(),
                action: MovementAction::Forwarded,
                actor: $signer,
                toOfficeId: $next->id,
            );

            $signer = $this->admin($next);

            app(SignDocument::class)->handle(
                document: $document->fresh(),
                signer: $signer,
                method: SignatureMethod::Drawn,
                drawnPng: $this->pngDataUrl(),
                placement: $this->placement(page: 1),
                stampedPdf: $this->stampedUploadFor($code),
            );
        }

        $signatures = DocumentSignature::query()->with('file')->get();

        $this->assertCount(3, $signatures);

        // Three marks really did build three versions on top of the original.
        $this->assertSame(4, DocumentFile::query()->count());

        foreach ($signatures as $signature) {
            $this->assertFalse(
                $signature->isSuperseded(),
                sprintf('%s signed v%d and should not read as superseded.',
                    $signature->signer_name,
                    (int) $signature->file?->version,
                ),
            );
        }
    }

    /**
     * ...and the amber badge still has to appear when it means something.
     * Somebody uploading a corrected file is exactly the case "superseded" was
     * added for: the mark is a true statement about a version nobody is
     * reading any more.
     */
    public function test_a_real_upload_still_supersedes_a_stamped_signature(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle(
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

        $signature = $signature->fresh()->load('file');

        // Still a true statement about v1 -- it just no longer describes the
        // file the next office will open.
        $this->assertTrue($signature->isValid());
        $this->assertTrue($signature->isSuperseded());
    }

    /**
     * The page does not call isSuperseded() per row -- it compares against a
     * max version the controller passes in, to keep the register off N+1. That
     * shortcut is where the bug actually reached the user, so it is pinned
     * against the model's answer rather than trusted to agree with it.
     */
    public function test_the_document_page_agrees_with_the_model_about_superseded(): void
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

        $this->actingAs($admin)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('signatures.0.superseded', false)
                ->where('signatures.0.valid', true));

        app(StoreDocumentFile::class)->handle(
            document: $document->fresh(),
            upload: UploadedFile::fake()->createWithContent('pr.pdf', '%PDF-1.4 corrected'),
            uploader: $admin,
            replaceReason: 'Corrected figures',
        );

        $this->actingAs($admin)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('signatures.0.superseded', true));
    }

    /**
     * And the printed certificate, which is the copy that leaves the building
     * and cannot be corrected once it has.
     */
    public function test_the_public_verify_page_does_not_call_a_fresh_stamp_superseded(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        // No session: this is the QR on the paper.
        $this->get(route('signatures.verify', $signature->serial))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('signature.superseded', false)
                ->where('signature.valid', true));
    }

    /**
     * A RETURN takes the marks off, client rule 2026-09-20 -- "babalik yung
     * version ng office before".
     *
     * The document goes back for correction as the clean copy it was before
     * anybody signed, and the offices sign again afterwards.
     */
    public function test_returning_a_document_withdraws_the_signatures_and_their_versions(): void
    {
        $document = $this->reviewable();
        $office = $document->originatingOffice;
        $admin = $this->admin($office);
        $original = $document->currentFile()->first();

        $next = $this->office('HRMO', 'Human Resource Office');

        // The first office signs, stamping v2, then sends it on.
        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        $stampedPath = $signature->stampedFile->path;
        $this->assertSame(2, DocumentFile::query()->count());

        app(TransitionDocument::class)->handle(
            document: $document->fresh(),
            action: MovementAction::Forwarded,
            actor: $admin,
            toOfficeId: $next->id,
        );

        // HRMO finds a problem and sends it back.
        $this->actingAs($this->admin($next))
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Returned->value,
                'remarks' => 'The attached figures do not match the request.',
                'expected_movement_id' => $document->fresh()->openMovement->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The mark and the version it stamped are both gone...
        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame(1, DocumentFile::query()->count());
        Storage::disk('documents')->assertMissing($stampedPath);

        // ...and the clean copy is what came back.
        $this->assertSame($original->id, $document->fresh()->currentFile()->first()->id);
        $this->assertSame('%PDF-1.4 original', Storage::disk('documents')->get($original->path));
    }

    /** Two offices' marks come off in one return, newest version first. */
    public function test_a_return_withdraws_every_live_signature(): void
    {
        $document = $this->reviewable();
        $first = $this->admin($document->originatingOffice);
        $next = $this->office('HRMO', 'Human Resource Office');
        $third = $this->office('LEGAL', 'Legal Office');

        app(SignDocument::class)->handle(
            document: $document,
            signer: $first,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        app(TransitionDocument::class)->handle(
            document: $document->fresh(),
            action: MovementAction::Forwarded,
            actor: $first,
            toOfficeId: $next->id,
        );

        app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $this->admin($next),
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(page: 1),
            stampedPdf: UploadedFile::fake()->createWithContent('pr.pdf', '%PDF-1.4 twice stamped'),
        );

        $this->assertSame(2, DocumentSignature::query()->count());
        $this->assertSame(3, DocumentFile::query()->count());

        app(TransitionDocument::class)->handle(
            document: $document->fresh(),
            action: MovementAction::Forwarded,
            actor: $this->admin($next),
            toOfficeId: $third->id,
        );

        $this->actingAs($this->admin($third))
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Returned->value,
                'remarks' => 'Wrong attachment entirely.',
                'expected_movement_id' => $document->fresh()->openMovement->id,
            ])
            ->assertSessionHasNoErrors();

        // Both marks, both stamped versions -- gone, leaving only the upload.
        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame(1, DocumentFile::query()->count());
        $this->assertSame(1, $document->fresh()->currentFile()->first()->version);
    }

    /**
     * A mark that a PREVIOUS correction already superseded is history, not a
     * live attestation, so a later return leaves it alone. Rewriting the past
     * is not what a return does.
     */
    public function test_a_return_leaves_already_superseded_signatures_alone(): void
    {
        $document = $this->reviewable();
        $office = $document->originatingOffice;
        $admin = $this->admin($office);

        app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        // A corrected upload supersedes that first mark.
        app(StoreDocumentFile::class)->handle(
            document: $document->fresh(),
            upload: UploadedFile::fake()->createWithContent('pr.pdf', '%PDF-1.4 corrected'),
            uploader: $admin,
            replaceReason: 'Corrected figures',
        );

        $next = $this->office('HRMO', 'Human Resource Office');

        app(TransitionDocument::class)->handle(
            document: $document->fresh(),
            action: MovementAction::Forwarded,
            actor: $admin,
            toOfficeId: $next->id,
        );

        $this->actingAs($this->admin($next))
            ->post(route('documents.transitions.store', $document), [
                'action' => MovementAction::Returned->value,
                'remarks' => 'Still not right.',
                'expected_movement_id' => $document->fresh()->openMovement->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            DocumentSignature::query()->count(),
            'The superseded mark describes a file replaced before this return.',
        );
    }

    /**
     * QA of the rule above: it depends on every stamped version still being
     * CLAIMED by a live signature row.
     *
     * Withdraw a signature and its stamped version goes with it
     * (UndoSignature::deleteStampedVersion). If it ever did not, the orphan
     * would answer to nobody, start counting as an upload, and quietly
     * supersede the office that signed before it -- the original bug, reached
     * by a different road.
     */
    public function test_withdrawing_a_signature_does_not_supersede_the_office_before_it(): void
    {
        $document = $this->reviewable();
        $first = $this->admin($document->originatingOffice);

        $earlier = app(SignDocument::class)->handle(
            document: $document,
            signer: $first,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        $next = $this->office('HRMO', 'Human Resource Office');

        app(TransitionDocument::class)->handle(
            document: $document->fresh(),
            action: MovementAction::Forwarded,
            actor: $first,
            toOfficeId: $next->id,
        );

        $second = $this->admin($next);

        $later = app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $second,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(page: 1),
            stampedPdf: $this->stampedUploadFor('HRMO'),
        );

        $this->assertSame(3, DocumentFile::query()->count());

        app(UndoSignature::class)->handle($later->fresh(), $second);

        // The withdrawn mark's version is gone, not orphaned above the
        // signature that came before it.
        $this->assertSame(2, DocumentFile::query()->count());
        $this->assertSame(0, DocumentFile::query()->where('version', 3)->count());
        $this->assertFalse($earlier->fresh()->load('file')->isSuperseded());
    }

    /**
     * The superseded column must not cost a query per row.
     *
     * isSuperseded() is one query, and the register renders every signature on
     * the document -- so the page works the number out ONCE from the relations
     * it has already loaded and compares in memory. Measured through the
     * presenter with no viewer, because `can_undo` asks a policy per row that
     * runs queries of its own: including it would measure that instead, and a
     * test that cannot fail for the reason it names is not a test.
     */
    public function test_the_superseded_column_costs_no_queries(): void
    {
        $document = $this->reviewable();
        $signer = $this->admin($document->originatingOffice);

        app(SignDocument::class)->handle(
            document: $document,
            signer: $signer,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        foreach ([['HRMO', 'Human Resource Office'], ['BAC', 'Bids and Awards Committee']] as [$code, $name]) {
            $office = $this->office($code, $name);

            app(TransitionDocument::class)->handle(
                document: $document->fresh(),
                action: MovementAction::Forwarded,
                actor: $signer,
                toOfficeId: $office->id,
            );

            $signer = $this->admin($office);

            app(SignDocument::class)->handle(
                document: $document->fresh(),
                signer: $signer,
                method: SignatureMethod::Drawn,
                drawnPng: $this->pngDataUrl(),
                placement: $this->placement(page: 1),
                stampedPdf: $this->stampedUploadFor($code),
            );
        }

        $document = $document->fresh();
        $document->load(['signatures.file', 'files']);

        $maxUploaded = (int) $document->files
            ->whereNotIn('id', $document->signatures->pluck('stamped_file_id')->filter()->all())
            ->max('version');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $rows = app(DocumentPresenter::class)->signatures(
            $document->signatures,
            $maxUploaded,
        );

        $this->assertCount(3, $rows);
        $this->assertSame(0, $queries, 'The register is querying per signature again.');

        foreach ($rows as $row) {
            $this->assertFalse($row['superseded']);
        }

        // And the shortcut agrees with the model it stands in for.
        foreach ($document->signatures as $index => $signature) {
            $this->assertSame(
                $signature->isSuperseded(),
                $rows[$index]['superseded'],
                'The page and the model disagree about superseded.',
            );
        }
    }

    /**
     * REGRESSION, found in QA 2026-09-20: a stamp identical to what was signed
     * used to make Undo delete the ORIGINAL file.
     *
     * StoreDocumentFile dedupes a byte-identical upload back onto the current
     * version rather than making a new one -- correct on its own, and the
     * reason a double-submitted form does not manufacture versions. But it
     * meant a stamped PDF that happened to equal the signed bytes came back as
     * the SAME ROW, so the signature recorded `stamped_file_id ==
     * document_file_id`, and withdrawing it then deleted the document's only
     * file and its bytes off disk.
     *
     * A browser that failed to draw the mark produces exactly those bytes.
     */
    public function test_a_stamp_identical_to_the_signed_file_is_not_recorded_as_a_new_version(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);
        $signed = $document->currentFile()->first();

        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            // The SAME bytes the document already has: nothing was stamped.
            stampedPdf: UploadedFile::fake()->createWithContent('pr.pdf', '%PDF-1.4 original'),
        );

        $this->assertSame(
            1,
            DocumentFile::query()->count(),
            'Identical bytes must not manufacture a version.',
        );
        $this->assertNull(
            $signature->stamped_file_id,
            'A stamp that produced no new version must not claim one.',
        );
        $this->assertSame($signed->id, $signature->document_file_id);
    }

    /** ...and withdrawing such a signature must not take the document with it. */
    public function test_undoing_a_no_op_stamp_leaves_the_document_intact(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);
        $signed = $document->currentFile()->first();

        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: UploadedFile::fake()->createWithContent('pr.pdf', '%PDF-1.4 original'),
        );

        app(UndoSignature::class)->handle($signature, $admin);

        $this->assertSame(0, DocumentSignature::query()->count());
        $this->assertSame(1, DocumentFile::query()->count(), 'The document lost its only file.');
        Storage::disk('documents')->assertExists($signed->path);
        $this->assertSame('%PDF-1.4 original', Storage::disk('documents')->get($signed->path));
    }

    /**
     * Belt and braces for the same hazard from the other side: even if a row
     * somehow claims the file it was signed against, withdrawing must not
     * delete it.
     */
    public function test_undo_never_deletes_the_version_that_was_signed(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);
        $signed = $document->currentFile()->first();

        $signature = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        // Force the broken state a dedupe used to produce.
        $signature->forceFill(['stamped_file_id' => $signed->id])->save();

        app(UndoSignature::class)->handle($signature->fresh(), $admin);

        $this->assertSame(1, DocumentFile::query()->count());
        Storage::disk('documents')->assertExists($signed->path);
    }

    /**
     * Two signatures sharing one stamped version -- the other face of the
     * dedupe hazard. Withdrawing one must not delete bytes the other still
     * points at.
     */
    public function test_undo_keeps_a_stamped_version_another_signature_still_claims(): void
    {
        $document = $this->reviewable();
        $office = $document->originatingOffice;
        $admin = $this->admin($office);

        $first = app(SignDocument::class)->handle(
            document: $document,
            signer: $admin,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        $stamped = $first->stampedFile;
        $this->assertNotNull($stamped);

        // A second signature pointing at the same produced version.
        $second = app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $this->superAdmin(),
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );
        $second->forceFill(['stamped_file_id' => $stamped->id])->save();

        app(UndoSignature::class)->handle($first->fresh(), $admin);

        $this->assertNotNull(
            DocumentFile::query()->find($stamped->id),
            'Another signature still claims this version.',
        );
        Storage::disk('documents')->assertExists($stamped->path);
    }

    /**
     * The Undo button must not cost a query per signature.
     *
     * DocumentSignaturePolicy::undo is asked once per row, and before QA on
     * 2026-09-20 each ask ran its own "did anybody sign after this" and "was
     * anything uploaded since" -- measured at two queries per signature, on a
     * page that had already loaded every signature and every file needed to
     * answer them.
     *
     * Measured as a DELTA between one signature and three, on the same
     * document, rather than as an absolute count. The page's other queries are
     * nobody's business here, and an absolute number turns every unrelated
     * eager-load into a failure in this test. What must hold is that the count
     * does not GROW with the number of signatures.
     */
    public function test_the_undo_button_costs_no_query_per_signature(): void
    {
        $document = $this->reviewable();
        $viewer = $this->admin($document->originatingOffice);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $load = function () use ($document, $viewer, &$queries): int {
            $queries = 0;

            $this->actingAs($viewer)
                ->get(route('documents.show', $document))
                ->assertOk();

            return $queries;
        };

        app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $viewer,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        $one = $load();

        // Two more marks on the same document, from signers with no office of
        // their own so the one-per-office rule does not refuse them.
        foreach ([1, 2] as $ignored) {
            app(SignDocument::class)->handle(
                document: $document->fresh(),
                signer: $this->superAdmin(),
                method: SignatureMethod::Drawn,
                drawnPng: $this->pngDataUrl(),
            );
        }

        $this->assertSame(3, DocumentSignature::query()->count());

        $three = $load();

        $this->assertLessThanOrEqual(
            $one,
            $three,
            sprintf(
                'Two more signatures cost %d more queries; the Undo check is running per row again.',
                $three - $one,
            ),
        );
    }

    /**
     * ...and the check itself asks the database nothing when the page has
     * already loaded what it needs.
     *
     * A separate test from the one above, and it has to be. On a real page
     * at most ONE signature belongs to the viewer's office -- the rest are
     * refused at the ownership check before the expensive reads are reached --
     * so a page-level query count cannot tell whether those reads are cheap.
     * It only takes the eager load away. This asks the policy directly, with
     * the relations documents.show loads, and requires zero.
     */
    public function test_the_undo_check_asks_nothing_when_the_page_has_loaded_it(): void
    {
        $document = $this->reviewable();
        $viewer = $this->admin($document->originatingOffice);

        app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $viewer,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
            placement: $this->placement(),
            stampedPdf: $this->stampedUpload(),
        );

        // Exactly what DocumentController::show loads for this decision --
        // including pointing each signature's `document` relation back at the
        // instance that carries the loaded files and signatures. A separately
        // hydrated Document would be bare, and the policy would query off it.
        $loaded = Document::query()
            ->with([
                'files',
                'openMovement',
                'signatures.file:id,version,document_id,checksum_sha256',
            ])
            ->findOrFail($document->id);

        $loaded->signatures->each(
            fn (DocumentSignature $row) => $row->setRelation('document', $loaded),
        );

        $signature = $loaded->signatures->first();

        // Warm anything the AUTH layer resolves once, so the count below is
        // the policy's own cost and not the gate's first-call setup.
        $viewer->can('undo', $signature);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertTrue($viewer->can('undo', $signature));

        $this->assertSame(
            0,
            $queries,
            'DocumentSignaturePolicy::undo is querying for what the page already has.',
        );
    }

    /**
     * EVERY signature reaches the page, however many there are.
     *
     * The client's report of 2026-09-20 was that a long register looked cut
     * off. The clipping turned out to be in the ATTACHMENTS column, where each
     * signature writes a "Signed by ..." note that a `truncate` was cutting
     * mid-word -- but the claim worth pinning is this one, because it is the
     * one that would be a real loss: the Signatures panel must never show a
     * subset, and nothing in the payload may cap it.
     */
    public function test_every_signature_reaches_the_page_however_many_there_are(): void
    {
        $document = $this->reviewable();
        $office = $document->originatingOffice;
        $viewer = $this->admin($office);

        // Twelve, from signers with no office of their own so the
        // one-per-office rule does not refuse them.
        app(SignDocument::class)->handle(
            document: $document->fresh(),
            signer: $viewer,
            method: SignatureMethod::Drawn,
            drawnPng: $this->pngDataUrl(),
        );

        for ($i = 0; $i < 11; $i++) {
            app(SignDocument::class)->handle(
                document: $document->fresh(),
                signer: $this->superAdmin(),
                method: SignatureMethod::Drawn,
                drawnPng: $this->pngDataUrl(),
            );
        }

        $this->assertSame(12, DocumentSignature::query()->count());

        $this->actingAs($viewer)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('signatures', 12));
    }

    /**
     * The note each signature writes into the version list must be readable in
     * full, not cut off mid-word.
     *
     * `truncate` on the wrapper set white-space: nowrap, which the note
     * inherited -- so "Signed by CAO Admin (Office of the City Mayor -
     * Community Affairs Office). Signature serial ..." rendered as "Signature
     * s" with no way to see the rest.
     */
    public function test_the_version_note_is_not_clipped_by_a_nowrap_wrapper(): void
    {
        $source = (string) file_get_contents(
            resource_path('js/pages/documents/show.tsx'),
        );

        $start = strpos($source, '{files.map((file, index) => (');
        $this->assertNotFalse($start, 'The attachments list moved.');

        $list = substr($source, $start, 3600);

        $this->assertStringNotContainsString(
            'min-w-0 flex-1 truncate',
            $list,
            'The wrapper is clipping the version note again.',
        );
        $this->assertStringContainsString('break-words', $list);
    }
}
