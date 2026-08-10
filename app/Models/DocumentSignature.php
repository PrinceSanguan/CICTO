<?php

namespace App\Models;

use App\Enums\SignatureMethod;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * §15. An electronic signature -- see the migration for what that does and does
 * not mean.
 *
 * Written only by App\Actions\Documents\SignDocument. Rows are never updated:
 * re-signing a corrected file is a new row against a new document_file_id.
 *
 * @property int $id
 * @property string $serial
 * @property int $document_id
 * @property int|null $document_movement_id
 * @property int|null $document_file_id
 * @property int $user_id
 * @property string $signer_name
 * @property string|null $signer_position
 * @property string|null $signer_office
 * @property string $purpose
 * @property SignatureMethod $method
 * @property string|null $image_disk
 * @property string|null $image_path
 * @property string|null $document_hash_sha256
 * @property string $signature_hash_sha256
 * @property string|null $ip_address
 * @property Carbon $signed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentSignature extends Model
{
    public const PURPOSE_APPROVAL = 'approval';

    /**
     * Bumped if the canonical payload format ever changes, so old signatures
     * stay verifiable under the rules they were made with instead of silently
     * failing against new ones.
     */
    public const PAYLOAD_VERSION = 'v2';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => SignatureMethod::class,
            'signed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'serial';
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<DocumentFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(DocumentFile::class, 'document_file_id');
    }

    /** @return BelongsTo<DocumentMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(DocumentMovement::class, 'document_movement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The exact string that gets hashed.
     *
     * Order and separator are part of the contract -- change either and every
     * existing signature stops verifying, which is what PAYLOAD_VERSION is for.
     */
    public static function canonicalPayload(
        string $serial,
        int $documentId,
        ?int $documentFileId,
        ?string $documentHash,
        int $userId,
        string $purpose,
        string $signedAtIso,
        string $signerName = '',
        ?string $signerPosition = null,
        ?string $signerOffice = null,
        string $method = '',
    ): string {
        // The identity fields are IN the hash, not merely stored alongside it.
        // They are what the Signature Certificate prints and what a reader
        // relies on; leaving them out meant an UPDATE on signer_name produced
        // a certificate naming someone else that still verified as valid.
        return implode('|', [
            self::PAYLOAD_VERSION,
            $serial,
            (string) $documentId,
            (string) ($documentFileId ?? 0),
            $documentHash ?? '',
            (string) $userId,
            $purpose,
            $signedAtIso,
            $signerName,
            $signerPosition ?? '',
            $signerOffice ?? '',
            $method,
        ]);
    }

    /** Recompute the hash from the stored fields and compare. */
    public function selfHashMatches(): bool
    {
        $expected = hash('sha256', self::canonicalPayload(
            $this->serial,
            $this->document_id,
            $this->document_file_id,
            $this->document_hash_sha256,
            $this->user_id,
            $this->purpose,
            $this->signed_at->toIso8601String(),
            $this->signer_name,
            $this->signer_position,
            $this->signer_office,
            $this->method->value,
        ));

        return hash_equals($expected, $this->signature_hash_sha256);
    }

    /**
     * Whether the file still hashes to what was signed.
     *
     * This is the whole point of the feature: tampering is DETECTABLE. It is
     * not preventable -- nothing here stops someone replacing bytes, it only
     * makes the substitution visible.
     */
    public function fileHashMatches(bool $rehashBytes = false): bool
    {
        if ($this->document_hash_sha256 === null) {
            // Nothing was attached when this was signed. If a file has since
            // appeared, the signature no longer describes the document -- it
            // must not keep reporting a clean bill of health.
            return ! DocumentFile::query()
                ->where('document_id', $this->document_id)
                ->exists();
        }

        $file = $this->file;

        if ($file === null) {
            return false; // the version it was bound to is gone
        }

        if (! hash_equals($file->checksum_sha256, $this->document_hash_sha256)) {
            return false;
        }

        // The column comparison above catches a rewritten database row. It does
        // NOT catch someone replacing the bytes on disk and leaving the row
        // alone, so the nightly sweep re-hashes the actual file. Page renders
        // skip it -- hashing every attachment on every view would be absurd.
        if ($rehashBytes && ! $file->isPurged()) {
            $actual = $file->hashOnDisk();

            if ($actual === null || ! hash_equals($actual, $this->document_hash_sha256)) {
                return false;
            }
        }

        return true;
    }

    public function isValid(bool $rehashBytes = false): bool
    {
        return $this->selfHashMatches() && $this->fileHashMatches($rehashBytes);
    }

    /**
     * A signature is superseded when a newer version of the file exists.
     * Derived from version order -- never stored, so it cannot go stale.
     */
    public function isSuperseded(): bool
    {
        $file = $this->file;

        if ($file === null) {
            // Signing a fileless document is refused now, but rows written
            // before that guard existed still have to answer honestly: any file
            // at all supersedes a signature that was bound to none.
            return DocumentFile::query()
                ->where('document_id', $this->document_id)
                ->exists();
        }

        return DocumentFile::query()
            ->where('document_id', $file->document_id)
            ->where('version', '>', $file->version)
            ->exists();
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function forDocument(Builder $query, int $documentId): void
    {
        $query->where('document_signatures.document_id', $documentId);
    }
}
