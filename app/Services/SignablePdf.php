<?php

namespace App\Services;

use App\Models\DocumentFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A PDF of this version, so a signature can be placed ON it.
 *
 * WHY THIS EXISTS. §15 stamping draws the mark onto a page at a spot the
 * signer picks, and the whole pipeline -- pdf.js to show the pages, pdf-lib to
 * embed the PNG -- needs a PDF to work on. A .docx has no pages a browser can
 * point at, so Word and Excel documents could be READ in the signing panel
 * (since 2026-09-20) but not SIGNED on. The client asked for every type to be
 * signable inside the document.
 *
 * WHAT THE SIGNER ENDS UP WITH, said plainly because it is a real trade: for
 * a Word or Excel file the signed copy is a PDF RENDITION, not the original
 * with a signature tucked inside it. Putting a visual mark inside a .docx
 * means rewriting its OOXML and handing back a file Word may or may not open
 * the same way; converting once, signing the conversion, and keeping the
 * original untouched as its own version is the way an office already does
 * this with a printer. The register ends up with both: the .docx that was
 * signed, and the PDF that carries the mark.
 *
 * NOTHING HERE IS STORED. The rendition is generated per request and streamed;
 * the only thing that becomes a version is the stamped PDF the browser posts
 * back, and that goes through StoreDocumentFile like any other upload.
 */
final class SignablePdf
{
    public function __construct(private readonly OfficeDocumentPreview $office) {}

    /** Can a signature be placed on a page of this version? */
    public function supports(DocumentFile $file): bool
    {
        if ($file->isPurged()) {
            return false;
        }

        return $file->mime_type === 'application/pdf'
            || $this->office->supports($file->mime_type)
            || $this->isImage($file);
    }

    /**
     * The bytes to place a signature on.
     *
     * A real PDF is handed back UNCHANGED -- re-rendering one would throw away
     * the original's fonts and layout to gain nothing, and the signature would
     * then bind to a version nobody had read.
     *
     * @throws RuntimeException when the version cannot be rendered at all
     */
    public function bytes(DocumentFile $file): string
    {
        if (! $this->supports($file)) {
            throw new RuntimeException('This version cannot be signed on the page.');
        }

        if ($file->mime_type === 'application/pdf') {
            $bytes = Storage::disk($file->disk)->get($file->path);

            if ($bytes === null) {
                throw new RuntimeException('The stored file could not be read.');
            }

            return $bytes;
        }

        return $this->isImage($file)
            ? $this->fromImage($file)
            : $this->fromOfficeDocument($file);
    }

    /**
     * Word and Excel, through the same converter the reading view uses.
     *
     * Deliberately the SAME HTML. If the signer places a mark two thirds down
     * page three of the rendition, that has to be the page three they read --
     * and it can only be guaranteed by both coming from one renderer.
     */
    private function fromOfficeDocument(DocumentFile $file): string
    {
        $body = $this->office->body($file);

        if ($body === null) {
            throw new RuntimeException('This file could not be converted for signing.');
        }

        return (string) Pdf::loadHTML($this->page($body))->setPaper('a4')->output();
    }

    /**
     * A scan or photograph, as a single page to sign on.
     *
     * Fitted to the page rather than stretched: a signature placed over a
     * distorted scan is a signature over the wrong part of the document.
     */
    private function fromImage(DocumentFile $file): string
    {
        $bytes = Storage::disk($file->disk)->get($file->path);

        if ($bytes === null) {
            throw new RuntimeException('The stored file could not be read.');
        }

        $data = 'data:'.$file->mime_type.';base64,'.base64_encode($bytes);

        $body = '<div class="scan"><img src="'.$data.'" alt=""></div>';

        return (string) Pdf::loadHTML($this->page($body, <<<'CSS'
            .scan { text-align: center; }
            .scan img { max-width: 100%; max-height: 26cm; }
            CSS))->setPaper('a4')->output();
    }

    /**
     * The shell dompdf renders.
     *
     * DejaVu Sans because it is the font dompdf ships with full Unicode
     * coverage for -- anything else silently drops the characters a Filipino
     * office actually types.
     */
    private function page(string $body, string $extra = ''): string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html><head><meta charset="utf-8"><style>
                @page { margin: 18mm 16mm; }
                body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; line-height: 1.5; color: #111827; }
                h1, h2, h3, h4 { line-height: 1.3; margin: 1.1em 0 0.4em; }
                h1 { font-size: 15pt; } h2 { font-size: 13pt; } h3 { font-size: 11pt; }
                p { margin: 0 0 0.6em; }
                ul, ol { margin: 0 0 0.6em; padding-left: 1.2em; }
                img { max-width: 100%; }
                table { border-collapse: collapse; width: 100%; margin: 0 0 0.8em; }
                td, th { border: 1px solid #9ca3af; padding: 3px 5px; vertical-align: top; }
                {$extra}
            </style></head><body>{$body}</body></html>
            HTML;
    }

    private function isImage(DocumentFile $file): bool
    {
        return in_array($file->mime_type, ['image/png', 'image/jpeg'], true);
    }
}
