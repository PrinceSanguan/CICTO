<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Support\Presenters\DocumentPresenter;
use App\Support\Reporting\AdminTrend;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §4's Reports item in the Admin Panel sidebar.
 *
 * Distinct from /reports, which is the clerk-facing Reports & Analytics screen
 * with the export buttons. This one is the panel's own view: the approved /
 * pending / rejected trend and the queue of documents still waiting on this
 * office -- the two cards the design puts on this page and nothing else.
 */
class AdminReportController extends Controller
{
    public function __construct(
        private readonly DocumentPresenter $presenter,
        private readonly AdminTrend $trend,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('admin/reports/index', [
            'office' => $user->office?->only(['id', 'code', 'name']),
            'trend' => $this->trend->monthly($user),
            'pending' => Document::query()
                ->visibleTo($user)
                ->with([
                    'creator:id,name',
                    'documentType:id,name',
                    'openMovement.toOffice:id,name',
                    'lastMovement.toOffice:id,name',
                ])
                ->whereIn('documents.status', AdminTrend::PENDING_STATES)
                ->orderByDesc('documents.updated_at')
                ->limit(10)
                ->get()
                ->map(fn (Document $document) => [
                    ...$this->presenter->listItem($document),
                    'uploaded_by' => $document->creator?->name,
                    'updated_at' => $document->updated_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }
}
