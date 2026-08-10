<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Document;
use App\Support\Presenters\DocumentPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2 delivers the working queue that each role needs to do its job.
 *
 * The §18 metric widgets (total, monthly processed, delayed, approval rate) are
 * feature #14 and land in Phase 4; this is deliberately the operational view,
 * not the analytics one.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DocumentPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $base = fn () => Document::query()->visibleTo($user)->active()
            ->with(['documentType:id,name', 'openMovement.toOffice:id,name']);

        // "In my office right now" is the whole point of the system for an
        // Admin. For a plain user it is "what I submitted".
        $inbox = $user->office_id !== null && $user->atLeast(Role::Admin)
            ? $base()->heldByOffice($user->office_id)
            : $base()->where('documents.created_by_id', $user->id);

        return Inertia::render('dashboard', [
            'stats' => [
                'inbox' => (clone $inbox)->stillOpen()->count(),
                'overdue' => (clone $inbox)->overdue()->count(),
                'approaching' => (clone $inbox)->approachingDeadline()->count(),
                'submitted' => Document::query()->where('created_by_id', $user->id)->active()->count(),
            ],
            'recent' => (clone $inbox)
                ->stillOpen()
                ->orderByDesc('documents.created_at')
                ->limit(10)
                ->get()
                ->map(fn (Document $document) => $this->presenter->listItem($document))
                ->all(),
        ]);
    }
}
