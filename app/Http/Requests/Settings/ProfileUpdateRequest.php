<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),

            /*
             * The account's password, to move it to another address (client
             * request, 2026-09-28: each person may change their own email).
             * The address is where the sign-in code goes, so changing it hands
             * over the account; somebody at a desk that was left signed in
             * must not be able to do that without the password.
             */
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->changesEmail()),
                'nullable',
                'string',
                'current_password',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password to change your email address.',
            'current_password.current_password' => 'That is not your current password.',
        ];
    }

    public function changesEmail(): bool
    {
        return mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower((string) $this->user()?->email);
    }
}
