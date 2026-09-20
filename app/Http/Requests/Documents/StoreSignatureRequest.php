<?php

namespace App\Http\Requests\Documents;

use App\Enums\SignatureMethod;
use App\Models\Document;
use App\Models\DocumentSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            && ($this->user()?->can($this->ability(), $document) ?? false);
    }

    /**
     * Approving and releasing are different acts with different rules -- see
     * DocumentPolicy::sign vs ::signRelease -- so the purpose being signed
     * under decides which one is asked. An unrecognised purpose falls through
     * to the STRICTER ability rather than the looser one, and is refused by
     * rules() a moment later regardless.
     */
    private function ability(): string
    {
        return $this->input('purpose') === DocumentSignature::PURPOSE_RELEASE
            ? 'signRelease'
            : 'sign';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(SignatureMethod::class)],

            // Absent means approval, which is what every caller predating the
            // handoff signature posts.
            'purpose' => ['nullable', Rule::in(DocumentSignature::purposes())],

            // A base64 PNG data URL from the canvas -- drawn on it, or an
            // uploaded image redrawn onto it. The bytes are validated
            // by magic number in SignDocument, because a declared MIME is not
            // evidence of anything.
            //
            // The cap is base64 of the action's 512 KB byte limit (4/3, plus
            // the data-URL prefix). It used to be 700000, which let a payload
            // through validation only for the action to reject it as a
            // RuntimeException -- a 500 where the user should have seen "that
            // signature is too large".
            'image' => ['nullable', 'string', 'max:683008'],

            // Typed signatures confirm the name they are signing under, so the
            // act is deliberate rather than a stray click.
            'typed_name' => ['nullable', 'string', 'max:191'],

            /*
             * THE STAMPED VERSION, composed by the signer's browser.
             *
             * Accepting a client-built PDF is the price of stamping at all --
             * SignDocument's docblock says why no free PHP library can do it
             * server-side. It is narrowed as far as validation can narrow it:
             * PDF extension, PDF content type by magic bytes (`mimetypes`, not
             * `mimes`, so a renamed .docx is caught), and the same size ceiling
             * as any other upload. What makes it safe to keep is not this rule
             * but the fact that the version it joins is never deleted.
             */
            'stamped_pdf' => [
                'nullable',
                'file',
                'extensions:pdf',
                'mimetypes:application/pdf',
                'max:'.(int) config('cicto.uploads.max_size_kb'),
            ],

            /*
             * Fractions of the displayed page, origin top-left. `lt:1` on the
             * offsets rather than `max:1`: a mark whose left edge sits exactly
             * at the right margin has no page left to occupy, and silently
             * stamping a zero-width signature is worse than refusing it.
             */
            'placement' => ['nullable', 'array'],
            'placement.page' => ['required_with:placement', 'integer', 'min:1', 'max:10000'],
            'placement.x' => ['required_with:placement', 'numeric', 'min:0', 'lt:1'],
            'placement.y' => ['required_with:placement', 'numeric', 'min:0', 'lt:1'],
            'placement.width' => ['required_with:placement', 'numeric', 'gt:0', 'max:1'],
            'placement.height' => ['required_with:placement', 'numeric', 'gt:0', 'max:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $megabytes = rtrim(rtrim(number_format(
            (int) config('cicto.uploads.max_size_kb') / 1024,
            1,
        ), '0'), '.');

        return [
            'stamped_pdf.extensions' => 'The signed copy could not be prepared. Reload the page and sign again.',
            'stamped_pdf.mimetypes' => 'The signed copy could not be prepared. Reload the page and sign again.',
            'stamped_pdf.file' => 'The signed copy did not finish uploading. Try again.',
            'stamped_pdf.uploaded' => 'The signed copy did not finish uploading. Try again.',
            'stamped_pdf.max' => 'The signed copy is too large. The limit is '.$megabytes.' MB.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $method = $this->enum('method', SignatureMethod::class);

            if ($method?->requiresImage() && blank($this->input('image'))) {
                $validator->errors()->add('image', $method->missingImageMessage());
            }

            /*
             * The two halves of a stamp travel together or not at all.
             *
             * A file with no placement is a PDF nobody can say anything about,
             * and a placement with no file describes a mark on a page that was
             * never produced. Either alone means the browser half-failed, and
             * the honest answer is to refuse rather than record a signature
             * whose story does not add up.
             */
            $hasFile = $this->hasFile('stamped_pdf');
            $hasPlacement = filled($this->input('placement'));

            if ($hasFile !== $hasPlacement) {
                $validator->errors()->add(
                    'stamped_pdf',
                    'The signed copy could not be prepared. Reload the page and sign again.',
                );
            }

            /*
             * A stamp needs something to stamp. A typed signature has no
             * image, so there is nothing to draw onto the page.
             */
            if ($hasFile && $method === SignatureMethod::Typed) {
                $validator->errors()->add(
                    'stamped_pdf',
                    'A typed signature cannot be placed on the page. Draw or upload your signature instead.',
                );
            }

            if ($method === SignatureMethod::Typed) {
                $typed = trim((string) $this->input('typed_name'));

                if ($typed === '') {
                    $validator->errors()->add('typed_name', 'Please type your full name.');
                } elseif (mb_strtolower($typed) !== mb_strtolower((string) $this->user()?->name)) {
                    // You sign as yourself. Typing somebody else's name is not
                    // a signature, it is impersonation with extra steps.
                    $validator->errors()->add(
                        'typed_name',
                        'The typed name must match the name on your account.',
                    );
                }
            }
        });
    }
}
