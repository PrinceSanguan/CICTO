<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Enums\DocumentPriority;
use App\Enums\SecurityEventType;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Reading a version on screen instead of downloading it.
 *
 * Most of this file is about what the endpoint REFUSES. Serving uploads inline
 * puts them on the application's own origin, so the allowlist and the headers
 * are the feature -- the streaming part is the easy half.
 */
class FilePreviewTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    /** @return array{Document, DocumentFile} */
    private function documentWithFile(
        string $name = 'pr.pdf',
        string $content = '%PDF-1.4 original',
        string $officeCode = 'MPDO',
    ): array {
        Storage::fake('documents');

        $office = $this->office($officeCode);
        $clerk = $this->staff($office);

        $document = app(RegisterDocument::class)->handle(
            title: 'Purchase request',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $clerk,
            upload: UploadedFile::fake()->createWithContent($name, $content),
        );

        return [$document->refresh(), $document->currentFile()->first()];
    }

    private function previewUrl(Document $document, DocumentFile $file): string
    {
        return route('documents.files.preview', ['document' => $document, 'file' => $file]);
    }

    public function test_a_pdf_version_is_served_inline_and_not_as_a_download(): void
    {
        [$document, $file] = $this->documentWithFile();

        $response = $this->actingAs($document->creator)->get($this->previewUrl($document, $file));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 original', $response->streamedContent());
    }

    /**
     * The headers ARE the feature. An upload rendered inline runs on this
     * application's origin, so losing any of these turns an attachment into a
     * stored-XSS delivery route.
     */
    public function test_the_inline_response_carries_the_headers_that_make_it_safe(): void
    {
        [$document, $file] = $this->documentWithFile();

        $response = $this->actingAs($document->creator)->get($this->previewUrl($document, $file));

        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("script-src 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        // These bytes are authorised per-user, so no shared cache may keep them.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_image_version_is_previewable(): void
    {
        [$document, $file] = $this->documentWithFile('scan.png', 'not really a png');
        DocumentFile::query()->whereKey($file->id)->update(['mime_type' => 'image/png']);

        $this->actingAs($document->creator)
            ->get($this->previewUrl($document, $file->refresh()))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    /**
     * LEGACY Word and Excel are accepted uploads with no viewer at all. 415,
     * because the refusal is about the type and not about the person asking.
     *
     * Their modern counterparts stopped being refused on 2026-09-20 --
     * OfficeDocumentPreview converts .docx and .xlsx to HTML on the server --
     * but the old binary formats have no reader worth the name, and pretending
     * otherwise would be worse than the download link.
     */
    public function test_a_file_type_with_no_viewer_is_refused_rather_than_guessed_at(): void
    {
        // One document, retyped -- documentWithFile() creates its own office,
        // and a second call collides on offices.code.
        [$document, $file] = $this->documentWithFile();

        foreach (['application/msword', 'application/vnd.ms-excel'] as $legacy) {
            DocumentFile::query()->whereKey($file->id)->update(['mime_type' => $legacy]);

            $this->actingAs($document->creator)
                ->get($this->previewUrl($document, $file->refresh()))
                ->assertStatus(415);
        }
    }

    /**
     * ...and the modern ones come back as HTML the server produced, never as
     * their own bytes. Serving a .docx inline would be the very hazard
     * DocumentFile::PREVIEWABLE exists to close.
     */
    public function test_a_word_version_is_converted_rather_than_served_as_its_own_bytes(): void
    {
        [$document, $file] = $this->documentWithFile('memo.docx', 'PK not really a docx');

        DocumentFile::query()->whereKey($file->id)->update([
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        $response = $this->actingAs($document->creator)
            ->get($this->previewUrl($document, $file->refresh()))
            ->assertOk();

        $this->assertStringContainsString(
            'text/html',
            (string) $response->headers->get('Content-Type'),
        );

        // The upload's own bytes must not appear in the response.
        $this->assertStringNotContainsString('PK not really a docx', (string) $response->getContent());
    }

    /**
     * The whole point of the allowlist. mime_type is sniffed at upload and is
     * trustworthy enough to route on, but it is still a database column -- and a
     * column is the kind of thing that gets edited. A row claiming to be HTML
     * must not produce an HTML response on this origin.
     */
    public function test_a_rewritten_mime_type_cannot_talk_the_endpoint_into_serving_html(): void
    {
        [$document, $file] = $this->documentWithFile('payload.pdf', '<script>alert(1)</script>');

        DocumentFile::query()->whereKey($file->id)->update(['mime_type' => 'text/html']);

        $this->actingAs($document->creator)
            ->get($this->previewUrl($document, $file->refresh()))
            ->assertStatus(415);
    }

    /** SVG is the same attack in a friendlier extension, and is not on the list. */
    public function test_svg_is_not_previewable(): void
    {
        [$document, $file] = $this->documentWithFile();

        DocumentFile::query()->whereKey($file->id)->update(['mime_type' => 'image/svg+xml']);

        $this->actingAs($document->creator)
            ->get($this->previewUrl($document, $file->refresh()))
            ->assertStatus(415);
    }

    /** A purged version is GONE, not forbidden. Same rule as the download path. */
    public function test_a_purged_version_reports_gone_rather_than_forbidden(): void
    {
        [$document, $file] = $this->documentWithFile();

        DocumentFile::query()->whereKey($file->id)->update(['purged_at' => now()]);

        $this->actingAs($document->creator)
            ->get($this->previewUrl($document, $file->refresh()))
            ->assertStatus(410);
    }

    public function test_somebody_who_cannot_read_the_document_cannot_preview_its_file(): void
    {
        [$document, $file] = $this->documentWithFile();

        $outsider = $this->admin($this->office('TREA', 'Treasury Office'));

        $this->actingAs($outsider)
            ->get($this->previewUrl($document, $file))
            ->assertForbidden();
    }

    public function test_previewing_requires_signing_in(): void
    {
        [$document, $file] = $this->documentWithFile();

        $this->get($this->previewUrl($document, $file))->assertRedirect(route('login'));
    }

    /**
     * scopeBindings() on the route. Without it {file} resolves globally, and a
     * document you may read could be paired with a file id from one you may not
     * -- the policy would then authorise against the wrong parent.
     */
    public function test_a_file_belonging_to_another_document_is_not_found(): void
    {
        [$mine] = $this->documentWithFile();
        // A second office, or the two registrations collide on offices.code.
        [, $theirs] = $this->documentWithFile('other.pdf', '%PDF-1.4 someone else', 'TREA');

        $this->actingAs($mine->creator)
            ->get(route('documents.files.preview', ['document' => $mine, 'file' => $theirs]))
            ->assertNotFound();
    }

    /**
     * The app-wide CSP is a DEFAULT, not an override.
     *
     * SecurityHeaders runs after the controller and headers->set() replaces, so
     * the app policy used to silently overwrite the deny-all one on inline
     * attachments -- and only in production, which is the one environment
     * nobody would have caught it in.
     */
    public function test_the_app_wide_policy_does_not_overwrite_the_preview_policy_in_production(): void
    {
        [$document, $file] = $this->documentWithFile();

        // The middleware only applies the app policy when the app is production.
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('cicto.security.csp_enforce', true);

        $response = $this->actingAs($document->creator)->get($this->previewUrl($document, $file));

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringNotContainsString("default-src 'self'", $csp);
        $this->assertStringNotContainsString('nonce-', $csp);
    }

    /** ...and an ordinary page still gets the app-wide policy in production. */
    public function test_an_ordinary_page_still_receives_the_app_wide_policy(): void
    {
        [$document] = $this->documentWithFile();

        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('cicto.security.csp_enforce', true);

        $response = $this->actingAs($document->creator)->get(route('documents.show', $document));

        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    /**
     * §21: reads are audited. Filed as its own type -- somebody who read a
     * document on screen did not take a copy away, and a leak investigation
     * cares which one happened.
     */
    public function test_a_preview_is_audited_as_a_preview_and_not_as_a_download(): void
    {
        [$document, $file] = $this->documentWithFile();

        $this->actingAs($document->creator)
            ->get($this->previewUrl($document, $file))
            ->assertOk();

        $this->assertTrue(
            SecurityEvent::query()->where('type', SecurityEventType::FilePreviewed)->exists(),
        );
        $this->assertFalse(
            SecurityEvent::query()->where('type', SecurityEventType::FileDownloaded)->exists(),
        );
    }

    /**
     * original_name comes from an upload and is interpolated into a header, so
     * it cannot be allowed to carry a quote or a newline out with it.
     */
    public function test_a_hostile_filename_cannot_break_out_of_the_content_disposition_header(): void
    {
        [$document, $file] = $this->documentWithFile();

        DocumentFile::query()->whereKey($file->id)->update([
            'original_name' => "eviI\".pdf\r\nX-Injected: yes",
        ]);

        $response = $this->actingAs($document->creator)
            ->get($this->previewUrl($document, $file->refresh()));

        $response->assertOk();
        $response->assertHeaderMissing('X-Injected');

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertSame('inline; filename="eviI.pdfX-Injected: yes"', $disposition);
    }

    /** The page must not offer a button the endpoint would refuse. */
    public function test_the_page_reports_which_versions_can_be_previewed(): void
    {
        [$document, $file] = $this->documentWithFile();

        $this->actingAs($document->creator)
            ->get(route('documents.show', $document))
            ->assertInertia(fn ($page) => $page
                ->where('files.0.is_previewable', true)
                ->where('files.0.id', $file->id));
    }
}
