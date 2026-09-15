<?php

namespace App\Http\Requests\Documents;

use App\Enums\MovementAction;
use App\Enums\SignatureMethod;
use App\Exceptions\StaleWorkflowStateException;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
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

        if (! ($this->user()?->can('act', [$document, $action]) ?? false)) {
            return false;
        }

        /*
         * §15 handoff signature, when one rides along with the forward.
         *
         * Asked separately from `act`, because holding the folder is not the
         * same permission as signing for it -- DocumentPolicy::signRelease also
         * wants a file to bind to and refuses a version this person already
         * released. The document page hides the pad when it would fail, so a
         * request reaching here with a signature it may not make is a crafted
         * one, and a bare refusal is the right answer.
         */
        if ($this->carriesSignature() && ! $this->user()->can('signRelease', $document)) {
            return false;
        }

        // A corrected file riding along with a return or a resubmit is an
        // upload, so it asks the upload question too. The office holding the
        // document always passes it; this only refuses a crafted request.
        if ($this->hasFile('file') && ! $this->user()->can('uploadVersion', $document)) {
            return false;
        }

        return true;
    }

    /** Did this submit bring a signature along with it? */
    public function carriesSignature(): bool
    {
        return filled($this->input('signature_method'));
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

            /*
             * The corrected document, attached to a resubmit -- or, since
             * 2026-09-16, to the return itself, by the office sending it back --
             * so the fix and the send are one click. Optional -- a correction
             * can be a signature
             * on the paper folder -- and the same rules as every other upload,
             * because it lands in the same version history through the same
             * StoreDocumentFile.
             */
            'file' => [
                'nullable',
                File::types(config('cicto.uploads.mimes'))
                    ->extensions(config('cicto.uploads.extensions'))
                    ->max((int) config('cicto.uploads.max_size_kb')),
            ],
            'replace_reason' => ['nullable', 'string', 'max:500'],

            /*
             * §15 "Sign & send". Optional, and absent from every submit that
             * does not use it -- signing before a handoff is offered, never
             * required, so these rules must stay silent when the block is not
             * there.
             *
             * Prefixed rather than reusing `method`/`image` because this form
             * already carries an `action`, and two fields a keystroke apart
             * meaning different things is how the wrong one ends up read.
             */
            'signature_method' => ['nullable', Rule::enum(SignatureMethod::class)],

            // Cap mirrors StoreSignatureRequest: base64 of SignDocument's
            // 512 KB byte limit, so an oversized mark is a sentence rather than
            // a RuntimeException surfacing as a 500.
            'signature_image' => ['nullable', 'string', 'max:683008'],
            'signature_typed_name' => ['nullable', 'string', 'max:191'],
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

            $this->validateSignature($validator, $action);

            // The corrected file belongs to a return or a resubmit and nothing
            // else: on any other action it would be silently dropped, which is
            // worse than saying so.
            if ($this->hasFile('file')
                && ! in_array($action, [MovementAction::Returned, MovementAction::Resubmitted], true)) {
                $validator->errors()->add('file', 'A corrected file can only be attached when returning or resubmitting a document.');
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
     * The §15 block, when one was sent.
     *
     * Mirrors StoreSignatureRequest deliberately: the same two ways of
     * capturing a mark, refused for the same two reasons. Both paths end in the
     * same SignDocument call, so a rule enforced on one form and not the other
     * is a hole rather than a shortcut.
     */
    private function validateSignature(Validator $validator, ?MovementAction $action): void
    {
        if (! $this->carriesSignature()) {
            return;
        }

        /*
         * A release signature is a statement about a handoff, so there has to
         * BE a handoff. Approving or completing with a signature attached would
         * write a row claiming the folder was released to an office it never
         * went to.
         */
        if ($action !== MovementAction::Forwarded) {
            $validator->errors()->add(
                'signature_method',
                'A signature can only be added when sending the document to another office.',
            );

            return;
        }

        $method = $this->enum('signature_method', SignatureMethod::class);

        if ($method === SignatureMethod::Drawn && blank($this->input('signature_image'))) {
            $validator->errors()->add('signature_image', 'Please draw your signature before signing.');
        }

        if ($method === SignatureMethod::Typed) {
            $typed = trim((string) $this->input('signature_typed_name'));

            if ($typed === '') {
                $validator->errors()->add('signature_typed_name', 'Please type your full name.');
            } elseif (mb_strtolower($typed) !== mb_strtolower((string) $this->user()?->name)) {
                // You sign as yourself. Typing somebody else's name is not a
                // signature, it is impersonation with extra steps.
                $validator->errors()->add(
                    'signature_typed_name',
                    'The typed name must match the name on your account.',
                );
            }
        }
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
