<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentPriority;
use App\Models\Document;
use App\Support\DocumentUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Spec §5's Submit Document form: title, department, document type, priority,
 * description, remarks, file upload.
 */
class StoreDocumentRequest extends FormRequest
{
    /**
     * How the departments are served. One after another, and nothing else:
     * the client removed "all at the same time" (`all_at_once`) on 2026-09-19.
     * Kept as a field so a tab opened before that still posts successfully.
     *
     * @var list<string>
     */
    public const DISTRIBUTIONS = ['in_order'];

    /**
     * Whether this submission arrived as the old single `originating_office_id`.
     * Errors are mirrored back onto that key when it did, so a caller only ever
     * sees the field name it actually sent.
     */
    private bool $usedScalarAlias = false;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Document::class) ?? false;
    }

    /**
     * Fold the single-department alias into the list, so rules() and everything
     * downstream see one shape.
     *
     * Only when `office_ids` is absent: a client that sends both meant the
     * list, and letting the scalar win would silently drop the extra
     * departments.
     */
    protected function prepareForValidation(): void
    {
        $ids = $this->input('office_ids');

        if ($ids === null) {
            $single = $this->input('originating_office_id');

            if ($single !== null && $single !== '') {
                $this->usedScalarAlias = true;
                $ids = [$single];
            }
        }

        /*
         * Blanks are not departments. An empty element can only mean "nothing
         * picked", and `required` is the rule that says so in a sentence a
         * person can act on -- `integer` complaining about an id the user never
         * chose is not.
         */
        $ids = array_values(array_filter(
            (array) ($ids ?? []),
            static fn ($id): bool => $id !== null && $id !== '',
        ));

        $this->merge(['office_ids' => $ids === [] ? null : $ids]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('is_active', true)],

            /*
             * §5's Department field, for one department or several.
             *
             * ORDERED, and the order is load-bearing. The first entry is the
             * ORIGINATING office: it stamps the control number prefix and is
             * where the folder physically starts -- so it must be an office the
             * submitter works for, which withValidator() checks. Everything
             * after it is the §9 routing plan, queued at registration instead
             * of forwarded by hand at each hop -- the folder still visits one
             * department at a time, because one printed QR label cannot be on
             * three desks at once (decision D13).
             *
             * `originating_office_id` survives as a scalar alias because it is
             * what every existing test, and any tab left open across the
             * deploy, still posts. prepareForValidation() folds it in, so there
             * is exactly one shape below this line.
             */
            'office_ids' => ['required', 'array', 'max:20'],
            'office_ids.*' => [
                'integer',
                // A department twice in one route is a typo, not a round trip:
                // the picker never offers one it has already added.
                'distinct',
                Rule::exists('offices', 'id')->where('is_active', true),
            ],

            /*
             * How the departments above are served: `in_order`, the routing
             * list -- one document, visiting each department in turn as the one
             * before it receives it. Nullable, and absence means the same.
             *
             * `all_at_once` is REFUSED rather than quietly routed. It asked for
             * one copy per department; filing a single routed document instead
             * would hand the submitter something other than what they chose.
             */
            'distribution' => ['nullable', Rule::in(self::DISTRIBUTIONS)],

            // The client's three levels only. Legacy `urgent` still reads
            // correctly on old documents, but nothing new is filed as it.
            'priority' => [
                'required',
                Rule::in(array_map(
                    static fn (DocumentPriority $priority) => $priority->value,
                    DocumentPriority::selectable(),
                )),
            ],

            // Extension and content both checked -- DocumentUpload says why.
            'file' => [
                // Required, per the client's design. Kept in step with the
                // asterisk on the Upload File label -- the two must change
                // together or the form goes back to promising a check that
                // does not happen.
                'required',
                ...DocumentUpload::rules(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...DocumentUpload::messages(),
            'distribution.in' => 'Sending to every department at the same time is no longer available. Departments now receive the document one after another, in the order listed.',
            'priority.required' => 'Please choose a priority.',
            'priority.in' => 'Please choose High, Medium or Low.',
        ];
    }

    /**
     * Without the indexed key, an array rule renders as "The selected
     * office_ids.0 is invalid." create.tsx hunts for those indexed keys so the
     * message reaches the user at all, and the case they exist for -- a
     * department deactivated between opening the form and pressing Submit -- is
     * exactly what a re-seed onto the client's real office list causes.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'document_type_id' => 'document type',
            'originating_office_id' => 'department',
            'office_ids' => 'department',
            'office_ids.*' => 'department',
            'distribution' => 'delivery',
        ];
    }

    /**
     * Why the first department was refused, in a sentence the person can act on.
     *
     * A user whose own office has been deactivated -- which is what re-seeding
     * onto the client's real office list does -- is not offered that office at
     * all, so "must be your own office" would ask for something they cannot
     * pick. What they need to hear is that their account needs moving.
     */
    private function originMessage(): string
    {
        $office = $this->user()?->office;

        if ($office !== null && ! $office->is_active) {
            return "Your office, {$office->name}, is no longer active, so documents cannot be registered under it. Ask an administrator to move your account to your current office.";
        }

        return 'The first department must be your own office, because the document is registered under it.';
    }

    public function withValidator(Validator $validator): void
    {
        /*
         * THE ORIGINATING OFFICE IS THE SUBMITTER'S OWN.
         *
         * The first department registers the document: its prefix goes on the
         * control number and its desk holds the genesis leg. Nothing used to
         * check whose office that was, so reordering the Department list put
         * somebody else's office first and filed the document under it -- the
         * uploader then appeared as a user of an office they do not belong to.
         * The client reported exactly that on 2026-09-19.
         *
         * actsForOffice() is the rule everywhere else in the system: a user's
         * own office, or any office for a Super Admin, who belongs to none.
         * The form locks row 1 to the user's office, so this only fires on a
         * tab from before the lock or a hand-built request.
         *
         * Registered BEFORE the alias mirror below, so an old single-department
         * client gets this message back under the key it posted, too.
         */
        $validator->after(function (Validator $validator): void {
            // An id that failed its own rules already has a message; a second
            // one about the same pick would only bury it.
            if ($validator->errors()->has('office_ids') || $validator->errors()->has('office_ids.*')) {
                return;
            }

            $first = ((array) $this->input('office_ids', []))[0] ?? null;

            if ($first !== null && ! ($this->user()?->actsForOffice((int) $first) ?? false)) {
                $validator->errors()->add('office_ids', $this->originMessage());
            }
        });

        if (! $this->usedScalarAlias) {
            return;
        }

        /*
         * Report a department problem on the key the CALLER used.
         *
         * `office_ids` is the real field and the picker reads it. The scalar
         * alias is mirrored only when the submission arrived that way, so a
         * single-department client -- an old tab, an existing test -- still
         * gets its error back under the name it posted, instead of one it
         * cannot display.
         */
        $validator->after(function (Validator $validator): void {
            // messages() rather than get(), which types a wildcard key's value
            // as a nested array: this bag is flat, and reading it flat is what
            // keeps the mirrored message a string.
            foreach ($validator->errors()->messages() as $key => $messages) {
                if ($key !== 'office_ids' && ! str_starts_with($key, 'office_ids.')) {
                    continue;
                }

                foreach ($messages as $message) {
                    $validator->errors()->add('originating_office_id', $message);
                }
            }
        });
    }
}
