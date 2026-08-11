<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentPriority;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * Spec §5's Submit Document form: title, department, document type, priority,
 * description, remarks, file upload.
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Document::class) ?? false;
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
            'originating_office_id' => ['required', 'integer', Rule::exists('offices', 'id')->where('is_active', true)],
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
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'document_type_id' => 'document type',
            'originating_office_id' => 'department',
        ];
    }
}
