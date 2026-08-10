<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * §12 in-app notification.
 *
 * NOT a Laravel database-channel notification -- this table has real foreign
 * keys and pre-rendered strings instead of a morph pair and a json blob. See
 * the migration for why.
 *
 * Title and body are frozen at write time: a notification records what was true
 * when it fired, not what is true when it is read.
 *
 * @property int $id
 * @property int $user_id
 * @property int $document_id
 * @property int|null $document_movement_id
 * @property NotificationType $type
 * @property string $dedupe_key
 * @property string $title
 * @property string|null $body
 * @property string $control_number
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Notification extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<DocumentMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(DocumentMovement::class, 'document_movement_id');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function unread(Builder $query): void
    {
        $query->whereNull('notifications.read_at');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function forUser(Builder $query, User $user): void
    {
        $query->where('notifications.user_id', $user->id);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
