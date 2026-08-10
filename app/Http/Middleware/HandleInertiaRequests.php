<?php

namespace App\Http\Middleware;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,

                // Role and office drive which sidebar renders and which buttons
                // exist. They are hints for the UI only -- every one of them has
                // a named server-side twin in a Policy or in EnsureRole.
                'role' => $user?->role,
                'office' => $user?->office === null ? null : [
                    'id' => $user->office->id,
                    'code' => $user->office->code,
                    'name' => $user->office->name,
                ],
                'can' => $user?->role->capabilities() ?? [],
            ],

            // Unread badge in the header. One indexed COUNT, evaluated lazily so
            // it costs nothing on pages that never render the bell. The dropdown
            // contents come from a separate on-demand endpoint rather than
            // riding along on every page payload.
            'unreadNotifications' => fn () => $user instanceof User
                ? Notification::query()->forUser($user)->unread()->count()
                : 0,

            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
