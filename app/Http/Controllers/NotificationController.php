<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §12 bell UI.
 *
 * The unread COUNT rides along in shared Inertia props (one indexed query). The
 * dropdown CONTENTS come from here on demand, so a list of notifications is not
 * serialised into every single page payload.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = Notification::query()
            ->forUser($request->user())
            // Explicit ordering. Without it the database is free to return rows
            // in any order it likes, so page 2 can repeat page 1 -- and the
            // newest notification is what the user came here for.
            ->orderByDesc('id')
            ->paginate(25)
            ->through(fn (Notification $notification) => $this->present($notification));

        return Inertia::render('notifications/index', [
            'notifications' => $notifications,
        ]);
    }

    /** Feeds the header dropdown. */
    public function recent(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->forUser($request->user())
            // LIMIT without ORDER BY is not "the latest eight" -- it is whatever
            // eight rows the driver reaches first, which in practice is the
            // OLDEST eight. The bell would then never show a new notification.
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (Notification $notification) => $this->present($notification));

        return response()->json([
            'notifications' => $notifications,
            'unread' => Notification::query()->forUser($request->user())->unread()->count(),
        ]);
    }

    /**
     * Mark read, then redirect. One click, no race between "open" and "mark
     * read", and no role branching -- the document URL is identical for every
     * role by design.
     */
    public function go(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->markRead();

        return redirect()->route('documents.show', $notification->document_id);
    }

    public function readAll(Request $request): RedirectResponse
    {
        Notification::query()
            ->forUser($request->user())
            ->unread()
            ->update(['read_at' => now()]);

        return back()->with('toast', ['type' => 'success', 'message' => 'All notifications marked as read.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type->value,
            'icon' => $notification->type->icon(),
            'title' => $notification->title,
            'body' => $notification->body,
            'control_number' => $notification->control_number,
            'document_id' => $notification->document_id,
            'read' => $notification->isRead(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
