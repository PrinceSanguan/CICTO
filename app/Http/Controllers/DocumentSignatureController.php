<?php

namespace App\Http\Controllers;

use App\Actions\Documents\SignDocument;
use App\Enums\SignatureMethod;
use App\Http\Requests\Documents\StoreSignatureRequest;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Services\QrCodeRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\HtmlString;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * §15 digital signatures.
 *
 * SINCE 2026-09-20 the mark IS printed onto the page, at a spot the signer
 * picks in the viewer. Both of the old objections still stand; neither is
 * answered by ignoring them, so here is what each became:
 *
 *  1. "Stamping rewrites the file, destroying the binding." It does not
 *     rewrite it. Stamping APPENDS a version and the signature keeps binding
 *     to the one that was read -- see SignDocument, which holds the whole
 *     argument.
 *  2. "Free PHP tooling cannot re-typeset PDF 1.5+ object streams." Still
 *     true, which is why nothing here touches the PDF. The signer's browser
 *     composes the stamped copy with pdf-lib and posts it as a file.
 *
 * The one-page Signature Certificate did not go away, and is still the thing
 * that makes a signature checkable by someone holding only paper: the stamp is
 * a picture of a signature, while the certificate carries the serial, the file
 * hash and a QR to public verification.
 */
class DocumentSignatureController extends Controller
{
    public function __construct(private readonly QrCodeRenderer $qr) {}

    public function store(
        StoreSignatureRequest $request,
        Document $document,
        SignDocument $sign,
    ): RedirectResponse {
        $method = $request->enum('method', SignatureMethod::class);

        $signature = $sign->handle(
            document: $document,
            signer: $request->user(),
            method: $method,
            drawnPng: $request->input('image'),
            purpose: $request->input('purpose') ?: DocumentSignature::PURPOSE_APPROVAL,
            request: $request,
            placement: $request->validated('placement'),
            stampedPdf: $request->file('stamped_pdf'),
        );

        // Read back rather than trusted from the relation: the toast names a
        // version number, and naming the wrong one sends a clerk to the wrong
        // row in the Attachments list.
        $stamped = $signature->stampedFile()->first();

        return back()->with('toast', [
            'type' => 'success',
            'message' => $stamped !== null
                ? sprintf(
                    '%s signature placed on page %d and saved as v%d. Certificate serial %s.',
                    $signature->purposeLabel(),
                    (int) $signature->stamp_page,
                    $stamped->version,
                    $signature->serial,
                )
                : sprintf(
                    '%s signature recorded. Certificate serial %s.',
                    $signature->purposeLabel(),
                    $signature->serial,
                ),
        ]);
    }

    /**
     * The printable certificate.
     *
     * dompdf cannot parse oklch(), flexbox or grid, so this Blade view carries
     * its own hex/table stylesheet and shares nothing with the Tailwind 4 app
     * theme. That duplication is deliberate and will drift -- the alternative
     * is a PDF that renders as a blank page.
     */
    public function certificate(Document $document, DocumentSignature $signature): Response
    {
        $this->authorize('view', $document);
        abort_unless($signature->document_id === $document->id, 404);

        $pdf = Pdf::loadView('documents.signature-certificate', [
            'signature' => $signature->load(['document', 'file', 'signer']),
            'document' => $document,
            'valid' => $signature->isValid(rehashBytes: true),
            'superseded' => $signature->isSuperseded(),
            'verifyUrl' => $this->verifyUrl($signature),
            'qr' => new HtmlString($this->qr->svg($this->verifyUrl($signature), 220)),
        ])->setPaper('a4');

        return $pdf->stream("signature-{$signature->serial}.pdf");
    }

    /**
     * Public verification. Reached by scanning the QR on a printed certificate,
     * so it must work with no session.
     *
     * Shows only what the certificate already shows, plus the verdict. No
     * document contents, no download link -- possession of a serial proves
     * nothing except that you are holding the paper.
     */
    public function verify(string $serial): InertiaResponse
    {
        $signature = DocumentSignature::query()
            ->where('serial', $serial)
            ->with(['document:id,control_number,title', // disk, path and purged_at are required: isValid(rehashBytes: true)
                // re-reads the bytes, and a column list that omits them hands
                // hashOnDisk() a null path.
                'file:id,version,checksum_sha256,document_id,disk,path,purged_at'])
            ->first();

        if ($signature === null) {
            return Inertia::render('signatures/verify', ['signature' => null]);
        }

        return Inertia::render('signatures/verify', [
            'signature' => [
                'serial' => $signature->serial,
                'control_number' => $signature->document->control_number,
                'signer_name' => $signature->signer_name,
                'signer_position' => $signature->signer_position,
                'signer_office' => $signature->signer_office,
                'purpose' => $signature->purpose,
                'signed_at' => $signature->signed_at->toIso8601String(),
                'file_version' => $signature->file?->version,
                // rehashBytes: this page IS the tamper check. Comparing two
                // database columns leaves a byte swap on disk invisible, and
                // the nightly sweep that would catch it writes to a log the
                // person holding the paper will never read. One file hash on a
                // page nobody loads in bulk is the right trade.
                'valid' => $signature->isValid(rehashBytes: true),
                'superseded' => $signature->isSuperseded(),
            ],
        ]);
    }

    private function verifyUrl(DocumentSignature $signature): string
    {
        // Same rule as the QR label: baked from config, never from the request,
        // because this string gets printed onto paper.
        return rtrim((string) config('cicto.scan_base_url'), '/').'/verify/'.$signature->serial;
    }
}
