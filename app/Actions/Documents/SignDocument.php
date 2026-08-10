<?php

namespace App\Actions\Documents;

use App\Enums\SecurityEventType;
use App\Enums\SignatureMethod;
use App\Exceptions\AlreadySignedException;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Deadlines;
use Illuminate\Http\Request;
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
 * chain, no revocation list, and nothing is embedded into the PDF itself. If a
 * document ever needs a signature certified for a court or a national agency,
 * that is PNPKI and separate work. The paragraph to send the client before
 * building any of this is in docs/implementation/phase-3-trust-and-toolchain.md.
 */
final class SignDocument
{
    public function handle(
        Document $document,
        User $signer,
        SignatureMethod $method,
        ?string $drawnPng = null,
        string $purpose = DocumentSignature::PURPOSE_APPROVAL,
        ?Request $request = null,
    ): DocumentSignature {
        return DB::transaction(function () use ($document, $signer, $method, $drawnPng, $purpose, $request): DocumentSignature {
            // Lock the document so a version cannot be uploaded between us
            // reading the current file and binding its hash.
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            $file = $locked->currentFile()->first();
            $openLeg = $locked->openMovement;

            $serial = mb_strtolower((string) Str::ulid());
            $signedAt = Deadlines::now();

            // Checked BEFORE the image is written, so a duplicate never leaves
            // an orphaned PNG behind and never surfaces as a raw 500.
            $already = DocumentSignature::query()
                ->where('document_file_id', $file?->id)
                ->where('user_id', $signer->id)
                ->where('purpose', $purpose)
                ->exists();

            if ($already) {
                throw new AlreadySignedException;
            }

            $imagePath = null;

            if ($method === SignatureMethod::Drawn) {
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

                'purpose' => $purpose,
                'method' => $method->value,
                'image_disk' => $imagePath === null ? null : 'documents',
                'image_path' => $imagePath,
                'document_hash_sha256' => $documentHash,
                'signature_hash_sha256' => $hash,
                'ip_address' => $request?->ip(),
                'signed_at' => $signedAt,
            ]);

            SecurityEvent::log(
                SecurityEventType::DocumentSigned,
                sprintf('%s signed %s (%s).', $signer->name, $locked->control_number, $purpose),
                $signer,
                $locked->control_number,
            );

            return $signature;
        });
    }

    /**
     * Drawn marks arrive as a base64 data URL from a canvas.
     *
     * Validated by magic bytes rather than by trusting the declared MIME: this
     * ends up on disk and is rendered back into a PDF, so "it said it was a
     * PNG" is not good enough.
     */
    private function storeSignatureImage(Document $document, string $serial, ?string $dataUrl): string
    {
        if ($dataUrl === null || $dataUrl === '') {
            throw new RuntimeException('A drawn signature requires an image.');
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
