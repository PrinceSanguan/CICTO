<?php

namespace App\Actions\DocumentTypes;

use App\Enums\RouteStepKind;
use App\Enums\SecurityEventType;
use App\Models\DocumentType;
use App\Models\DocumentTypeRouteStep;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\RouteTemplates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every write the Document Types page makes.
 *
 * Client requests: 2026-10-02, "mag dagdag ng documents type tapos i input na
 * rin po don yung offices na dadaanan nya, para sa automation"; 2026-10-04,
 * the 43 built-in types editable the same way.
 *
 * WHAT A SUPER ADMIN CAN CHANGE. Name, description, turnaround, whether it is
 * offered, and the route: offices added, removed, reordered, made optional,
 * with what happens at each. On a built-in type they can also keep, move or
 * remove the steps that are not fixed offices -- "chosen when filing", "the
 * same office as step N", a note -- and reword them, but not make new ones.
 * A custom type's route is offices only, as it always was.
 *
 * WHAT NOBODY CAN CHANGE HERE. The code, Confidential and Broadcast; a
 * Confidential type's route (App\Support\Confidential decides it); and the
 * placeholder types the seeder retired. SaveDocumentTypeRequest refuses those
 * with a message; guard() refuses them again, for any caller that skips it.
 *
 * WHAT THE SEEDER DOES AFTERWARDS. A change to a built-in type stamps
 * `customized_at` (name, description, turnaround, active) or
 * `route_customized_at` (the route), and DocumentTypeSeeder -- which every
 * deploy re-runs -- leaves that part alone from then on.
 * restoreOriginalRoute() hands the route back to it.
 *
 * Nothing here touches a registered document. A document carries its own
 * route in document_route_stops from the moment it is filed, so a changed or
 * deactivated type only changes what the Submit form offers next.
 *
 * Each change is written to §21's security log as a setting change, and only
 * when something actually changed -- the same rule SystemController follows.
 */
final class ManageDocumentType
{
    /**
     * Add a type, or save one. The route is as the page posts it, or null to
     * leave it as it is.
     *
     * @param  array{name: string, code?: string, description?: string|null, turnaround_days?: int|null}  $fields
     * @param  list<array{kind?: string, office_id?: int|null, step_id?: int|null, optional?: bool, checked?: bool, purpose?: string|null}>|null  $steps
     */
    public function save(User $actor, ?DocumentType $type, array $fields, ?array $steps): DocumentType
    {
        $this->guard($actor, $type);

        if ($steps === null && $type === null) {
            throw new InvalidArgumentException('A new document type needs a route.');
        }

        if ($steps !== null && $type !== null && ! $type->routeIsEditable()) {
            throw new AuthorizationException('This document type\'s route cannot be changed.');
        }

        return DB::transaction(function () use ($actor, $type, $fields, $steps): DocumentType {
            $creating = $type === null;
            $type ??= new DocumentType;

            if ($creating) {
                // Fixed for every custom type: they are plain routes, never
                // Confidential and never broadcast.
                $type->forceFill([
                    'code' => $fields['code'] ?? '',
                    'is_custom' => true,
                    'is_confidential' => false,
                    'allows_broadcast' => false,
                    'requires_approval' => true,
                    'is_active' => true,
                    'sort_order' => DocumentType::CUSTOM_SORT_ORDER,
                ]);
            }

            $type->fill([
                'name' => trim($fields['name']),
                'description' => self::blankToNull($fields['description'] ?? null),
                'turnaround_days' => $fields['turnaround_days'] ?? null,
            ]);

            $changed = $creating ? [] : array_keys($type->getDirty());

            if ($changed !== [] && $type->isBuiltIn()) {
                $type->forceFill(['customized_at' => now()]);
            }

            $type->save();

            $count = 0;

            if ($steps !== null) {
                $saved = $creating ? new Collection : $type->routeSteps()->get();
                $route = self::rows($type, $saved, $steps);
                $count = count(array_filter($route, static fn (array $row): bool => $row['kind'] !== RouteStepKind::Note->value));

                $before = $saved
                    ->map(static fn (DocumentTypeRouteStep $step): array => $step->definition())
                    ->all();

                if ($before !== $route) {
                    // Replaced whole: positions are the order, and a route
                    // edited in the middle has no row that kept its place.
                    $type->routeSteps()->delete();
                    $type->routeSteps()->createMany(RouteTemplates::positioned($route));

                    if ($type->isBuiltIn()) {
                        $type->forceFill(['route_customized_at' => now()])->save();
                    }

                    $changed[] = 'route';
                }
            }

            if ($creating) {
                $this->log($actor, $type, sprintf(
                    'Document type "%s" (%s) added, with a route of %d %s.',
                    $type->name,
                    $type->code,
                    $count,
                    $count === 1 ? 'office' : 'offices',
                ));
            } elseif ($changed !== []) {
                $this->log($actor, $type, sprintf(
                    'Document type "%s" (%s) changed: %s.',
                    $type->name,
                    $type->code,
                    implode(', ', array_map(self::label(...), $changed)),
                ));
            }

            return $type;
        });
    }

    /**
     * Take a type off the Submit form, or put it back.
     *
     * Deactivated, never deleted: documents.document_type_id points at it, and
     * every document, filter and report keeps showing its name.
     */
    public function setActive(User $actor, DocumentType $type, bool $active): void
    {
        $this->guard($actor, $type);

        if ($type->is_active === $active) {
            return;
        }

        $type->forceFill(['is_active' => $active]);

        // Or the next deploy's seeder would switch a built-in one back on.
        if ($type->isBuiltIn()) {
            $type->forceFill(['customized_at' => now()]);
        }

        $type->save();

        $this->log($actor, $type, sprintf(
            'Document type "%s" (%s) %s.',
            $type->name,
            $type->code,
            $active ? 'activated: it is offered on Submit Document again' : 'deactivated: it is no longer offered on Submit Document',
        ));
    }

    /**
     * Put a built-in type's route back to the one it came with, and let the
     * seeder keep it current again.
     *
     * @return bool whether anything changed
     */
    public function restoreOriginalRoute(User $actor, DocumentType $type): bool
    {
        $this->guard($actor, $type);

        if (! $type->isBuiltIn() || ! $type->routeIsEditable()) {
            throw new AuthorizationException('Only a built-in document type has an original route to go back to.');
        }

        $changed = RouteTemplates::installOriginal($type);

        if ($changed) {
            $this->log($actor, $type, sprintf(
                'Document type "%s" (%s) route restored to the original.',
                $type->name,
                $type->code,
            ));
        }

        return $changed;
    }

    /**
     * The posted route as rows, in DocumentTypeRouteStep::definition()'s
     * shape.
     *
     * An `office` step is taken as posted. Any other kind names a step the
     * type already has, by id, and keeps everything about it but its wording
     * and -- for a choice -- whether it is optional. A `same` step follows
     * the choice it repeats to wherever that choice now is.
     *
     * SaveDocumentTypeRequest has refused anything that does not fit, with a
     * message; these exceptions are for a caller that skipped it.
     *
     * @param  Collection<int, DocumentTypeRouteStep>  $saved
     * @param  list<array{kind?: string, office_id?: int|null, step_id?: int|null, optional?: bool, checked?: bool, purpose?: string|null}>  $steps
     * @return list<array{kind: string, office_id: int|null, is_optional: bool, is_checked: bool, suggested_office_id: int|null, suggests_origin: bool, only_office_ids: list<int>|null, same_as_position: int|null, purpose: string|null}>
     */
    private static function rows(DocumentType $type, Collection $saved, array $steps): array
    {
        $byId = $saved->keyBy('id');
        $byPosition = $saved->keyBy('position');

        // Saved step id => its index in the posted route.
        $index = [];

        foreach ($steps as $at => $step) {
            if (isset($step['step_id']) && ($step['kind'] ?? RouteStepKind::Office->value) !== RouteStepKind::Office->value) {
                $index[(int) $step['step_id']] ??= $at;
            }
        }

        $rows = [];

        foreach ($steps as $at => $step) {
            $kind = RouteStepKind::from($step['kind'] ?? RouteStepKind::Office->value);
            $optional = (bool) ($step['optional'] ?? false);
            $purpose = self::blankToNull($step['purpose'] ?? null);

            if ($kind === RouteStepKind::Office) {
                $rows[] = [
                    'kind' => $kind->value,
                    'office_id' => (int) ($step['office_id'] ?? 0),
                    'is_optional' => $optional,
                    // "Starts ticked" is a built-in type's (the BAC's
                    // members); a custom type's optional step starts unticked.
                    'is_checked' => $optional && ! $type->is_custom && (bool) ($step['checked'] ?? false),
                    'suggested_office_id' => null,
                    'suggests_origin' => false,
                    'only_office_ids' => null,
                    'same_as_position' => null,
                    'purpose' => $purpose,
                ];

                continue;
            }

            $original = $byId->get((int) ($step['step_id'] ?? 0));

            if ($type->is_custom || $original === null || $original->kind !== $kind) {
                throw new InvalidArgumentException('A route step that is not an office must be one this type already has.');
            }

            $row = $original->definition();
            $row['purpose'] = $purpose;
            $row['is_optional'] = $kind === RouteStepKind::Choose && $optional;

            if ($kind === RouteStepKind::Same) {
                $target = $byPosition->get($original->same_as_position);
                $targetAt = $target === null ? null : ($index[$target->id] ?? null);

                if ($targetAt === null || $targetAt >= $at) {
                    throw new InvalidArgumentException('A "same office" step must come after the choice it repeats.');
                }

                $row['same_as_position'] = $targetAt + 1;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function guard(User $actor, ?DocumentType $type): void
    {
        if (! $actor->is_active || ! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Only a Super Admin can manage document types.');
        }

        if ($type !== null && ! $type->isEditable()) {
            throw new AuthorizationException('This document type was retired and cannot be changed.');
        }
    }

    private function log(User $actor, DocumentType $type, string $summary): void
    {
        SecurityEvent::log(
            SecurityEventType::SettingChanged,
            $summary,
            $actor,
            "Document type {$type->code}",
        );
    }

    private static function label(string $field): string
    {
        return match ($field) {
            'turnaround_days' => 'turnaround days',
            default => $field,
        };
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
