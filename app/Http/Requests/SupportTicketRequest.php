<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §23 support ticket.
 *
 * The design asks for a name and an email address. Both are collected and both
 * are treated as CONTACT details -- where the office should reply -- never as
 * identity: HelpController attributes every ticket to the signed-in account.
 * A ticket form that lets you file as somebody else is a way to send mail as
 * them, and this one posts to a municipal inbox.
 */
class SupportTicketRequest extends FormRequest
{
    /**
     * The design's Issue Type list.
     *
     * A closed set rather than free text, because this string is what routes a
     * ticket to the right desk once an inbox exists.
     *
     * @var list<string>
     */
    public const ISSUE_TYPES = [
        'Cannot find a document',
        'Document stuck at an office',
        'QR code will not scan',
        'Login or password problem',
        'Wrong information on a document',
        'Something else',
    ];

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
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:191'],

            // Optional in the design, and genuinely optional here: plenty of
            // problems are not about one document.
            'tracking_number' => ['nullable', 'string', 'max:50'],

            'issue_type' => ['required', Rule::in(self::ISSUE_TYPES)],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * The subject line, derived rather than typed.
     *
     * The design has no Subject field -- it has an Issue Type -- so building
     * one here keeps the mail readable in an inbox without asking the reporter
     * for something the form does not show.
     */
    public function subject(): string
    {
        $tracking = trim((string) $this->string('tracking_number'));
        $type = (string) $this->string('issue_type');

        return $tracking === '' ? $type : $type.' — '.$tracking;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Please describe the problem so somebody can act on it.',
            'issue_type.required' => 'Please choose the kind of problem you are reporting.',
            'issue_type.in' => 'Please choose one of the listed issue types.',
        ];
    }
}
