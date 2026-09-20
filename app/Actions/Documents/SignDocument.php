<?php

namespace App\Actions\Documents;

use App\Enums\SecurityEventType;
use App\Enums\SignatureMethod;
use App\Exceptions\AlreadySignedException;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentSignature;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Deadlines;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * §15. The only writer of document_signatures.
 *
 * What this produces is an ELECTRONIC signature: signer identity, a captured
 * mark, a timestamp, and a hash binding the whole thing to the exact file
 * version that was on screen. Under RA 8792 an electronic signature can be
 * given legal effect when the method used can identify the party and indicate
 * their assent -- which is why the signing route sits behind a password
 * confirmation, and why the signer's office and position are snapshotted.
 *
 * What it is NOT: PKI. There is no certificate authority, no certificate
 * chain and no revocation list. If a document ever needs a signature certified
 * for a court or a national agency, that is PNPKI and separate work. The
 * paragraph to send the client before building any of this is in
 * docs/implementation/phase-3-trust-and-toolchain.md.
 *
 * SINCE 2026-09-20 THE MARK IS ALSO PRINTED ONTO THE PAGE, because the client
 * asked for a signature that looks like ink on paper. That is a VISUAL stamp,
 * not a PAdES embedded signature: it carries no cryptography of its own, and
 * the thing that makes it checkable is still the row written here.
 *
 * Stamping appends a NEW VERSION rather than rewriting the signed one. Three
 * consequences worth stating, because each is a question somebody will ask:
 *
 *  1. The signature still binds to the version that was READ (document_file_id
 *     and document_hash_sha256 are unchanged). The produced version is
 *     recorded separately, as stamped_file_id.
 *  2. Nothing is overwritten, so the unsigned original stays downloadable and
 *     the tamper check keeps working on the bytes it was made against.
 *  3. Each office in the route signs the version the previous office produced,
 *     so the marks accumulate down the page exactly as they would on paper.
 *
 * THE STAMPED BYTES ARE PRODUCED BY THE SIGNER'S BROWSER, not here. No free
 * PHP library can reliably rewrite PDF 1.5+ object streams, which is most
 * modern PDFs, so server-side stamping would mean silently corrupting some
 * uploads. pdf-lib in the browser handles them. The cost is that this action
 * accepts a PDF the client composed: it is gated on the same policy as signing,
 * it is validated as a real PDF, and the version it replaces is never deleted --
 * so a substitution is visible by comparing two versions that both still exist.
 */
final class SignDocument
{
    /**
     * @param  array{page: int, x: float, y: float, width: float, height: float}|null  $placement
     *                                                                                             Where the signer put the mark, as fractions of the displayed
     *                                                                                             page. Required with $stampedPdf and meaningless without it.
     * @param  UploadedFile|null  $stampedPdf
     *                                         The signed version the browser composed. Appended as a new
     *                                         version; never written over the one that was signed.
     */
    public function handle(
        Document $document,
        User $signer,
        SignatureMethod $method,
        ?string $drawnPng = null,
        string $purpose = DocumentSignature::PURPOSE_APPROVAL,
        ?Request $request = null,
        ?array $placement = null,
        ?UploadedFile $stampedPdf = null,
    ): DocumentSignature {
        return DB::transaction(function () use ($document, $signer, $method, $drawnPng, $purpose, $request, $placement, $stampedPdf): DocumentSignature {
            // Lock the document so a version cannot be uploaded between us
            // reading the current file and binding its hash.
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            $file = $locked->currentFile()->first();
            $openLeg = $locked->openMovement;

            $serial = mb_strtolower((string) Str::ulid());
            $signedAt = Deadlines::now();

            /*
             * ONE SIGNATURE PER OFFICE -- the client's rule of 2026-09-20.
             *
             * Checked BEFORE the image is written, so a duplicate never leaves
             * an orphaned PNG behind and never surfaces as a raw 500.
             *
             * Three things this question is NOT, each of which it used to be:
             *
             *  - not "per person". An office speaks with one voice on a
             *    document; its head signing after its clerk already did is two
             *    marks for one decision. Whoever signs first signs FOR the
             *    office, and the others are refused.
             *
             *  - not "against the current file". Stamping makes a new version
             *    out of the act of signing, so a second attempt would find
             *    itself looking at a version nobody had signed yet and be
             *    waved straight through. The baseline is the last version
             *    somebody UPLOADED, which a stamp never moves and a genuine
             *    correction does -- so a corrected file correctly reopens
             *    signing for an office that had already signed the old one.
             *
             *  - not matched on the office NAME. `signer_office` is a snapshot
             *    kept for the printed certificate; renaming an office would
             *    otherwise hand it a second signature.
             *
             * A signer with no office -- a Super Admin -- is asked about as
             * themselves instead, because "their office has signed" is not a
             * question that means anything for them.
             */
            $baseline = $this->lastUploadedVersion($locked);

            $already = DocumentSignature::query()
                ->where('document_id', $locked->id)
                ->where('purpose', $purpose)
                ->when(
                    $signer->office_id !== null,
                    fn (Builder $query) => $query->where('office_id', $signer->office_id),
                    fn (Builder $query) => $query->where('user_id', $signer->id),
                )
                ->where(function (Builder $query) use ($baseline): void {
                    $query
                        ->whereNull('document_file_id')
                        ->orWhereHas(
                            'file',
                            fn (Builder $file) => $file->where('version', '>=', $baseline),
                        );
                })
                ->exists();

            if ($already) {
                throw new AlreadySignedException;
            }

            $imagePath = null;

            // Drawn or uploaded: both arrive as a PNG the browser rendered.
            if ($method->requiresImage()) {
                $imagePath = $this->storeSignatureImage($locked, $serial, $drawnPng);
            }

            $documentHash = $file?->checksum_sha256;

            // Plain SHA-256 over a versioned canonical payload -- NOT an HMAC
            // keyed on APP_KEY. Keying it would mean that losing or rotating
            // the key makes every historical signature unverifiable, and the
            // backup runbook already flags APP_KEY as a single point of loss.
            $signerName = $signer->name;
            $signerPosition = $signer->position;
            $signerOffice = $signer->office?->name;

            $hash = hash('sha256', DocumentSignature::canonicalPayload(
                $serial,
                $locked->id,
                $file?->id,
                $documentHash,
                $signer->id,
                $purpose,
                $signedAt->toIso8601String(),
                $signerName,
                $signerPosition,
                $signerOffice,
                $method->value,
            ));

            $signature = DocumentSignature::create([
                'serial' => $serial,
                'document_id' => $locked->id,
                'document_movement_id' => $openLeg?->id,
                'document_file_id' => $file?->id,
                'user_id' => $signer->id,

                // Snapshotted on purpose: a signature is an attestation made by
                // a person holding a role on a date, and it has to still read
                // correctly after they transfer or are renamed.
                'signer_name' => $signerName,
                'signer_position' => $signerPosition,
                'signer_office' => $signerOffice,

                // The office as an ID as well as a name. The name is the
                // snapshot a certificate prints; this is what "one signature
                // per office" is actually asked about. See the migration.
                'office_id' => $signer->office_id,

                'purpose' => $purpose,
                'method' => $method->value,
                'image_disk' => $imagePath === null ? null : 'documents',
                'image_path' => $imagePath,

                // Where the mark was put, or nulls when it was only filed.
                // Kept even if the stamped version is later purged, so the
                // register can still describe the signature it recorded.
                'stamp_page' => $placement['page'] ?? null,
                'stamp_x' => $placement['x'] ?? null,
                'stamp_y' => $placement['y'] ?? null,
                'stamp_width' => $placement['width'] ?? null,
                'stamp_height' => $placement['height'] ?? null,
                'document_hash_sha256' => $documentHash,
                'signature_hash_sha256' => $hash,
                'ip_address' => $request?->ip(),
                'signed_at' => $signedAt,
            ]);

            /*
             * The stamped version is appended AFTER the row exists, so its
             * replace_reason can name the signature that produced it. Inside
             * the same transaction: a stamped file with no signature row would
             * be an unexplained version of a government document, and a
             * signature claiming a stamp that was never written would be worse.
             *
             * StoreDocumentFile opens its own transaction and takes the same
             * row lock. Both nest as savepoints on the one already held here,
             * and re-locking a row this transaction already locked is free.
             */
            if ($stampedPdf !== null) {
                $stamped = app(StoreDocumentFile::class)->handle(
                    document: $locked,
                    upload: $stampedPdf,
                    uploader: $signer,
                    movement: $openLeg,
                    replaceReason: sprintf(
                        'Signed by %s%s. Signature serial %s.',
                        $signerName,
                        $signerOffice === null ? '' : ' ('.$signerOffice.')',
                        $serial,
                    ),
                );

                /*
                 * ONLY IF IT REALLY IS A NEW VERSION.
                 *
                 * StoreDocumentFile dedupes byte-identical bytes back onto the
                 * current row instead of manufacturing a version -- right on
                 * its own, and what stops a double-submitted form growing the
                 * version list. But a "stamped" PDF equal to what was signed
                 * comes back as THE SAME ROW, and recording it here would make
                 * the signature claim the file it was signed against as its
                 * own output. UndoSignature would then delete the document's
                 * only file, bytes and all, when the mark was withdrawn.
                 *
                 * Found in QA on 2026-09-20. A browser that decoded the PDF
                 * but failed to draw the mark produces exactly these bytes, so
                 * this is a real path and not a theoretical one.
                 *
                 * Left null rather than refused: the signature itself is
                 * sound -- the signer read that version and attested to it --
                 * and it is recorded as an ordinary unstamped one, which is
                 * what actually happened.
                 */
                if ($stamped->id !== $signature->document_file_id) {
                    $signature->forceFill(['stamped_file_id' => $stamped->id])->save();
                }
            }

            SecurityEvent::log(
                SecurityEventType::DocumentSigned,
                sprintf(
                    '%s signed %s (%s)%s.',
                    $signer->name,
                    $locked->control_number,
                    $purpose,
                    $stampedPdf === null ? '' : ', stamping the mark onto page '.($placement['page'] ?? '?'),
                ),
                $signer,
                $locked->control_number,
            );

            return $signature;
        });
    }

    /**
     * The baseline the already-signed guard counts from: the newest version
     * somebody actually UPLOADED, ignoring the ones stamping produced.
     *
     * Versions born of a signature are not new content -- they are the same
     * document with one more mark on it -- so they must not reopen signing for
     * someone who has already signed. A real upload (a corrected document)
     * must. DocumentSignature::isSuperseded() draws the same line, which is
     * why both ask DocumentFile the one question.
     *
     * Returns 0 when nothing is attached, which makes the guard's `version >=
     * 0` match every signature the signer has on this document. That is the
     * right answer: with no file there is no content to have been corrected.
     */
    private function lastUploadedVersion(Document $document): int
    {
        return DocumentFile::lastUploadedVersion($document->id);
    }

    /**
     * Drawn marks arrive as a base64 data URL from a canvas -- and so do
     * uploaded ones, which the browser redraws onto a canvas before sending.
     *
     * Validated by magic bytes rather than by trusting the declared MIME: this
     * ends up on disk and is rendered back into a PDF, so "it said it was a
     * PNG" is not good enough.
     */
    private function storeSignatureImage(Document $document, string $serial, ?string $dataUrl): string
    {
        if ($dataUrl === null || $dataUrl === '') {
            throw new RuntimeException('A drawn or uploaded signature requires an image.');
        }

        $encoded = preg_replace('#^data:image/png;base64,#i', '', trim($dataUrl));
        $binary = base64_decode((string) $encoded, true);

        if ($binary === false || $binary === '') {
            throw new RuntimeException('The signature image could not be decoded.');
        }

        // PNG magic number. Anything else is refused outright -- notably SVG,
        // which would be a stored-XSS vector the moment it is rendered.
        if (! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('The signature image must be a PNG.');
        }

        if (strlen($binary) > 512_000) {
            throw new RuntimeException('The signature image is too large.');
        }

        $path = "signatures/{$document->id}/{$serial}.png";
        Storage::disk('documents')->put($path, $binary);

        return $path;
    }
}
