<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-office, per-year control number counter.
 *
 * Written only by App\Actions\Documents\AllocateControlNumber, under
 * SELECT ... FOR UPDATE inside the registration transaction.
 *
 * @property int $id
 * @property int $office_id
 * @property int $period_year
 * @property int $last_number
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentNumberSequence extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'last_number' => 'integer',
        ];
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
