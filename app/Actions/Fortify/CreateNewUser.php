<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\OutgoingMail;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Container key holding the account this request just created.
     *
     * Fortify fires Registered -- and therefore sends the verification mail --
     * BEFORE it logs the new user in, so a transport failure aborts the
     * controller with the row committed and the session still a guest. The
     * handler in bootstrap/app.php finishes the sign-in that Fortify was one
     * line away from doing, and this is how it knows who. Re-finding the user
     * from the posted email would work too, and is exactly the kind of lookup
     * that stops being obviously safe the moment somebody changes the
     * validation rules.
     */
    public const REGISTERED = 'cicto.registration.user';

    /**
     * Validate and create a newly registered user.
     *
     * On a host that cannot send, the account is marked verified on creation.
     * That looks like a hole and is the opposite: as of 2026-08-23 User
     * implements MustVerifyEmail, so `verified` on the six protected route
     * groups is a real gate, and the ONLY way through it for a self-registered
     * account is a link that a null transport cannot deliver. Leaving
     * `email_verified_at` null on such a host would hand somebody an account
     * that can sign in and reach nothing, with a Resend button that
     * RequireOutgoingMail correctly refuses -- a dead end with no self-service
     * exit and no admin action to open it either, since nothing in
     * SuperAdminUserController marks a user verified.
     *
     * It is not a weakening of anything that previously held. Before the
     * contract was declared, `verified` was inert and every self-registered
     * account reached every screen regardless of this column. This keeps that
     * exact behaviour on hosts where verification is impossible, and enforces
     * verification properly everywhere it is possible.
     *
     * Accounts created by an administrator are already verified on creation for
     * the same reason -- see App\Actions\Users\CreateUserAccount.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        /*
         * forceFill, and a second write, rather than a fourth key above.
         *
         * `email_verified_at` is deliberately absent from User's #[Fillable]
         * list, so passing it to create() is silently DROPPED -- the row is
         * written with a null and no error is raised anywhere. Adding it to
         * Fillable would fix that and open a much worse hole: this array is
         * built from request input, so a self-registering client could post
         * `email_verified_at` and verify itself.
         *
         * The value here is computed on the server from the transport, never
         * read from $input, which is what makes bypassing the guard safe.
         */
        if (! OutgoingMail::isConfigured()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        app()->instance(self::REGISTERED, $user);

        return $user;
    }
}
