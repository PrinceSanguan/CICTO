<?php

namespace App\Models;

use App\Enums\OfficeType;
use App\Enums\Role;
use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property OfficeType $type
 * @property int|null $parent_id
 * @property int|null $head_user_id
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'type', 'parent_id', 'head_user_id', 'is_active', 'sort_order'])]
class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => OfficeType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Documents currently held by this office -- the open leg points here.
     *
     * @return HasMany<DocumentMovement, $this>
     */
    public function heldMovements(): HasMany
    {
        return $this->hasMany(DocumentMovement::class, 'to_office_id')->whereNull('departed_at');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('offices.is_active', true);
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('offices.sort_order')->orderBy('offices.name');
    }

    /**
     * Adds `can_receive`: is there anybody here who could take the folder in?
     *
     * WHY THIS EXISTS. §5's department list and §9's send-to list are
     * `Office::active()->ordered()` -- every active office, staffed or not. So
     * forwarding is always possible and RECEIVING IS NOT: DocumentPolicy::view
     * grants office-scoped read to Role::Admin only, and act() needs view()
     * before it looks at anything else. Send a document to an office with no
     * Admin account and it arrives, becomes the open leg, starts counting
     * against its deadline -- and no one on earth can press Received except its
     * own submitter and a Super Admin. The route behind it waits forever.
     *
     * That is the client's "hindi na-rereceive sa pangatlong office", and the
     * office number is a coincidence: it is simply the first stop on their
     * route that nobody works at. Removing the approval gate on 2026-09-03 fixed
     * a different gate with the identical symptom, which is why it looked like
     * the same bug coming back.
     *
     * The state was always knowable and never shown -- OfficeAccountSeeder's
     * docblock has described it in prose since it was written, and the testing
     * guide has to warn testers by hand ("only two of them have a practice
     * account ... or the next step will show you nothing"). This is that warning,
     * moved into the payload the pickers render from.
     *
     * NOT a filter. An office with nobody in it is still a real department and
     * may be staffed next week; hiding it would silently shrink the client's own
     * org chart and give no clue why. It is labelled, and the send goes through.
     *
     * Super Admins are deliberately not counted: they have no office_id, so
     * "somebody could theoretically open it" is true of every office at once and
     * says nothing about whether THIS one can do its job.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withReceiver(Builder $query): void
    {
        $query->withExists(['users as can_receive' => function (Builder $users): void {
            $users->where('users.role', Role::Admin->value)
                ->where('users.is_active', true);
        }]);
    }
}
