<?php

namespace App\Models;

use App\Policies\DocumentFilePolicy;
use Database\Factories\DocumentFileFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An immutable, append-only file version.
 *
 * Rows are never updated and never soft-deleted; a correction is a new row with
 * the next version number. Phase 3 retention may purge the BYTES of
 * intermediate versions, setting purged_at while keeping the row, so the
 * version history never develops holes.
 *
 * @property int $id
 * @property int $document_id
 * @property int $version
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property int|null $uploaded_by_id
 * @property int|null $document_movement_id
 * @property string|null $replace_reason
 * @property Carbon|null $purged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(DocumentFilePolicy::class)]
class DocumentFile extends Model
{
    /** @use HasFactory<DocumentFileFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'version' => 'integer',
            'purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /** @return BelongsTo<DocumentMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(DocumentMovement::class, 'document_movement_id');
    }

    /**
     * Signatures bound to this exact version.
     *
     * Consulted by the retention pruner: a signed version's bytes are never
     * purged, or its certificate would keep reporting "valid" for a file that
     * no longer exists.
     *
     * @return HasMany<DocumentSignature, $this>
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class, 'document_file_id');
    }

    /**
     * The ONLY types this application will serve inline, and the exact
     * Content-Type it will serve each one as.
     *
     * This is a security boundary, not a convenience list. Uploads are served
     * from the application's own origin, so anything the browser renders inline
     * there runs as the app: an HTML or SVG payload could read the session
     * cookie and act as whoever opened it. That is why every attachment has
     * always been forced to download, and why previewing has to be a CLOSED
     * allowlist rather than "inline unless it looks dangerous".
     *
     * Three types, all inert renderers. Deliberately NARROWER than
     * cicto.uploads.mimes, which also accepts Word and Excel -- no browser
     * renders those anyway, so nothing is lost by refusing them here.
     *
     * The VALUE is what gets sent, never the stored mime_type string. The
     * stored one is sniffed from the bytes at upload time and is trustworthy
     * enough to route on, but it is still a database column, and a column is
     * the kind of thing that gets edited.
     *
     * @var array<string, string>
     */
    public const PREVIEWABLE = [
        'application/pdf' => 'application/pdf',
        'image/png' => 'image/png',
        'image/jpeg' => 'image/jpeg',
    ];

    /**
     * Can this version be shown in the browser rather than downloaded?
     *
     * Says nothing about whether the bytes still exist -- same rule as
     * DocumentFilePolicy::download. A purged version is GONE, not FORBIDDEN,
     * and the controller answers 410 for it.
     */
    public function isPreviewable(): bool
    {
        return array_key_exists((string) $this->mime_type, self::PREVIEWABLE);
    }

    /** The Content-Type to serve inline, or null if this type is not on the list. */
    public function previewContentType(): ?string
    {
        return self::PREVIEWABLE[(string) $this->mime_type] ?? null;
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    public function exists(): bool
    {
        return ! $this->isPurged() && Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * SHA-256 of the bytes actually on disk right now.
     *
     * Used by the nightly signature sweep -- comparing two database columns
     * cannot detect someone replacing the file and leaving the row alone.
     */
    public function hashOnDisk(): ?string
    {
        if ($this->isPurged()) {
            return null;
        }

        $disk = Storage::disk($this->disk);

        if (! $disk->exists($this->path)) {
            return null;
        }

        $stream = $disk->readStream($this->path);

        if ($stream === null) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

        return match (true) {
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
