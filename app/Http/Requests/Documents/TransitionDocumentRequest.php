<?php

namespace App\Http\Requests\Documents;

use App\Enums\MovementAction;
use App\Exceptions\StaleWorkflowStateException;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * §9 approve / reject / return / forward / complete.
 *
 * Authorization asks the policy about THIS action specifically -- the policy in
 * turn asks the workflow map whether the transition is even legal, so an
 * illegal action is refused before any lock is taken.
 */
class TransitionDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');
        $action = $this->enum('action', MovementAction::class);

        if (! $document instanceof Document || $action === null) {
            return false;
        }

        /*
         * Staleness is checked FIRST, and deliberately.
         *
         * When a second tab acts on a document the first tab has already moved,
         * the policy is what refuses -- the action is no longer legal from the
         * new state -- and the user gets a bare "This action is unauthorized."
         * That is both ugly and wrong: it is a conflict, not a permissions
         * problem, and the knowledge base promises a different sentence
         * entirely. TransitionDocument raises the same exception under its row
         * lock; this only moves the common case earlier so the message is right.
         */
        $expected = $this->integer('expected_movement_id') ?: null;

        if ($expected !== null && $document->openMovement?->id !== $expected) {
            throw new StaleWorkflowStateException;
        }

        return $this->user()?->can('act', [$document, $action]) ?? false;
    }

    /**
     * Fold the single-office alias into the list, so rules() and everything
     * downstream see one shape.
     *
     * Only when `to_office_ids` is absent: a client that sends both meant the
     * list, and letting the scalar win would silently drop the extra offices.
     */
    protected function prepareForValidation(): void
    {
        $ids = $this->input('to_office_ids');

        if ($ids === null) {
            $single = $this->input('to_office_id');

            if ($single !== null && $single !== '') {
                $this->usedScalarAlias = true;
                $ids = [$single];
            }
        }

        /*
         * Blanks are not destinations.
         *
         * The document page keeps `to_office_ids` in its form state for every
         * action, so approving, rejecting or completing posts the field with
         * one empty element -- `to_office_ids[]=`. Left alone that fails
         * `integer` and refuses the action, with the error on a field the user
         * cannot even see, because the office picker only renders for a
         * forward. Stripping empties here means one shape reaches rules() no
         * matter which button was pressed.
         */
        $ids = array_values(array_filter(
            (array) ($ids ?? []),
            static fn ($id): bool => $id !== null && $id !== '',
        ));

        $this->merge(['to_office_ids' => $ids === [] ? null : $ids]);
    }

    /**
     * Whether this submission arrived as the old single `to_office_id`. Errors
     * are mirrored back onto that key when it did, so a caller only ever sees
     * the field name it actually sent.
     */
    private bool $usedScalarAlias = false;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(MovementAction::class)],

            /*
             * §9 forwarding, to one office or to several in one submit.
             *
             * `to_office_ids` is the real field: an ORDERED list of
             * destinations, first one now and the rest queued. `to_office_id`
             * survives as a scalar alias because it is what every existing
             * test, and any tab left open across the deploy, still posts.
             * prepareForValidation() folds the alias into the array so there is
             * exactly one shape below this line.
             */
            'to_office_ids' => [
                'nullable',
                'array',
                'max:20',
                // `required` already refuses null and the empty array, so there
                // is no `min:1` here to fire on every non-forward action.
                Rule::requiredIf(fn () => $this->input('action') === MovementAction::Forwarded->value),
            ],
            'to_office_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('offices', 'id')->where('is_active', true),
            ],

            // The open leg the form was rendered from. TransitionDocument
            // compares it under the row lock, so a double-click or a tab left
            // open overnight gets a 409 instead of silently acting on a state
            // its author never saw.
            'expected_movement_id' => ['nullable', 'integer'],

            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Without this the array rule renders its own key: "The selected
     * to_office_ids.0 is invalid." show.tsx deliberately hunts for those
     * indexed keys so the message reaches the user, and the case it exists for
     * -- an office deactivated between opening the page and pressing Confirm --
     * is exactly what a re-seed onto the client's real office list causes. The
     * refusal is right; the sentence was unreadable.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'to_office_id' => 'destination office',
            'to_office_ids' => 'destination office',
            'to_office_ids.*' => 'destination office',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $action = $this->enum('action', MovementAction::class);

            // §9: "approve, reject, or return a document with remarks". Sending
            // something back without saying why is the single most common
            // complaint about systems like this.
            if ($action?->requiresRemarks() && blank($this->input('remarks'))) {
                $validator->errors()->add('remarks', 'Please say why you are '.$action->verb().' this document.');
            }

            if ($action === MovementAction::Forwarded) {
                $document = $this->route('document');
                $leg = $document instanceof Document ? $document->openMovement : null;
                $destinations = array_map('intval', (array) $this->input('to_office_ids', []));

                // Only the FIRST stop can be "already at that office" -- it is
                // the one the folder moves to now. A later stop naming the
                // current holder would be a legitimate round trip (out to
                // Budget, back here to sign), so it is not refused here; the
                // picker simply does not offer the current holder at all.
                if ($leg !== null && ($destinations[0] ?? null) === $leg->to_office_id) {
                    $this->addDestinationError($validator, 'This document is already at that office.');
                }

                // 'distinct' catches repeats, but its message names an index the
                // picker never shows. This is the sentence a person can act on.
                if (count($destinations) !== count(array_unique($destinations))) {
                    $this->addDestinationError($validator, 'Each office can only appear once in the route.');
                }
            }
        });
    }

    /**
     * Report a destination problem on the key the CALLER used.
     *
     * `to_office_ids` is the real field and the picker reads it. The scalar
     * `to_office_id` is mirrored only when the submission arrived that way, so
     * a single-office client -- an old tab, an existing test -- still gets its
     * error back under the name it posted.
     */
    private function addDestinationError(Validator $validator, string $message): void
    {
        $validator->errors()->add('to_office_ids', $message);

        if ($this->usedScalarAlias) {
            $validator->errors()->add('to_office_id', $message);
        }
    }
}
