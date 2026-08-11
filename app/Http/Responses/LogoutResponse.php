<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where signing out lands.
 *
 * Fortify's default drops you on the landing page, which on a shared counter
 * terminal is ambiguous -- the landing page looks the same whether or not the
 * sign-out worked. §4's design answers that with a dedicated confirmation, so
 * the person stepping away from the machine can see that it happened.
 *
 * The page is public and stateless: by the time it renders there is no session
 * left to read, which is the point.
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? response()->json(['message' => 'Signed out.'])
            : redirect()->route('logout.confirmed');
    }
}
