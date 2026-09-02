<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentPriority;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

/**
 * Spec §5's Submit Document form: title, department, document type, priority,
 * description, remarks, file upload.
 */
class StoreDocumentRequest extends FormRequest
{
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
             * where the folder physically starts. Everything after it is the
             * §9 routing plan, queued at registration instead of forwarded by
             * hand at each hop -- the folder still visits one department at a
             * time, because one printed QR label cannot be on three desks at
             * once (decision D13).
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

            'priority' => ['required', Rule::enum(DocumentPriority::class)],

            // Both types() and extensions(). mimes:/types() validates only the
            // GUESSED MIME, so a .php file carrying a PDF magic header passes it;
            // extensions() checks the actual filename extension. SVG is
            // permanently excluded from the allow-list -- stored XSS.
            'file' => [
                // Required, per the client's design. Kept in step with the
                // asterisk on the Upload File label -- the two must change
                // together or the form goes back to promising a check that
                // does not happen.
                'required',
                File::types(config('cicto.uploads.mimes'))
                    ->extensions(config('cicto.uploads.extensions'))
                    ->max((int) config('cicto.uploads.max_size_kb')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'The file may not be larger than :max KB. Note that a very large upload can also be cut off by the server before it reaches this check.',
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
        ];
    }

    /**
     * Report a department problem on the key the CALLER used.
     *
     * `office_ids` is the real field and the picker reads it. The scalar alias
     * is mirrored only when the submission arrived that way, so a single-
     * department client -- an old tab, an existing test -- still gets its error
     * back under the name it posted, instead of one it cannot display.
     */
    public function withValidator(Validator $validator): void
    {
        if (! $this->usedScalarAlias) {
            return;
        }

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
