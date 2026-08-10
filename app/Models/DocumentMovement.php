<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\MovementAction;
use App\Support\Database\Duration;
use Database\Factories\DocumentMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One row = one leg = one period of custody.
 *
 * This is the ledger: routing record (§9) and audit trail (§13) in one table.
 * Rows are written exclusively by App\Actions\Documents\TransitionDocument. No
 * controller may insert here, and nothing else may write documents.status --
 * that one rule is what keeps the trail honest.
 *
 * @property int $id
 * @property int $document_id
 * @property int $sequence
 * @property int|null $from_office_id
 * @property int|null $to_office_id
 * @property int|null $actor_id
 * @property MovementAction $action
 * @property DocumentStatus|null $from_status
 * @property DocumentStatus|null $to_status
 * @property string|null $remarks
 * @property Carbon|null $arrived_at
 * @property Carbon|null $departed_at
 * @property Carbon|null $due_at
 * @property int|null $is_open
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentMovement extends Model
{
    /** @use HasFactory<DocumentMovementFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => MovementAction::class,
            'from_status' => DocumentStatus::class,
            'to_status' => DocumentStatus::class,
            'arrived_at' => 'datetime',
            'departed_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function fromOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'from_office_id');
    }

    /** @return BelongsTo<Office, $this> */
    public function toOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'to_office_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return HasMany<DocumentComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(DocumentComment::class);
    }

    /**
     * The decision remark recorded against this leg, if any.
     *
     * @return HasOne<DocumentComment, $this>
     */
    public function comment(): HasOne
    {
        return $this->hasOne(DocumentComment::class);
    }

    /**
     * Legs whose custody has ended -- the only ones with a measurable dwell.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function closed(Builder $query): void
    {
        $query->whereNotNull('document_movements.departed_at');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('document_movements.departed_at');
    }

    /**
     * How long this leg held the document, in whole minutes.
     *
     * Null while the leg is still open: the folder in your hand has not finished
     * sitting anywhere yet.
     */
    public function dwellMinutes(): ?int
    {
        if ($this->arrived_at === null || $this->departed_at === null) {
            return null;
        }

        return (int) $this->arrived_at->diffInMinutes($this->departed_at);
    }

    /**
     * SQL expression for cross-document dwell aggregates (§19 average
     * processing time per office). Per-document timelines use PHP instead.
     */
    public static function dwellExpression(): string
    {
        return Duration::minutesBetween(
            'document_movements.arrived_at',
            'document_movements.departed_at',
        );
    }
}
