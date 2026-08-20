<?php

namespace App\Http\Requests\SuperAdmin;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * §4's Manage Users screen, setting a password for somebody else.
 *
 * Like StoreUserRequest, the authorize() below is presentational: the check
 * that matters is inside App\Actions\Users\ResetAccountPassword, which refuses
 * a non-Super-Admin actor whatever route reaches it.
 *
 * THE FIELD IS `your_password`, NOT `current_password`, AND THAT IS
 * DELIBERATE. The `current_password` validation rule compares against the
 * AUTHENTICATED user -- the administrator -- but on a screen about somebody
 * else's account, a field labelled "current password" reads as that person's
 * current password, which the administrator does not know and never will. The
 * rule keeps its name; the field takes the name of the thing being asked for.
 */
class ResetUserPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Re-authentication, in the form rather than in front of it.
             *
             * The house pattern for a high-consequence action is the
             * password.confirm middleware (documents.php uses it for
             * signatures, settings.php for the security page). It cannot be
             * used here: RequirePassword redirects rather than 423s for an
             * Inertia POST, which is a full page visit, and the typed password
             * and its confirmation are gone by the time the user comes back
             * with nothing on screen explaining why. Asking for the actor's own
             * password as a field buys the same property -- an unattended
             * signed-in browser cannot take over an account -- without throwing
             * the form away.
             */
            'your_password' => $this->currentPasswordRules(),

            /*
             * Password::defaults(), for the same reason StoreUserRequest gives:
             * a password set here opens an account that may hold more access
             * than the one setting it, so it is held to whatever profile the
             * deployment enforces rather than a convenient one.
             */
            'password' => ['required', 'confirmed', Password::defaults()],

            // Absent on an ordinary "they forgot it" reset; ticked when the
            // account may be in someone else's hands.
            'revoke_second_factors' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'your_password.required' => 'Confirm your own password to continue.',
            'your_password.current_password' => 'That is not your password.',
        ];
    }
}
