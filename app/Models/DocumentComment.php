<?php

namespace App\Models;

use App\Policies\DocumentCommentPolicy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * §16 comments and §9 approval remarks -- one table, one panel.
 *
 * A decision remark is written here AND mirrored into the movement's `remarks`
 * in the same transaction. Only context='comment' rows are editable, so the two
 * copies cannot diverge.
 *
 * @property int $id
 * @property int $document_id
 * @property int|null $user_id
 * @property int|null $document_movement_id
 * @property int|null $parent_id
 * @property string $context
 * @property string $body
 * @property bool $is_internal
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(DocumentCommentPolicy::class)]
class DocumentComment extends Model
{
    public const CONTEXT_COMMENT = 'comment';

    public const CONTEXT_APPROVAL = 'approval';

    public const CONTEXT_REJECTION = 'rejection';

    public const CONTEXT_RETURN = 'return';

    /** Remarks attached to a routing action (forward, receive, complete). */
    public const CONTEXT_MOVEMENT = 'movement';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<DocumentMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(DocumentMovement::class, 'document_movement_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * A decision remark is part of the ledger. It has no edit route, because the
     * movement holds an immutable copy of the same text.
     */
    public function isDecisionRemark(): bool
    {
        return $this->context !== self::CONTEXT_COMMENT;
    }

    public function isEditable(): bool
    {
        return ! $this->isDecisionRemark();
    }

    /**
     * Internal notes are hidden from the person who submitted the document.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleToSubmitter(Builder $query): void
    {
        $query->where('document_comments.is_internal', false);
    }

    /** @return array<int, string> */
    public static function contexts(): array
    {
        return [
            self::CONTEXT_COMMENT,
            self::CONTEXT_APPROVAL,
            self::CONTEXT_REJECTION,
            self::CONTEXT_RETURN,
            self::CONTEXT_MOVEMENT,
        ];
    }
}
