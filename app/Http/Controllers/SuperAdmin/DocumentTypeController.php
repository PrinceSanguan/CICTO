<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\DocumentTypes\ManageDocumentType;
use App\Enums\RouteStepKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SaveDocumentTypeRequest;
use App\Models\DocumentType;
use App\Models\DocumentTypeRouteStep;
use App\Models\Office;
use App\Support\Deadlines;
use App\Support\RoutePlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Document Types page (client requests 2026-10-02, approved 2026-10-03;
 * and 2026-10-04, the built-in types editable too).
 *
 * Lists every type. A Super Admin adds types of their own, and edits any of
 * them -- the 43 built-in ones included: name, description, turnaround, the
 * offices it passes through, and whether it is offered at all. A built-in
 * type's route can also be put back to the one it came with. There is no
 * delete: documents point at their type. What may not change, and why, is
 * ManageDocumentType's docblock.
 */
class DocumentTypeController extends Controller
{
    public function index(): Response
    {
        $types = DocumentType::query()
            ->ordered()
            ->with('routeSteps.office:id,code,name,is_active')
            ->withCount('documents')
            ->get();

        // The same list, and the same "no account yet" flag, as the Submit
        // form's office picker -- see DocumentController::create.
        $offices = Office::query()->active()->ordered()
            ->select(['id', 'code', 'name'])
            ->withReceiver()
            ->get();

        // Every office, for naming a choice's list and suggestion.
        $names = Office::query()->pluck('name', 'id');

        return Inertia::render('super-admin/document-types/index', [
            'types' => $types->map(fn (DocumentType $type): array => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'description' => $type->description,
                'turnaround_days' => $type->turnaround_days,
                'is_custom' => $type->is_custom,
                'is_active' => $type->is_active,
                'editable' => $type->isEditable(),
                'route_editable' => $type->routeIsEditable(),
                // Changed from what it came with, so the seeder leaves it be.
                'customized' => $type->isBuiltIn() && ($type->customized_at !== null || $type->route_customized_at !== null),
                'route_customized' => $type->isBuiltIn() && $type->route_customized_at !== null,
                'is_confidential' => $type->is_confidential,
                'allows_broadcast' => $type->allows_broadcast,
                'route_note' => $type->route_note,
                'documents_count' => (int) $type->getAttribute('documents_count'),
                'steps' => $this->steps($type, $names),
            ])->all(),
            'offices' => $offices,
            'maxSteps' => RoutePlan::maxStops() - 1,
            'defaultTurnaroundDays' => Deadlines::defaultTurnaroundDays(),
        ]);
    }

    public function store(SaveDocumentTypeRequest $request, ManageDocumentType $manage): RedirectResponse
    {
        $type = $manage->save($request->user(), null, $this->fields($request), $this->route($request));

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$type->name} added. It is on the Submit Document form now, with its route.",
        ]);
    }

    public function update(SaveDocumentTypeRequest $request, DocumentType $documentType, ManageDocumentType $manage): RedirectResponse
    {
        $manage->save(
            $request->user(),
            $documentType,
            $this->fields($request),
            $request->routeIsEditable() ? $this->route($request) : null,
        );

        return back()->with('toast', [
            'type' => 'success',
            // Said, because it is the first thing anybody editing a route
            // will wonder about.
            'message' => "{$documentType->name} saved. Documents already filed keep the route they were filed with.",
        ]);
    }

    public function updateStatus(Request $request, DocumentType $documentType, ManageDocumentType $manage): RedirectResponse
    {
        // Before validation, so a retired placeholder is a 403 whatever is
        // posted.
        abort_unless($documentType->isEditable(), 403, 'This document type was retired and cannot be changed.');

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['is_active'];

        $manage->setActive($request->user(), $documentType, $active);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $active
                ? "{$documentType->name} is on the Submit Document form again."
                : "{$documentType->name} is no longer offered on Submit Document. Documents already filed under it are unchanged.",
        ]);
    }

    public function restoreRoute(Request $request, DocumentType $documentType, ManageDocumentType $manage): RedirectResponse
    {
        abort_unless($documentType->isBuiltIn() && $documentType->routeIsEditable(), 403, 'Only a built-in document type has an original route to go back to.');

        $changed = $manage->restoreOriginalRoute($request->user(), $documentType);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $changed
                ? "{$documentType->name} has its original route again. Documents already filed keep the route they were filed with."
                : "{$documentType->name} already has its original route.",
        ]);
    }

    /**
     * A type's route as the page shows and edits it.
     *
     * @param  Collection<int, string>  $names  office id => name
     * @return list<array<string, mixed>>
     */
    private function steps(DocumentType $type, Collection $names): array
    {
        $byPosition = $type->routeSteps->keyBy('position');

        return array_values($type->routeSteps
            ->map(fn (DocumentTypeRouteStep $step): array => [
                'id' => $step->id,
                'kind' => $step->kind->value,
                'office_id' => $step->office_id,
                'office_name' => $step->office?->name,
                'office_active' => $step->office === null || $step->office->is_active,
                'optional' => $step->is_optional,
                'checked' => $step->is_checked,
                'purpose' => $step->purpose,
                // What a step that is not a fixed office does, in words.
                'detail' => $step->kind === RouteStepKind::Choose ? $this->choice($step, $names) : null,
                // A "same office" step's choice, by id: positions move.
                'same_as' => $step->kind === RouteStepKind::Same ? $byPosition->get($step->same_as_position)?->id : null,
            ])
            ->all());
    }

    /**
     * "One of: Office of the City Mayor, Human Resource Management Office ·
     * starts as Office of the City Mayor".
     *
     * @param  Collection<int, string>  $names
     */
    private function choice(DocumentTypeRouteStep $step, Collection $names): string
    {
        $parts = [$step->only_office_ids === null
            ? 'Any office'
            : 'One of: '.collect($step->only_office_ids)->map(fn ($id) => $names->get((int) $id))->filter()->implode(', ')];

        if ($step->suggests_origin) {
            $parts[] = 'starts as the office filing it';
        } elseif ($step->suggested_office_id !== null && $names->has($step->suggested_office_id)) {
            $parts[] = 'starts as '.$names->get($step->suggested_office_id);
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{name: string, code?: string, description: string|null, turnaround_days: int|null}
     */
    private function fields(SaveDocumentTypeRequest $request): array
    {
        $fields = [
            'name' => $request->string('name')->value(),
            'description' => $request->filled('description') ? $request->string('description')->value() : null,
            'turnaround_days' => $request->filled('turnaround_days') ? $request->integer('turnaround_days') : null,
        ];

        // Only on create: SaveDocumentTypeRequest refuses a different code
        // on an edit, and the action only reads it for a new type.
        if ($request->editing() === null) {
            $fields['code'] = $request->string('code')->value();
        }

        return $fields;
    }

    /**
     * @return list<array{kind: string, office_id: int|null, step_id: int|null, optional: bool, checked: bool, purpose: string|null}>
     */
    private function route(SaveDocumentTypeRequest $request): array
    {
        /** @var list<array<string, mixed>> $steps */
        $steps = array_values((array) $request->validated('steps'));

        return array_map(static function (array $step): array {
            $kind = is_string($step['kind'] ?? null) ? $step['kind'] : RouteStepKind::Office->value;
            $office = $kind === RouteStepKind::Office->value;

            return [
                'kind' => $kind,
                'office_id' => $office ? (int) ($step['office_id'] ?? 0) : null,
                'step_id' => $office ? null : (int) ($step['step_id'] ?? 0),
                'optional' => filter_var($step['optional'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'checked' => filter_var($step['checked'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'purpose' => isset($step['purpose']) && is_string($step['purpose']) ? $step['purpose'] : null,
            ];
        }, $steps);
    }
}
