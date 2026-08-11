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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(MovementAction::class)],

            // Required when forwarding; ignored otherwise.
            'to_office_id' => [
                'nullable',
                'integer',
                Rule::exists('offices', 'id')->where('is_active', true),
                Rule::requiredIf(fn () => $this->input('action') === MovementAction::Forwarded->value),
            ],

            // The open leg the form was rendered from. TransitionDocument
            // compares it under the row lock, so a double-click or a tab left
            // open overnight gets a 409 instead of silently acting on a state
            // its author never saw.
            'expected_movement_id' => ['nullable', 'integer'],

            'remarks' => ['nullable', 'string', 'max:2000'],
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

                if ($leg !== null && (int) $this->input('to_office_id') === $leg->to_office_id) {
                    $validator->errors()->add('to_office_id', 'This document is already at that office.');
                }
            }
        });
    }
}
