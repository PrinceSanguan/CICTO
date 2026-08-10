<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * §7 scan log. A lookup, not a transfer -- deliberately outside the ledger.
 *
 * Holds ip_address and user_agent, which are personal information under
 * RA 10173. Retention is configured in config/cicto.php and stated in the
 * privacy notice; pruning stays disabled until the client agrees a policy.
 *
 * @property int $id
 * @property int $document_id
 * @property int|null $user_id
 * @property int|null $office_id
 * @property string $source
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $scanned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentScan extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * Anonymous scans are couriers and citizens; they see the reduced view.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function anonymous(Builder $query): void
    {
        $query->whereNull('document_scans.user_id');
    }
}
