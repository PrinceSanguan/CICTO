<?php

namespace App\Http\Controllers;

use App\Models\Builders\DocumentBuilder;
use App\Models\Document;
use App\Support\Presenters\DocumentPresenter;
use App\Support\Reporting\DocumentStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §18 Dashboard: total documents, monthly processed, delayed, approval rate,
 * plus the work waiting on the person looking at it.
 *
 * Two rows of numbers, deliberately answering two different questions:
 *
 *  - The §18 headline figures come from DocumentStats::summary(), the same
 *    method Reports uses, so the dashboard and the report can never quote
 *    different totals for the same office.
 *  - The queue counters below are operational: what is in my office right now,
 *    what is late, what is about to be.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DocumentPresenter $presenter,
        private readonly DocumentStats $stats,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        // NOT ->active(). Per decision D16 the headline figures COUNT archived
        // documents: archiving is filing, not deletion, and excluding filed
        // work would silently undercount everything this office completed --
        // the opposite of what the archive is for. The queue below is a
        // different question and does exclude them.
        $base = fn () => Document::query()->visibleTo($user)->active()
            ->with([
                'documentType:id,name',
                'openMovement.toOffice:id,name',
                'lastMovement.toOffice:id,name',
            ]);

        /*
         * An office's clerk and its Admin see ONE queue.
         *
         * This used to split by role: an Admin saw only what sat on the desk
         * right now, a clerk only what they had filed. So the moment a clerk's
         * document left for the next office it vanished from the Admin's
         * dashboard, while the clerk could still follow its status. The client
         * asked for the office to share the view (2026-09-13): what it holds,
         * everything it sent out that is still travelling, and anything this
         * person filed against another office.
         */
        $officeId = $user->office_id;

        $inbox = $officeId !== null
            ? $base()->where(function (DocumentBuilder $query) use ($officeId, $user): void {
                $query->heldByOffice($officeId)
                    ->orWhere('documents.originating_office_id', $officeId)
                    ->orWhere('documents.created_by_id', $user->id);
            })
            : $base()->where('documents.created_by_id', $user->id);

        return Inertia::render('dashboard', [
            'summary' => $this->stats->summary($user),
            'stats' => [
                'inbox' => (clone $inbox)->stillOpen()->count(),
                'overdue' => (clone $inbox)->overdue()->count(),
                'approaching' => (clone $inbox)->approachingDeadline()->count(),
                // Archived submissions still count as things this office
                // submitted, so this one is not scoped to the active list.
                'submitted' => Document::query()
                    ->where(function (DocumentBuilder $query) use ($officeId, $user): void {
                        $query->where('documents.created_by_id', $user->id)
                            ->when($officeId !== null, fn (DocumentBuilder $sub) => $sub
                                ->orWhere('documents.originating_office_id', $officeId));
                    })
                    ->count(),
            ],
            'recent' => (clone $inbox)
                ->stillOpen()
                ->orderByDesc('documents.created_at')
                ->limit(10)
                ->get()
                ->map(fn (Document $document) => $this->presenter->listItem($document))
                ->all(),
            'hasOffice' => $officeId !== null,
        ]);
    }
}
