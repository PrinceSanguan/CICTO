<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RegisterDocument;
use App\Enums\DocumentPriority;
use App\Enums\SecurityEventType;
use App\Models\Document;
use App\Models\SecurityEvent;
use App\Services\OfficeDocumentPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Concerns\BuildsDocuments;
use Tests\TestCase;

/**
 * Word and Excel, readable on screen -- the client's report of 2026-09-20:
 * a .docx reached the signing panel as a grey box telling them to download it.
 *
 * The point of these tests is not fidelity, which is explicitly imperfect.
 * It is that the CONTENT reaches the reader, that nothing executable comes
 * with it, and that a file the converter cannot open degrades to the sentence
 * it used to show rather than to a 500 on the signing page.
 */
class OfficeDocumentPreviewTest extends TestCase
{
    use BuildsDocuments, RefreshDatabase;

    private function documentWith(UploadedFile $upload): Document
    {
        Storage::fake('documents');

        $office = $this->office('MPDO');

        return app(RegisterDocument::class)->handle(
            title: 'Request for office supplies',
            documentTypeId: $this->documentType()->id,
            priority: DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $this->staff($office),
            upload: $upload,
        );
    }

    /** A real .docx, written by PhpWord and read back through the converter. */
    private function wordFile(string $sentence = 'Approved for procurement.'): UploadedFile
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addText($sentence);

        $table = $section->addTable();
        $table->addRow();
        $table->addCell(4000)->addText('Bond paper');
        $table->addCell(2000)->addText('12,000.00');

        $path = tempnam(sys_get_temp_dir(), 'cicto-test-').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return new UploadedFile(
            $path,
            'request.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );
    }

    private function excelFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cicto-test-').'.xlsx';

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Office', 'Amount']));
        $writer->addRow(Row::fromValues(['City Treasurer', 12000]));
        $writer->close();

        return new UploadedFile(
            $path,
            'budget.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    public function test_a_word_document_is_previewable_and_its_text_reaches_the_reader(): void
    {
        $document = $this->documentWith($this->wordFile('Approved for procurement.'));
        $file = $document->currentFile()->first();
        $admin = $this->admin($document->originatingOffice);

        $this->assertTrue($file->isPreviewable(), 'The page would still show the grey box.');
        $this->assertTrue($file->isConverted());

        $response = $this->actingAs($admin)
            ->get(route('documents.files.preview', [$document, $file]))
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('Approved for procurement.', $html);
        $this->assertStringContainsString('Bond paper', $html);
        $this->assertStringContainsString('12,000.00', $html);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_spreadsheet_renders_as_a_table_of_its_values(): void
    {
        $document = $this->documentWith($this->excelFile());
        $file = $document->currentFile()->first();

        $html = $this->actingAs($this->admin($document->originatingOffice))
            ->get(route('documents.files.preview', [$document, $file]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('City Treasurer', $html);
        $this->assertStringContainsString('12000', $html);
        $this->assertStringContainsString('<table>', $html);
    }

    /**
     * The converted markup is served as inertly as the byte path.
     *
     * This response carries HTML produced from a file somebody else uploaded,
     * so the policy is the thing standing between a crafted document and the
     * signer's session.
     */
    public function test_the_converted_page_is_served_with_a_deny_all_policy(): void
    {
        $document = $this->documentWith($this->wordFile());
        $file = $document->currentFile()->first();

        $response = $this->actingAs($this->admin($document->originatingOffice))
            ->get(route('documents.files.preview', [$document, $file]))
            ->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("script-src 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString('nosniff', (string) $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** §21: a converted preview is a read, and the log has to say so. */
    public function test_a_converted_preview_is_audited(): void
    {
        $document = $this->documentWith($this->wordFile());
        $file = $document->currentFile()->first();
        $admin = $this->admin($document->originatingOffice);

        $this->actingAs($admin)
            ->get(route('documents.files.preview', [$document, $file]))
            ->assertOk();

        $event = SecurityEvent::query()
            ->where('type', SecurityEventType::FilePreviewed)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertStringContainsString($document->control_number, (string) $event->summary);
    }

    /**
     * A file the converter cannot open shows the sentence it used to show,
     * not a 500 on the page somebody is trying to sign from.
     */
    public function test_an_unreadable_office_file_degrades_to_the_download_message(): void
    {
        /*
         * A file that WAS a valid .docx at upload -- so the row carries the
         * Word type and the page offers a preview -- whose bytes have since
         * become unreadable. That is the shape the soft failure exists for; a
         * file that was never a .docx is refused earlier, by the type check.
         */
        $document = $this->documentWith($this->wordFile());
        $file = $document->currentFile()->first();

        Storage::disk('documents')->put($file->path, "PK\x03\x04 not a word document at all");

        $html = $this->actingAs($this->admin($document->originatingOffice))
            ->get(route('documents.files.preview', [$document, $file]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Download it to read it before you sign.', $html);
    }

    /** Reading a 30 MB spreadsheet on screen is not what anybody wants. */
    public function test_a_file_over_the_ceiling_is_not_converted(): void
    {
        $document = $this->documentWith($this->wordFile());
        $file = $document->currentFile()->first();

        $file->forceFill(['size_bytes' => OfficeDocumentPreview::MAX_BYTES + 1])->save();

        $html = app(OfficeDocumentPreview::class)->render($file->fresh());

        $this->assertStringContainsString('too large to show on screen', $html);
    }

    /** Legacy .doc and .xls are honestly out of scope, not silently broken. */
    public function test_legacy_office_formats_are_not_claimed_as_previewable(): void
    {
        $preview = app(OfficeDocumentPreview::class);

        $this->assertFalse($preview->supports('application/msword'));
        $this->assertFalse($preview->supports('application/vnd.ms-excel'));
        $this->assertTrue($preview->supports(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ));
    }

    /**
     * REGRESSION: one paragraph must render as one paragraph.
     *
     * Word splits a paragraph into RUNS on every formatting or spell-check
     * language change, and PhpWord's own HTML writer emits a `<p>` per run --
     * so the client's file came out as a column of single words, one per
     * line. The converter walks the object model instead, where the paragraph
     * structure is intact.
     */
    public function test_a_paragraph_of_several_runs_is_not_split_into_lines(): void
    {
        $word = new PhpWord;
        $run = $word->addSection()->addTextRun();
        $run->addText('List of offices ');
        $run->addText('and also ', ['bold' => true]);
        $run->addText('code for the offices.');

        $path = tempnam(sys_get_temp_dir(), 'cicto-test-').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        $document = $this->documentWith(new UploadedFile(
            $path,
            'runs.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        ));

        $html = app(OfficeDocumentPreview::class)->render($document->currentFile()->first());

        // The sentence survives as one block, with the emphasis kept.
        $this->assertStringContainsString(
            'List of offices <strong>and also </strong>code for the offices.',
            $html,
        );
        $this->assertSame(
            1,
            substr_count($html, '<p>List of offices'),
            'The runs were split across paragraphs again.',
        );
    }

    /**
     * REGRESSION: text is escaped exactly once.
     *
     * PhpWord's reader returns text that is ALREADY HTML-escaped, so escaping
     * it again printed "mayor&amp;#039;s office" on screen.
     */
    public function test_an_apostrophe_is_not_double_escaped(): void
    {
        $document = $this->documentWith($this->wordFile("The mayor's office approved it."));

        $html = app(OfficeDocumentPreview::class)->render($document->currentFile()->first());

        $this->assertStringNotContainsString('&amp;#', $html);
        $this->assertStringContainsString('mayor', $html);
    }

    /**
     * ...and escaped it certainly is. A document is the most ordinary place
     * for somebody to have typed a tag, by accident or otherwise, and the
     * converted markup is rendered in a frame.
     *
     * The .docx is assembled by hand rather than with PhpWord's writer,
     * which strips tags from text before it writes them -- a fixture built
     * that way never contains what this test is about, and the test passes
     * while proving nothing.
     */
    public function test_markup_inside_a_document_is_escaped_not_rendered(): void
    {
        $document = $this->documentWith(new UploadedFile(
            $this->handBuiltDocx('<script>alert(1)</script> and <b>bold</b>'),
            'crafted.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        ));

        $html = app(OfficeDocumentPreview::class)->render($document->currentFile()->first());

        $this->assertStringContainsString(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $html,
            'The tag has to reach the reader as text.',
        );
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
    }

    /**
     * A minimal but real .docx carrying exactly the text given.
     *
     * Three entries is all an OOXML word processing package needs to be
     * opened: the content types, the package relationships, and the document
     * part itself.
     */
    private function handBuiltDocx(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // tempnam() leaves an empty file behind, and ZipArchive refuses to
        // OVERWRITE something that is not already an archive.
        $path = tempnam(sys_get_temp_dir(), 'cicto-test-').'.docx';
        @unlink($path);

        $zip = new \ZipArchive;

        if ($zip->open($path, \ZipArchive::CREATE) !== true) {
            $this->fail('The test could not build a .docx to convert.');
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
              <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
              <Default Extension="xml" ContentType="application/xml"/>
              <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
            </Types>
            XML);

        $zip->addFromString('_rels/.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
              <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
            </Relationships>
            XML);

        $zip->addFromString('word/document.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
              <w:body><w:p><w:r><w:t xml:space="preserve">{$escaped}</w:t></w:r></w:p></w:body>
            </w:document>
            XML);

        $zip->close();

        return $path;
    }

    /**
     * EVERY readable type can be signed ON, client request 2026-09-21.
     *
     * §15 stamping needs pages to point at, and a .docx has none a browser
     * can address -- so Word and Excel could be read in the signing panel and
     * not signed on. The signable endpoint answers with a PDF for all of
     * them.
     */
    public function test_a_word_document_is_offered_as_a_pdf_to_sign_on(): void
    {
        $document = $this->documentWith($this->wordFile('Approved for procurement.'));
        $file = $document->currentFile()->first();

        $response = $this->actingAs($this->admin($document->originatingOffice))
            ->get(route('documents.files.signable', [$document, $file]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $bytes = $response->getContent();

        $this->assertStringStartsWith('%PDF', (string) $bytes, 'That is not a PDF.');
        $this->assertGreaterThan(1000, strlen((string) $bytes));
    }

    public function test_a_spreadsheet_is_offered_as_a_pdf_to_sign_on(): void
    {
        $document = $this->documentWith($this->excelFile());
        $file = $document->currentFile()->first();

        $bytes = $this->actingAs($this->admin($document->originatingOffice))
            ->get(route('documents.files.signable', [$document, $file]))
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF', (string) $bytes);
    }

    /**
     * A PDF is handed back UNCHANGED. Re-rendering one would throw away its
     * fonts and layout to gain nothing, and the signature would then bind to
     * a version nobody had read.
     */
    public function test_a_pdf_version_is_passed_through_untouched(): void
    {
        $original = '%PDF-1.4 the original bytes';

        $document = $this->documentWith(
            UploadedFile::fake()->createWithContent('memo.pdf', $original),
        );

        $file = $document->currentFile()->first();

        $bytes = $this->actingAs($this->admin($document->originatingOffice))
            ->get(route('documents.files.signable', [$document, $file]))
            ->assertOk()
            ->getContent();

        $this->assertSame($original, $bytes);
    }

    /** A scan is a page too: one page, fitted, not stretched. */
    public function test_an_image_is_offered_as_a_one_page_pdf(): void
    {
        $document = $this->documentWith(
            UploadedFile::fake()->image('scan.png', 400, 300),
        );

        $file = $document->currentFile()->first();

        $bytes = $this->actingAs($this->admin($document->originatingOffice))
            ->get(route('documents.files.signable', [$document, $file]))
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF', (string) $bytes);
    }

    /** The types with no viewer have no page to sign on either. */
    public function test_a_legacy_office_format_cannot_be_signed_on(): void
    {
        $document = $this->documentWith($this->wordFile());
        $file = $document->currentFile()->first();

        $file->forceFill(['mime_type' => 'application/msword'])->save();

        $this->actingAs($this->admin($document->originatingOffice))
            ->get(route('documents.files.signable', [$document, $file->fresh()]))
            ->assertStatus(415);
    }

    /** It is a read of the document's contents, so it is audited as one. */
    public function test_opening_a_version_to_sign_on_is_audited(): void
    {
        $document = $this->documentWith($this->wordFile());
        $file = $document->currentFile()->first();

        $this->actingAs($this->admin($document->originatingOffice))
            ->get(route('documents.files.signable', [$document, $file]))
            ->assertOk();

        $event = SecurityEvent::query()
            ->where('type', SecurityEventType::FilePreviewed)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertStringContainsString('place a signature', (string) $event->summary);
    }

    /** And it is not a way past the policy. */
    public function test_another_office_cannot_open_a_version_to_sign_on(): void
    {
        $document = $this->documentWith($this->wordFile());
        $file = $document->currentFile()->first();

        $stranger = $this->admin($this->office('HRMO', 'Human Resource Office'));

        $this->actingAs($stranger)
            ->get(route('documents.files.signable', [$document, $file]))
            ->assertForbidden();
    }

    /**
     * A version purged under the retention policy is GONE, not forbidden --
     * 410, the same answer the preview and download paths give. Found
     * untested in QA on 2026-09-21.
     */
    public function test_a_purged_version_cannot_be_opened_to_sign_on(): void
    {
        $document = $this->documentWith($this->wordFile());
        $file = $document->currentFile()->first();

        $file->forceFill(['purged_at' => now()])->save();

        $this->actingAs($this->admin($document->originatingOffice))
            ->get(route('documents.files.signable', [$document, $file->fresh()]))
            ->assertStatus(410);
    }
}
