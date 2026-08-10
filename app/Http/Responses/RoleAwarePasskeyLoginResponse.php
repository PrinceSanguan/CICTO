<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

/**
 * Passkey sign-in lands on the same role-aware panel as every other entry
 * point.
 *
 * laravel/passkeys is a SEPARATE package from Fortify with its own response
 * contract, so binding Fortify's three contracts does not cover it. Left
 * unbound, its default sends every user to config('passkeys.redirect') -- and
 * there is no config/passkeys.php in this project, so that resolves to '/',
 * dumping an Admin on the public landing page after a successful sign-in.
 *
 * The JSON branch matters: the @laravel/passkeys client calls Passkeys.verify()
 * over fetch and navigates to the `redirect` value itself, so returning a bare
 * RedirectResponse would leave the browser sitting on the login page.
 */
class RoleAwarePasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function __construct(private readonly RoleAwareLoginResponse $base) {}

    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $destination = $this->base->destinationFor($request);

        if ($request->wantsJson()) {
            return new JsonResponse(['redirect' => $destination], 200);
        }

        return redirect()->to($destination);
    }
}
