<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for role-exclusive panels.
 *
 * Constructed with EnsureRole::using(Role::Admin) rather than a stringly-typed
 * 'role:admin' alias: renaming a case then breaks the build instead of silently
 * opening a route, phpstan checks it, and grep finds every guarded group.
 */
class EnsureRole
{
    /**
     * Marks a refusal as route-level rather than record-level.
     *
     * Matched by the exception handler, so it is a constant rather than a
     * string repeated in two files.
     */
    public const ROLE_DENIED = 'cicto.role_denied';

    public static function using(Role ...$roles): string
    {
        return static::class.':'.implode(',', array_map(
            static fn (Role $role) => $role->value,
            $roles,
        ));
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);
        abort_unless($user->is_active, 403, 'This account has been deactivated.');

        $allowed = array_map(static fn (string $role) => Role::from($role), $roles);

        /*
         * 403, never 404 or a redirect: the plan's Phase 1 exit criterion is
         * that a User typing an Admin URL gets a refusal, not a hidden button.
         *
         * The message is carried through so the error page can tell this apart
         * from a document-scoping refusal. Without it every 403 rendered the
         * same copy -- an Admin who typed a Super Admin URL was told the
         * document belonged to another office and to ask their department head
         * to forward it, which is about a document they never opened.
         */
        abort_unless($user->hasRole(...$allowed), 403, self::ROLE_DENIED);

        return $next($request);
    }
}
