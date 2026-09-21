<?php

namespace App\Http\Controllers;

use App\Actions\Documents\StoreDocumentFile;
use App\Enums\SecurityEventType;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\SecurityEvent;
use App\Services\OfficeDocumentPreview;
use App\Services\SignablePdf;
use App\Support\DocumentUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploads and downloads for document versions.
 *
 * The download route MUST be declared with ->scopeBindings(). Without it,
 * {file} resolves globally rather than within {document}, and an attacker can
 * pair a document they may read with a file id belonging to one they may not --
 * the policy would then authorise against the wrong parent.
 */
class DocumentFileController extends Controller
{
    public function store(Request $request, Document $document, StoreDocumentFile $store): RedirectResponse
    {
        $this->authorize('uploadVersion', $document);

        $validated = $request->validate([
            'file' => ['required', ...DocumentUpload::rules()],
            'replace_reason' => ['nullable', 'string', 'max:500'],
        ], DocumentUpload::messages());

        $file = $store->handle(
            document: $document,
            upload: $request->file('file'),
            uploader: $request->user(),
            movement: $document->openMovement,
            replaceReason: $validated['replace_reason'] ?? null,
        );

        return back()->with('upload', DocumentUpload::confirmation($file->original_name, "Uploaded as version {$file->version}."));
    }

    /**
     * Show a version in the browser instead of handing over a copy.
     *
     * §15 leans on this: a signature binds to one exact file version, and
     * "sign this" is not a reasonable thing to ask of someone who has to
     * download an attachment and find it in their Downloads folder first. The
     * point of previewing is that the bytes on screen are the bytes being
     * hashed.
     *
     * SERVING UPLOADS INLINE IS THE DANGEROUS DIRECTION, and everything below
     * exists because of it. An attachment rendered inline runs on this
     * application's own origin, so an HTML or SVG payload could read the
     * session cookie of whoever opened it -- which is exactly why download()
     * has always forced a copy instead. Four things keep that shut:
     *
     *  1. DocumentFile::PREVIEWABLE is a closed allowlist -- PDF, PNG, JPEG.
     *     Anything else is refused here rather than sent with a guess.
     *  2. The Content-Type sent is the allowlist's own value, never the stored
     *     mime_type. A rewritten column cannot change what the browser is told.
     *  3. nosniff, so the browser may not re-interpret the bytes as something
     *     more interesting than what it was told.
     *  4. A response CSP that denies everything and grants nothing back, so a
     *     rendered file has no script, no network and nothing to fetch.
     *
     * NO `sandbox` DIRECTIVE, deliberately, and it is the one obvious thing
     * missing from that list. It would put the response in an opaque origin,
     * which is genuinely stronger -- and it is also a known way to make browser
     * PDF viewers render nothing at all. SecurityHeaders already warns what that
     * costs here: "breaks the app in ways that look like random blank panels,
     * and an LGU office cannot debug that". The protection is not being leaned
     * on anyway. Rules 1-3 are what close the hole: the only way an upload
     * becomes stored XSS is by being interpreted as HTML or SVG, and a closed
     * allowlist of PDF/PNG/JPEG served under nosniff with an explicit
     * Content-Type cannot be. The sandbox would be depth on top of a shut door,
     * bought at the price of a feature that silently does not work.
     */
    public function preview(
        Request $request,
        Document $document,
        DocumentFile $file,
        OfficeDocumentPreview $preview,
    ): StreamedResponse|Response {
        $this->authorize('preview', $file);

        // 410 not 403: a version purged under the retention policy is GONE, not
        // forbidden, and the person asking may well have every right to it.
        abort_unless($file->exists(), 410, 'This version is no longer stored.');

        /*
         * WORD AND EXCEL TAKE THE OTHER PATH, added 2026-09-20 because the
         * client found a .docx arriving at the signing panel as a grey box.
         *
         * Their bytes are never sent: no browser renders them, and serving
         * them inline is exactly the hazard DocumentFile::PREVIEWABLE exists
         * to close. What is sent is HTML the server produced from them, under
         * the same deny-all policy below -- which is what makes converted
         * markup from a file somebody else uploaded safe to render at all.
         */
        if ($preview->supports($file->mime_type)) {
            return $this->convertedResponse($request, $document, $file, $preview);
        }

        $contentType = $file->previewContentType();

        // 415, because the refusal is about the TYPE and not the person. The
        // page does not offer a preview button for these, so reaching here
        // means a hand-built URL or a file type that stopped being previewable
        // after the page was rendered.
        abort_if($contentType === null, 415, 'This file type cannot be previewed. Download it instead.');

        // §21: reads are audited, and a preview is a read. Logged as its own
        // type -- somebody who looked at a document on screen did not take a
        // copy away, and a leak investigation cares which one happened.
        SecurityEvent::log(
            SecurityEventType::FilePreviewed,
            sprintf(
                '%s previewed %s v%d.',
                $request->user()->email ?? 'A user',
                $document->control_number,
                $file->version,
            ),
            $request->user(),
            $document->control_number,
        );

        return response()->stream(
            function () use ($file): void {
                $stream = Storage::disk($file->disk)->readStream($file->path);

                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $contentType,

                // The filename is quoted and stripped of anything that could
                // close the quote or start a new header line. It is attacker-
                // influenced -- it comes from an upload -- and it is being
                // interpolated into a header.
                'Content-Disposition' => sprintf('inline; filename="%s"', $this->headerSafeName($file)),

                'X-Content-Type-Options' => 'nosniff',

                // Deny by default and grant nothing back. frame-ancestors is
                // what lets the document page frame this at all; the app's own
                // X-Frame-Options says SAMEORIGIN and this agrees with it.
                'Content-Security-Policy' => implode('; ', [
                    "default-src 'none'",
                    "script-src 'none'",
                    "object-src 'none'",
                    "frame-ancestors 'self'",
                ]),

                // Private, not merely no-cache: these bytes are authorised
                // per-user, so a shared proxy must never keep a copy to hand to
                // the next person who asks for the same URL.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /**
     * The version as a PDF, for placing a signature on.
     *
     * §15 stamping draws the mark at a spot on a page, and the pipeline that
     * does it -- pdf.js to show the pages, pdf-lib to embed the PNG -- needs a
     * PDF. Word and Excel had no pages a browser could point at, so they could
     * be read in the signing panel and not signed on; the client asked for
     * every type to be signable (2026-09-21).
     *
     * Authorised as a PREVIEW, because that is what it is: the same content
     * the signer may already read, in a different shape. It is not a
     * download, and it never becomes a version -- the only thing stored is the
     * stamped PDF the browser posts back.
     */
    public function signable(
        Request $request,
        Document $document,
        DocumentFile $file,
        SignablePdf $signable,
    ): Response {
        $this->authorize('preview', $file);

        abort_unless($file->exists(), 410, 'This version is no longer stored.');
        abort_unless($signable->supports($file), 415, 'This file type cannot be signed on the page.');

        SecurityEvent::log(
            SecurityEventType::FilePreviewed,
            sprintf(
                '%s opened %s v%d to place a signature.',
                $request->user()->email ?? 'A user',
                $document->control_number,
                $file->version,
            ),
            $request->user(),
            $document->control_number,
        );

        return response($signable->bytes($file), 200, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',

            // Fetched by the placer rather than framed, but the same deny-all
            // reasoning applies to bytes built from somebody else's upload.
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "script-src 'none'",
                "object-src 'none'",
                "frame-ancestors 'self'",
            ]),

            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * A Word or Excel version, converted to HTML and served as inertly as its
     * own bytes would have been.
     *
     * The §21 audit line is written here too, and says the same thing: a
     * preview is a read, and this one put the document's contents on somebody's
     * screen just as surely as streaming the PDF would have.
     *
     * `no-store` and `private` for the same reason as the byte path -- the
     * HTML is the document's contents, and a shared proxy keeping a copy is
     * the same leak whichever form it is in.
     */
    private function convertedResponse(
        Request $request,
        Document $document,
        DocumentFile $file,
        OfficeDocumentPreview $preview,
    ): Response {
        SecurityEvent::log(
            SecurityEventType::FilePreviewed,
            sprintf(
                '%s previewed %s v%d (converted for reading).',
                $request->user()->email ?? 'A user',
                $document->control_number,
                $file->version,
            ),
            $request->user(),
            $document->control_number,
        );

        return response($preview->render($file), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',

            // Identical to the byte path's policy, and load-bearing for the
            // same reason: this markup was produced from a file somebody else
            // uploaded, so nothing in it may run, load or fetch anything.
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "script-src 'none'",
                "object-src 'none'",
                "style-src 'unsafe-inline'",
                'img-src data:',
                "frame-ancestors 'self'",
            ]),

            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * A filename safe to interpolate into Content-Disposition.
     *
     * Quotes, backslashes, and anything below 0x20 (which includes CR and LF,
     * the header-splitting pair) are dropped rather than escaped -- a document
     * whose name contains them has bigger problems than a slightly shortened
     * download name.
     */
    private function headerSafeName(DocumentFile $file): string
    {
        $name = preg_replace('/[\x00-\x1f"\\\\]/', '', $file->original_name) ?? '';

        return $name === '' ? "version-{$file->version}" : mb_substr($name, 0, 120);
    }

    public function download(Request $request, Document $document, DocumentFile $file): StreamedResponse
    {
        $this->authorize('download', $file);

        abort_unless($file->exists(), 410, 'This version is no longer stored.');

        // §21: reads are audited too. They go to security_events, NEVER to
        // document_movements -- the custody timeline records transfers, and
        // filling it with downloads would bury the routing history.
        SecurityEvent::log(
            SecurityEventType::FileDownloaded,
            sprintf(
                '%s downloaded %s v%d.',
                $request->user()->email ?? 'A user',
                $document->control_number,
                $file->version,
            ),
            $request->user(),
            $document->control_number,
        );

        return response()->streamDownload(
            function () use ($file): void {
                $stream = Storage::disk($file->disk)->readStream($file->path);

                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $file->original_name,
            [
                'Content-Type' => $file->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
