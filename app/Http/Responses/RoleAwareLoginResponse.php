<?php

namespace App\Http\Responses;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/**
 * Spec §3's "separate login entry points", resolved after authentication.
 *
 * A user can arrive authenticated by four different routes -- password, two
 * factor, passkey, or having just registered -- and all four must land in the
 * same place. Binding only LoginResponse is the classic half-fix.
 *
 * All three Fortify contracts are implemented rather than just one: Fortify's
 * controllers declare the specific interface as their return type, so binding
 * this class to RegisterResponse while implementing only LoginResponse is a
 * TypeError at runtime instead of a container error at boot.
 *
 * Passkeys live in a separate package with their own contract and their own
 * JSON response shape -- see RoleAwarePasskeyLoginResponse, which reuses
 * destinationFor() below.
 *
 * The role is read from the database record, never from anything the login form
 * posted. The three /login/* URLs differ only in presentation.
 */
class RoleAwareLoginResponse implements LoginResponseContract, RegisterResponseContract, TwoFactorLoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->to($this->destinationFor($request));
    }

    /**
     * Where this request should land, given who just authenticated.
     *
     * Shared with the passkey response so every entry point agrees.
     */
    public function destinationFor(Request $request): string
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return route('dashboard');
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->safeIntended($request, $user, route($user->role->homeRoute()));
    }

    /**
     * redirect()->intended() replays whatever URL bounced the user to login.
     * If that URL sits under a panel their role cannot enter, replaying it
     * sends them straight into a 403 immediately after a successful login, so
     * fall back to their own home instead.
     */
    private function safeIntended(Request $request, User $user, string $home): string
    {
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || $intended === '') {
            return $home;
        }

        $path = '/'.ltrim((string) parse_url($intended, PHP_URL_PATH), '/');

        foreach ([Role::Admin, Role::SuperAdmin] as $role) {
            $prefix = '/'.$role->panelPrefix();

            if (str_starts_with($path, $prefix.'/') || $path === $prefix) {
                if (! $user->role->atLeast($role)) {
                    return $home;
                }
            }
        }

        return $intended;
    }
}
