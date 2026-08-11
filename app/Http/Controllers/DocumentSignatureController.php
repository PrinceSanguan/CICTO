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
 * The signature is deliberately NOT stamped onto the uploaded PDF. Two reasons,
 * and both are load-bearing:
 *
 *  1. Stamping rewrites the file, which changes its checksum -- destroying the
 *     exact binding the signature exists to create.
 *  2. Uploaded documents are client-supplied PDFs and scanner output. Free PHP
 *     tooling cannot reliably re-typeset PDF 1.5+ object streams, which is most
 *     modern PDFs, so "stamp it" quietly means "corrupt some of them".
 *
 * What ships instead is a one-page Signature Certificate carrying a QR to a
 * public verification page. That is printable, attachable, and checkable by
 * someone holding only the paper.
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
            request: $request,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Signed. Certificate serial {$signature->serial}.",
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
