<?php

namespace App\Models;

use App\Enums\SecurityEventType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Request;

/**
 * Non-document audit events. See the D1 amendment in 00-architecture.md.
 *
 * Append-only: no updated_at, no update path, no delete path outside the
 * retention pruner.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $actor_label
 * @property SecurityEventType $type
 * @property string $summary
 * @property string|null $subject_label
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SecurityEventType::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The one writer.
     *
     * Never throws: an audit write must not be able to fail the action it is
     * describing. A lost log line is bad; a failed password reset because the
     * log line failed is worse.
     */
    public static function log(
        SecurityEventType $type,
        string $summary,
        ?User $actor = null,
        ?string $subjectLabel = null,
    ): void {
        try {
            static::create([
                'user_id' => $actor?->id,
                'actor_label' => mb_substr((string) ($actor->email ?? 'system'), 0, 191),
                'type' => $type->value,
                'summary' => mb_substr($summary, 0, 255),
                'subject_label' => $subjectLabel === null ? null : mb_substr($subjectLabel, 0, 191),
                'ip_address' => Request::ip(),
                'user_agent' => mb_substr((string) Request::userAgent(), 0, 191) ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function ofType(Builder $query, SecurityEventType ...$types): void
    {
        $query->whereIn('security_events.type', array_map(
            static fn (SecurityEventType $t) => $t->value,
            $types,
        ));
    }
}
