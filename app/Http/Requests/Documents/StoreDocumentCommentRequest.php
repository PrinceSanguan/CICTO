<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §16 comments and collaboration.
 */
class StoreDocumentCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            && ($this->user()?->can('comment', $document) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $document = $this->route('document');

        return [
            'body' => ['required', 'string', 'max:2000'],

            // Staff-only notes. A plain user must not be able to set this --
            // otherwise the submitter could hide a comment from the office.
            'is_internal' => ['nullable', 'boolean'],

            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('document_comments', 'id')->where(
                    'document_id',
                    $document instanceof Document ? $document->id : 0,
                ),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! ($this->user()?->isAdmin() || $this->user()?->isSuperAdmin())) {
            $this->merge(['is_internal' => false]);
        }
    }
}
