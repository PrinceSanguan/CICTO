<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Actions\Documents\SignDocument;
use App\Actions\Documents\StoreDocumentFile;
use App\Actions\Documents\TransitionDocument;
use App\Enums\DocumentPriority;
use App\Enums\MovementAction;
use App\Enums\SecurityEventType;
use App\Enums\SignatureMethod;
use App\Exceptions\AlreadySignedException;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * §15. The feature's whole value is that a later substitution is DETECTABLE,
 * so most of these tests are about breaking things and checking it notices.
 */
class SignatureTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /** A document under review at the given office, with a real file attached. */
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

    public function test_a_signature_binds_to_the_exact_file_version(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle($document, $admin, SignatureMethod::Typed);
        $file = $document->currentFile()->first();

        $this->assertSame($file->id, $signature->document_file_id);
        $this->assertSame($file->checksum_sha256, $signature->document_hash_sha256);
        $this->assertTrue($signature->isValid());
        $this->assertFalse($signature->isSuperseded());
    }

    public function test_replacing_the_file_makes_the_signature_report_a_mismatch(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle($document, $admin, SignatureMethod::Typed);

        // Someone swaps the bytes behind the version that was signed.
        DocumentFile::query()
            ->whereKey($signature->document_file_id)
            ->update(['checksum_sha256' => str_repeat('a', 64)]);

        $this->assertFalse($signature->fresh()->load('file')->isValid());
    }

    public function test_editing_the_signature_row_itself_is_detected(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle($document, $admin, SignatureMethod::Typed);

        // Rewriting who signed it must not silently succeed.
        DocumentSignature::query()
            ->whereKey($signature->id)
            ->update(['user_id' => $this->superAdmin()->id]);

        $this->assertFalse($signature->fresh()->selfHashMatches());
    }

    public function test_a_new_version_supersedes_without_invalidating(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle($document, $admin, SignatureMethod::Typed);

        app(StoreDocumentFile::class)->handle(
            $document,
            UploadedFile::fake()->createWithContent('pr-v2.pdf', '%PDF-1.4 CORRECTED'),
            $admin,
        );

        $signature = $signature->fresh()->load('file');

        // The signature is still a true statement about v1 -- it just no longer
        // describes the current file.
        $this->assertTrue($signature->isValid());
        $this->assertTrue($signature->isSuperseded());
    }

    public function test_the_same_person_cannot_sign_the_same_version_twice(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        app(SignDocument::class)->handle($document, $admin, SignatureMethod::Typed);

        // A domain refusal, NOT the unique index. Letting it reach the database
        // meant a raw QueryException surfaced to the user as a 500.
        $this->expectException(AlreadySignedException::class);

        app(SignDocument::class)->handle($document->fresh(), $admin, SignatureMethod::Typed);
    }

    public function test_a_refused_second_signature_leaves_no_orphaned_image(): void
    {
        Storage::fake('documents');

        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $png = 'data:image/png;base64,'.base64_encode($this->pngBytes());

        app(SignDocument::class)->handle($document, $admin, SignatureMethod::Drawn, $png);

        $before = Storage::disk('documents')->allFiles('signatures');

        try {
            app(SignDocument::class)->handle($document->fresh(), $admin, SignatureMethod::Drawn, $png);
            $this->fail('The second signature should have been refused.');
        } catch (AlreadySignedException) {
            // expected
        }

        // The duplicate is now caught BEFORE the image is written. Writing
        // first left a PNG behind that no row referenced, on every retry.
        $this->assertSame($before, Storage::disk('documents')->allFiles('signatures'));
    }

    public function test_a_document_with_no_file_cannot_be_signed(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $document->files()->delete();
        $document->unsetRelation('currentFile');

        // With no file there is no hash, so the certificate would print
        // "fingerprint: --" directly above a green "the signed file still
        // matches". Attaching any PDF later made that verdict permanent.
        $this->assertFalse($admin->can('sign', $document->fresh()));
    }

    public function test_a_person_who_already_signed_is_not_offered_the_pad_again(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $this->assertTrue($admin->can('sign', $document));

        app(SignDocument::class)->handle($document, $admin, SignatureMethod::Typed);

        $this->assertFalse($admin->can('sign', $document->fresh()));
    }

    public function test_a_signature_detects_bytes_swapped_on_disk(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle($document, $admin, SignatureMethod::Typed);
        $file = $signature->file;

        // Column comparison alone cannot see this: the row is untouched, only
        // the bytes changed. This is the case the nightly sweep exists for.
        Storage::disk($file->disk)->put($file->path, '%PDF-1.4 SUBSTITUTED');

        $this->assertTrue($signature->fileHashMatches());
        $this->assertFalse($signature->fileHashMatches(rehashBytes: true));
        $this->assertFalse($signature->isValid(rehashBytes: true));
    }

    public function test_the_signature_hash_covers_the_signer_identity(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle($document, $admin, SignatureMethod::Typed);

        $this->assertTrue($signature->selfHashMatches());

        // The certificate prints signer_name. If it were merely stored beside
        // the hash rather than inside it, an UPDATE would produce a document
        // naming someone else that still verified as valid.
        DB::table('document_signatures')
            ->where('id', $signature->id)
            ->update(['signer_name' => 'Someone Else']);

        $this->assertFalse($signature->fresh()->selfHashMatches());
    }

    /** A one-pixel PNG, magic bytes and all. */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }

    public function test_a_clerk_cannot_sign(): void
    {
        $document = $this->reviewable();
        $clerk = $this->staff($document->originatingOffice);

        // §15 "authorized users" is read as: someone who could approve it.
        $this->assertFalse($clerk->can('sign', $document));
    }

    public function test_an_admin_cannot_sign_their_own_submission_by_default(): void
    {
        config(['cicto.workflow.allow_self_approval' => false]);

        Storage::fake('documents');
        $office = $this->office('MPDO');
        $admin = $this->admin($office);

        $document = $this->registerDocument($office, $admin);
        app(TransitionDocument::class)->handle(
            document: $document,
            action: MovementAction::Received,
            actor: $admin,
            expectedMovementId: $document->openMovement->id,
        );

        $this->assertFalse($admin->can('sign', $document->fresh()));
    }

    public function test_a_drawn_signature_must_be_a_real_png(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $this->expectException(\RuntimeException::class);

        // An SVG would be a stored-XSS vector the moment it is rendered.
        app(SignDocument::class)->handle(
            $document,
            $admin,
            SignatureMethod::Drawn,
            'data:image/png;base64,'.base64_encode('<svg onload=alert(1)></svg>'),
        );
    }

    public function test_public_verification_reveals_no_document_content(): void
    {
        $document = $this->reviewable();
        $document->forceFill(['title' => 'Confidential disciplinary matter'])->save();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle($document->fresh(), $admin, SignatureMethod::Typed);

        $response = $this->get("/verify/{$signature->serial}");

        $response->assertOk();
        $response->assertDontSee('Confidential disciplinary matter', escape: false);
        $response->assertInertia(
            fn ($page) => $page
                ->component('signatures/verify')
                ->where('signature.valid', true)
                ->where('signature.signer_name', $admin->name)
                ->missing('signature.title')
                ->missing('signature.document_hash_sha256'),
        );
    }

    public function test_an_unknown_serial_renders_a_page_rather_than_a_404(): void
    {
        $this->get('/verify/definitelynotarealserial')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('signature', null));
    }

    public function test_the_nightly_sweep_flags_a_tampered_signature(): void
    {
        $document = $this->reviewable();
        $admin = $this->admin($document->originatingOffice);

        $signature = app(SignDocument::class)->handle($document, $admin, SignatureMethod::Typed);

        $this->artisan('cicto:verify-signatures')->assertSuccessful();

        DocumentFile::query()
            ->whereKey($signature->document_file_id)
            ->update(['checksum_sha256' => str_repeat('b', 64)]);

        // Non-zero exit so a cron wrapper notices, plus a security event so a
        // human finds out before they need the document in a hearing.
        $this->artisan('cicto:verify-signatures')->assertFailed();

        $this->assertDatabaseHas('security_events', [
            'type' => SecurityEventType::SignatureTampered->value,
        ]);
    }
}
