<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §8 Track Documents: search by control number, filter by status.
 */
class IndexDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $publicStatuses = array_column(DocumentStatus::publicOptions(), 'value');

        return [
            'q' => ['nullable', 'string', 'max:100'],

            // The §8 filter uses the client-facing names (Pending, In Process,
            // Rejected, Completed), which map to one or two internal statuses
            // each. DocumentStatus::fromPublicValue expands them.
            'status' => ['nullable', 'string', Rule::in($publicStatuses)],

            'priority' => ['nullable', Rule::enum(DocumentPriority::class)],
            'office_id' => ['nullable', 'integer', 'exists:offices,id'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'due' => ['nullable', Rule::in(['overdue', 'approaching'])],

            // Validated through Rule::in because they are interpolated into
            // orderBy. This is not optional.
            'sort' => ['nullable', Rule::in(['created_at', 'control_number', 'status', 'due_at'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],

            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => is_string($this->input('q')) ? trim($this->input('q')) : null,
        ]);
    }

    /**
     * The filter set echoed back to the page, so the UI can re-render its own
     * state and links stay shareable.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->only([
                'q', 'status', 'priority', 'office_id', 'document_type_id', 'from', 'to', 'due', 'sort', 'dir', 'per_page',
            ]),
            static fn ($value) => $value !== null && $value !== '',
        );
    }
}
