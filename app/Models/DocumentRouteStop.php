<?php

namespace App\Models;

use App\Enums\RouteStopStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One office still to be visited on a document's routing plan.
 *
 * A PLAN, not a ledger entry. Nothing here is an audit record and nothing here
 * grants access: a queued office cannot see the document until it arrives,
 * because DocumentBuilder::visibleTo() reads document_movements and no movement
 * row names them yet. See the migration for why the queue is not stored there.
 *
 * @property int $id
 * @property int $document_id
 * @property int $position
 * @property int $office_id
 * @property RouteStopStatus $status
 * @property int|null $created_by_id
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentRouteStop extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RouteStopStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', RouteStopStatus::Pending);
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position');
    }
}
