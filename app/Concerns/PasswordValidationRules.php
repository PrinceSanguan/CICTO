<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        /*
         * `bail` is load-bearing, not tidiness.
         *
         * Only `required` is an implicit rule, so without it a field posted as
         * an ARRAY fails `string` and then still reaches `current_password`,
         * which hands the array to password_verify() and throws an uncatchable
         * TypeError. The response is a 500 rather than the intended validation
         * error -- on the two routes in the system that re-authenticate somebody
         * before letting them change a password. `bail` stops at the first
         * failure, so the array is refused as text and never reaches the hasher.
         */
        return ['bail', 'required', 'string', 'current_password'];
    }
}
