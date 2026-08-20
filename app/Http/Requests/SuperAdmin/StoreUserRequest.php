<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * §4's Add New Admin form.
 *
 * The authorization check here is presentational only -- CreateUserAccount
 * refuses a non-Super-Admin actor whatever reaches it, and that is the check
 * that matters. Both exist on purpose: the route guard can be edited, the
 * request can be edited, and the action is the one place that cannot be
 * bypassed by adding a new caller.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * Lower-case the address before anything looks at it.
     *
     * Every OTHER door into users.email already does this: config/fortify.php
     * sets `lowercase_usernames`, so Fortify canonicalises on sign-in and on
     * the password-reset request, and `cicto:user` lower-cases its argument.
     * This screen did not, and the asymmetry was a trap with a long fuse.
     *
     * Type "Maria.Santos@baliwag.gov.ph" here and the row is stored with
     * capitals; sign-in lower-cases what is typed, and on PostgreSQL -- where
     * `=` is case-sensitive -- the lookup finds nothing. The account is
     * unreachable and the password is not the reason, so the obvious remedy,
     * setting a new password for them, does not fix it either. Worse, the
     * console fallback `cicto:user Maria.Santos@...` lower-cases, finds no
     * match, and takes the CREATE branch: a second account for the same person.
     *
     * Canonicalising on the way in makes the unique index do its job.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email:rfc', 'max:191', Rule::unique('users', 'email')],

            /*
             * Password::defaults(), not a hand-rolled rule.
             *
             * AppServiceProvider sets the production profile there
             * (min 12, mixed case, letters, numbers, symbols), and an account
             * created from this screen has at least as much access as one
             * created anywhere else -- often more. It must be held to the same
             * bar rather than a convenient one.
             */
            'password' => ['required', 'confirmed', Password::defaults()],

            'role' => ['required', Rule::enum(Role::class)],

            // Required for every role except Super Admin, who has no office by
            // definition -- office scoping is what they exist to see past.
            'office_id' => [
                Rule::requiredIf(fn () => $this->input('role') !== Role::SuperAdmin->value),
                'nullable',
                'integer',
                Rule::exists('offices', 'id')->where('is_active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'office_id.required' => 'Choose the office this account belongs to.',
            'email.unique' => 'An account already uses that email address.',
        ];
    }
}
