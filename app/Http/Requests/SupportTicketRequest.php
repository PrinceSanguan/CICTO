<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §23 support ticket.
 *
 * No `email` or `name` field: both are taken from the signed-in user. A ticket
 * form that lets you type somebody else's address is a way to send mail as
 * them, and this one posts to a municipal inbox.
 */
class SupportTicketRequest extends FormRequest
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
        return [
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Please describe the problem so somebody can act on it.',
        ];
    }
}
