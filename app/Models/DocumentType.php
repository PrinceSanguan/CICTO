<?php

namespace App\Models;

use Database\Factories\DocumentTypeFactory;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * §6 classification lookup, and the §11 SLA source.
 *
 * turnaround_days living here is what lets deadline monitoring ship in Phase 2
 * with no migration of its own.
 *
 * Two kinds of row since 2026-10-03, and both are edited on the Super
 * Admin's Document Types page (built-in ones since 2026-10-04):
 *
 *  - BUILT-IN: the 43 in DocumentTypeSeeder. The seeder makes them and gives
 *    each the route it starts with, from App\Support\RouteTemplates. A Super
 *    Admin may then change one; `customized_at` and `route_customized_at`
 *    say which part, and the seeder leaves that part alone from then on.
 *  - CUSTOM (`is_custom`): made on the page. The seeder never touches one.
 *
 * Either way, routeSteps() is the route the Submit form offers -- the
 * database is the source of truth, and RouteTemplates only holds the
 * originals.
 *
 * `is_custom` and the two timestamps are deliberately not fillable: they
 * decide what the seeder may overwrite, so only ManageDocumentType and the
 * seeder set them.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int|null $turnaround_days
 * @property bool $requires_approval
 * @property bool $is_confidential
 * @property bool $allows_broadcast
 * @property bool $is_custom
 * @property string|null $route_note
 * @property Carbon|null $customized_at
 * @property Carbon|null $route_customized_at
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'turnaround_days', 'requires_approval', 'is_confidential', 'allows_broadcast', 'is_active', 'sort_order'])]
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Where a Super Admin's own types sort: after the 43 built-in ones (10 to
     * 430), alphabetically among themselves. Mixing them in would mean
     * renumbering the built-ins, which the seeder owns.
     */
    public const CUSTOM_SORT_ORDER = 1000;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'turnaround_days' => 'integer',
            'requires_approval' => 'boolean',
            'is_confidential' => 'boolean',
            'allows_broadcast' => 'boolean',
            'is_custom' => 'boolean',
            'is_active' => 'boolean',
            'customized_at' => 'datetime',
            'route_customized_at' => 'datetime',
        ];
    }

    /** One of DocumentTypeSeeder's 43, rather than a Super Admin's own. */
    public function isBuiltIn(): bool
    {
        return ! $this->is_custom && in_array($this->code, DocumentTypeSeeder::builtInCodes(), true);
    }

    /**
     * Can the Document Types page change it at all? Every type but the
     * placeholders the seeder retired in 2026-08 (LETTER, PR, ORD, CLR),
     * which it deactivates again on every deploy.
     */
    public function isEditable(): bool
    {
        return $this->is_custom || $this->isBuiltIn();
    }

    /**
     * Can its route be changed? Not a Confidential type's: where one goes is
     * App\Support\Confidential's rule (the City Mayor or HRMO, by config), and
     * StoreDocumentRequest refuses any other route whatever a template says.
     */
    public function routeIsEditable(): bool
    {
        return $this->isEditable() && ! $this->is_confidential;
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * The type's suggested route, in visiting order.
     *
     * @return HasMany<DocumentTypeRouteStep, $this>
     */
    public function routeSteps(): HasMany
    {
        return $this->hasMany(DocumentTypeRouteStep::class)->orderBy('position');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('document_types.is_active', true);
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('document_types.sort_order')->orderBy('document_types.name');
    }
}
