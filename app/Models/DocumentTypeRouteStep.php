<?php

namespace App\Models;

use App\Enums\RouteStepKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a document type's suggested route.
 *
 * A TEMPLATE, not a plan: nothing here moves a document. The Submit form
 * reads these through RouteTemplates::forClient(), and a registered document
 * carries its own copy in document_route_stops.
 *
 * Which columns mean anything depends on `kind` -- see RouteStepKind and the
 * migration that made the table.
 *
 * @property int $id
 * @property int $document_type_id
 * @property int $position
 * @property RouteStepKind $kind
 * @property int|null $office_id
 * @property bool $is_optional
 * @property bool $is_checked
 * @property int|null $suggested_office_id
 * @property bool $suggests_origin
 * @property list<int>|null $only_office_ids
 * @property int|null $same_as_position
 * @property string|null $purpose
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentTypeRouteStep extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Integers on every driver, so "did the route change?" in
            // ManageDocumentType compares like with like.
            'document_type_id' => 'integer',
            'office_id' => 'integer',
            'position' => 'integer',
            'kind' => RouteStepKind::class,
            'is_optional' => 'boolean',
            'is_checked' => 'boolean',
            'suggested_office_id' => 'integer',
            'suggests_origin' => 'boolean',
            'only_office_ids' => 'array',
            'same_as_position' => 'integer',
        ];
    }

    /** @return BelongsTo<DocumentType, $this> */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * The step as ManageDocumentType and RouteTemplates::installOriginal()
     * compare and write it: every column but the keys and timestamps.
     *
     * @return array{kind: string, office_id: int|null, is_optional: bool, is_checked: bool, suggested_office_id: int|null, suggests_origin: bool, only_office_ids: list<int>|null, same_as_position: int|null, purpose: string|null}
     */
    public function definition(): array
    {
        return [
            'kind' => $this->kind->value,
            'office_id' => $this->office_id,
            'is_optional' => $this->is_optional,
            'is_checked' => $this->is_checked,
            'suggested_office_id' => $this->suggested_office_id,
            'suggests_origin' => $this->suggests_origin,
            'only_office_ids' => $this->only_office_ids === null
                ? null
                : array_map('intval', $this->only_office_ids),
            'same_as_position' => $this->same_as_position,
            'purpose' => $this->purpose,
        ];
    }
}
