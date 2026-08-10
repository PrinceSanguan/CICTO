<?php

namespace App\Models;

use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use App\Enums\DueState;
use App\Models\Builders\DocumentBuilder;
use App\Policies\DocumentPolicy;
use App\Support\Deadlines;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The aggregate root.
 *
 * Note what is absent: no current_office_id, no file_path, no dwell_minutes, no
 * is_overdue. All four are derivable, and all four drift.
 *
 * @property int $id
 * @property string $control_number
 * @property string $qr_token
 * @property string $title
 * @property string|null $description
 * @property string|null $remarks
 * @property int $document_type_id
 * @property int $originating_office_id
 * @property int $created_by_id
 * @property DocumentStatus $status
 * @property DocumentPriority $priority
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $deadline_warned_at
 * @property Carbon|null $overdue_notified_at
 * @property Carbon|null $archived_at
 * @property int|null $archived_by_id
 * @property string|null $archive_reason
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseEloquentBuilder(DocumentBuilder::class)]
#[UsePolicy(DocumentPolicy::class)]
#[Fillable([
    'title', 'description', 'remarks', 'document_type_id',
    'originating_office_id', 'priority',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'priority' => DocumentPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'deadline_warned_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The QR token, not the id, so a scan URL is unguessable.
     *
     * Route-model binding for the public scan path uses this explicitly via
     * {document:qr_token}; the key itself stays the id everywhere else.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    // ---------------------------------------------------------------- relations

    /** @return BelongsTo<DocumentType, $this> */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function originatingOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'originating_office_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_id');
    }

    /** @return HasMany<DocumentMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(DocumentMovement::class)->orderBy('sequence');
    }

    /**
     * The single leg currently holding this document.
     *
     * Guaranteed at most one row by unique(document_id, is_open).
     *
     * @return HasOne<DocumentMovement, $this>
     */
    public function openMovement(): HasOne
    {
        return $this->hasOne(DocumentMovement::class)->whereNull('departed_at');
    }

    /** @return HasMany<DocumentFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class)->orderByDesc('version');
    }

    /**
     * Current file is MAX(version). No is_current flag -- that needs a second
     * write on every upload and is the classic source of "two current files".
     *
     * @return HasOne<DocumentFile, $this>
     */
    public function currentFile(): HasOne
    {
        return $this->hasOne(DocumentFile::class)->latestOfMany('version');
    }

    /** @return HasMany<DocumentComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(DocumentComment::class);
    }

    /** @return HasMany<DocumentSignature, $this> */
    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class)->orderByDesc('signed_at');
    }

    /** @return HasMany<DocumentScan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(DocumentScan::class);
    }

    // ---------------------------------------------------------------- accessors

    /**
     * §11 due state, derived and never stored.
     *
     * The PHP twin of the overdue/approachingDeadline query scopes -- both read
     * their boundary from Deadlines, so a row's badge can never disagree with
     * the query that selected it.
     *
     * A plain method rather than an Eloquent accessor: Attribute<TGet, TSet> is
     * invariant, so an accessor returning a union of enum cases cannot satisfy
     * its own declared generic.
     */
    public function dueState(): DueState
    {
        return match (true) {
            $this->completed_at !== null || $this->status->isTerminal() => DueState::Closed,
            $this->due_at === null => DueState::None,
            $this->due_at->lt(Deadlines::now()) => DueState::Overdue,
            $this->due_at->lte(Deadlines::warnBoundary()) => DueState::Approaching,
            default => DueState::OnTrack,
        };
    }

    /** Which office holds it right now (§10). Derived from the open leg. */
    public function currentOffice(): ?Office
    {
        return $this->openMovement?->toOffice;
    }

    /**
     * §10 "how long it has been at the current office", in whole minutes.
     *
     * Computed in PHP: there is at most one open leg, so there is nothing to
     * aggregate, and PHP keeps Carbon::setTestNow() working. SQL duration
     * arithmetic is reserved for cross-document averages.
     */
    public function minutesAtCurrentOffice(): ?int
    {
        $leg = $this->openMovement;

        if ($leg === null || $leg->arrived_at === null) {
            return null;
        }

        return (int) $leg->arrived_at->diffInMinutes(Deadlines::now());
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isOpen(): bool
    {
        return ! $this->status->isTerminal() && $this->completed_at === null;
    }
}
