<?php

namespace App\Http\Requests\Documents;

use App\Enums\SignatureMethod;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            && ($this->user()?->can('sign', $document) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(SignatureMethod::class)],

            // A base64 PNG data URL from the canvas. The bytes are validated
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $method = $this->enum('method', SignatureMethod::class);

            if ($method === SignatureMethod::Drawn && blank($this->input('image'))) {
                $validator->errors()->add('image', 'Please draw your signature before signing.');
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
