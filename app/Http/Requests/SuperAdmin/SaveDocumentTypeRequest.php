<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\RouteStepKind;
use App\Models\DocumentType;
use App\Models\DocumentTypeRouteStep;
use App\Support\Confidential;
use App\Support\RoutePlan;
use Closure;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Document Types page's add and edit form (client requests 2026-10-02
 * and, for the built-in types, 2026-10-04).
 *
 * One request for both: the only differences are that the code is chosen
 * once, on create, and that what a route may hold depends on the type --
 * see ManageDocumentType. A type the page cannot change at all (a retired
 * placeholder) is refused in authorize() -- before validation, so the answer
 * is a 403 whatever was posted -- and again in ManageDocumentType, which is
 * the check no new caller can skip.
 *
 * A ROUTE STEP is posted as one of:
 *  - {kind: office, office_id, optional, checked, purpose} -- an office;
 *    `kind` may be left out, which is how the page posted a custom type's
 *    route before the built-in types were editable;
 *  - {kind: choose|same|note, step_id, optional, purpose} -- a step of that
 *    kind the type already has, kept and possibly moved or reworded. Only a
 *    built-in type has any.
 */
class SaveDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->editing();

        return ($this->user()?->isSuperAdmin() ?? false)
            && ($type === null || $type->isEditable());
    }

    /** The type being edited; null when one is being added. */
    public function editing(): ?DocumentType
    {
        $type = $this->route('documentType');

        return $type instanceof DocumentType ? $type : null;
    }

    /** Is a route posted for this type taken, or refused outright? */
    public function routeIsEditable(): bool
    {
        return $this->editing()?->routeIsEditable() ?? true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editing = $this->editing();

        return [
            'name' => ['required', 'string', 'max:191', $this->uniqueName($editing)],

            /*
             * The code is a key, not a label. The seeder finds its rows by
             * code on every deploy, so a custom type may never take a built-in
             * code -- the next deploy would overwrite it -- nor one any row
             * has ever had. Rule::unique counts soft-deleted rows unless told
             * otherwise, which is what "has ever had" needs.
             *
             * It cannot change afterwards. Nothing is posted for it on an
             * edit; a code that is posted must be the one it already has.
             */
            'code' => $editing === null
                ? [
                    'required',
                    'string',
                    'max:32',
                    'regex:/^[A-Z0-9-]+$/',
                    Rule::notIn(DocumentTypeSeeder::builtInCodes()),
                    Rule::unique('document_types', 'code'),
                ]
                : ['sometimes', Rule::in([$editing->code])],

            'description' => ['nullable', 'string', 'max:500'],

            // §11's deadline monitoring. Blank: the installation default.
            'turnaround_days' => ['nullable', 'integer', 'between:1,365'],

            ...$this->routeRules($editing),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function routeRules(?DocumentType $editing): array
    {
        if (! $this->routeIsEditable()) {
            return ['steps' => ['prohibited']];
        }

        /*
         * The steps AFTER the office filing it. The filer is always stop 1
         * on the Submit form, so a template has one stop fewer than a
         * registered route may.
         */
        $rules = [
            'steps' => ['required', 'array', 'min:1', 'max:'.(RoutePlan::maxStops() - 1)],
            'steps.*' => ['array'],
        ];

        $steps = $this->input('steps');

        // Too many is the `max` rule's to say; there is no point writing a
        // rule per step for them.
        if (! is_array($steps) || count($steps) > RoutePlan::maxStops() - 1) {
            return $rules;
        }

        foreach (array_keys($steps) as $at) {
            $kind = $this->input("steps.{$at}.kind", RouteStepKind::Office->value);

            $rules["steps.{$at}.kind"] = $editing?->is_custom === false
                ? ['sometimes', Rule::enum(RouteStepKind::class)]
                // A custom type's route is offices only (the Basic tier,
                // 2026-10-03).
                : ['sometimes', Rule::in([RouteStepKind::Office->value])];
            $rules["steps.{$at}.optional"] = ['sometimes', 'boolean'];

            if ($kind === RouteStepKind::Office->value || ! is_string($kind)) {
                $rules["steps.{$at}.office_id"] = ['required', 'integer', Rule::exists('offices', 'id')->where('is_active', true)];
                $rules["steps.{$at}.checked"] = ['sometimes', 'boolean'];
                $rules["steps.{$at}.purpose"] = ['nullable', 'string', 'max:191'];

                continue;
            }

            $rules["steps.{$at}.step_id"] = [
                'required',
                'integer',
                Rule::exists('document_type_route_steps', 'id')
                    ->where('document_type_id', $editing->id ?? 0)
                    ->where('kind', $kind),
            ];

            // A choice and a note have no other label on Submit Document; a
            // "same office" step is named by the office it repeats.
            $rules["steps.{$at}.purpose"] = $kind === RouteStepKind::Same->value
                ? ['nullable', 'string', 'max:191']
                : ['required', 'string', 'max:191'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may only use capital letters, numbers and hyphens, like EVENT-PERMIT.',
            'code.not_in' => 'That code belongs to a built-in document type. Choose another.',
            'code.unique' => 'Another document type uses that code, or once did. Choose another.',
            'code.in' => 'The code cannot be changed after the type is created.',
            'steps.required' => 'Add at least one office to the route.',
            'steps.min' => 'Add at least one office to the route.',
            'steps.max' => 'A route can have at most :max offices after the one filing it.',
            'steps.prohibited' => 'A Confidential document always goes straight to '.Confidential::officeNames().', so its route cannot be changed here.',
            'steps.*.kind.in' => 'A type you add can only pass through offices.',
            'steps.*.kind.enum' => 'That is not a kind of step a route can have.',
            'steps.*.office_id.required' => 'Choose an office for every step of the route.',
            'steps.*.office_id.exists' => 'An office on the route is no longer active. Remove it or choose another.',
            'steps.*.step_id.required' => 'This step is not one the saved route has. Reload the page and try again.',
            'steps.*.step_id.exists' => 'This step is not one the saved route has. Reload the page and try again.',
            'steps.*.purpose.required' => 'Write what this step says: it is its only label on Submit Document.',
            'turnaround_days.between' => 'Turnaround must be between 1 and 365 days.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'turnaround_days' => 'turnaround days',
            'steps' => 'route',
            'steps.*.office_id' => 'office',
            'steps.*.purpose' => 'purpose',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->routeIsEditable()) {
                return;
            }

            // A step that failed its own rules already has a message.
            foreach (array_keys($validator->errors()->messages()) as $key) {
                if ($key === 'steps' || str_starts_with($key, 'steps.')) {
                    return;
                }
            }

            /** @var list<array<string, mixed>> $steps */
            $steps = array_values(array_filter((array) $this->input('steps', []), 'is_array'));

            $this->checkKeptSteps($validator, $steps);

            if ($validator->errors()->has('steps') || $validator->errors()->has('steps.*')) {
                return;
            }

            $kinds = array_map(
                static fn (array $step): string => is_string($step['kind'] ?? null) ? $step['kind'] : RouteStepKind::Office->value,
                $steps,
            );

            // A note is said, not visited.
            if (! in_array(true, array_map(static fn (string $kind): bool => RouteStepKind::from($kind)->isStop(), $kinds), true)) {
                $validator->errors()->add('steps', 'Add at least one office to the route.');

                return;
            }

            // RoutePlan's rule, the one the Submit form is held to. A note
            // between two visits does not separate them, and a choice
            // separates whatever it is next to: it is not known yet.
            $run = [];

            foreach ($steps as $at => $step) {
                $kind = RouteStepKind::from($kinds[$at]);

                if ($kind === RouteStepKind::Note) {
                    continue;
                }

                $run[] = $kind === RouteStepKind::Office ? (int) ($step['office_id'] ?? 0) : -1 - $at;
            }

            if (count(RoutePlan::collapse($run)) !== count($run)) {
                $validator->errors()->add('steps', 'The same office is listed twice in a row. An office can come round again, but not straight after itself.');
            }
        });
    }

    /**
     * Each kept step once, and a "same office" step after the choice it
     * repeats -- the Submit form follows the choice wherever it is, and has
     * nothing to follow once it is gone.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    private function checkKeptSteps(Validator $validator, array $steps): void
    {
        $editing = $this->editing();

        if ($editing === null) {
            return;
        }

        $saved = $editing->routeSteps()->get()->keyBy('position');

        // Saved step id => where it is posted.
        $at = [];

        foreach ($steps as $index => $step) {
            if (! isset($step['step_id']) || ($step['kind'] ?? RouteStepKind::Office->value) === RouteStepKind::Office->value) {
                continue;
            }

            $id = (int) $step['step_id'];

            if (isset($at[$id])) {
                $validator->errors()->add("steps.{$index}.step_id", 'This step is on the route twice. Keep it once.');

                continue;
            }

            $at[$id] = $index;
        }

        foreach ($steps as $index => $step) {
            if (($step['kind'] ?? null) !== RouteStepKind::Same->value) {
                continue;
            }

            $same = $saved->first(static fn (DocumentTypeRouteStep $row): bool => $row->id === (int) $step['step_id']);
            $choice = $same === null ? null : $saved->get($same->same_as_position);
            $choiceAt = $choice === null ? null : ($at[$choice->id] ?? null);
            $label = $choice->purpose ?? 'the office chosen earlier';

            if ($choiceAt === null) {
                $validator->errors()->add("steps.{$index}.step_id", "This step repeats the office chosen at \"{$label}\". Keep that step, or remove this one too.");
            } elseif ($choiceAt > $index) {
                $validator->errors()->add("steps.{$index}.step_id", "This step repeats the office chosen at \"{$label}\", so it has to come after it.");
            }
        }
    }

    /**
     * No two types with one name, whatever the capitals -- the Submit form's
     * dropdown would show two entries with one label. Inactive types count:
     * reactivating one would bring the clash back.
     */
    private function uniqueName(?DocumentType $editing): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($editing): void {
            if (! is_string($value)) {
                return;
            }

            $taken = DocumentType::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))])
                ->when($editing !== null, fn ($query) => $query->whereKeyNot($editing?->id))
                ->exists();

            if ($taken) {
                $fail('Another document type already has that name.');
            }
        };
    }
}
