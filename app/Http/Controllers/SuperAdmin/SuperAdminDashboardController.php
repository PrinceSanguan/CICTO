<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use App\Support\Presenters\DocumentPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §4 Super Admin Panel. System-wide.
 */
class SuperAdminDashboardController extends Controller
{
    /**
     * Workflow states the Action column's filter offers, and the stored
     * statuses each one finds.
     *
     * The raw states, not the four public labels: this screen is for the role
     * that needs to tell Under Review from Approved.
     *
     * NO REJECTED, and that is the client's request of 2026-09-19, pointing at
     * this very dropdown. Return replaced Reject on 2026-09-15 and the word
     * went from every other list on 2026-09-16; this filter was the one place
     * still offering it. The documents refused under the old button still
     * exist, so Returned finds them -- exactly as the Track Documents filter
     * and DocumentStatus::publicLabel() already do. Nothing is hidden, one
     * option is gone.
     *
     * @var array<string, list<string>>
     */
    private const STATUS_FILTERS = [
        'initiated' => ['initiated'],
        'under_review' => ['under_review'],
        'approved' => ['approved'],
        'returned' => ['returned', 'rejected'],
        'completed' => ['completed'],
    ];

    /**
     * A link bookmarked before the option went still asks for `rejected`.
     * It lands on Returned, which is where those documents are listed now,
     * rather than silently showing every document under a blank filter.
     *
     * @var array<string, string>
     */
    private const RETIRED_FILTERS = [
        'rejected' => 'returned',
    ];

    public function __construct(private readonly DocumentPresenter $presenter) {}

    public function index(Request $request): Response
    {

        return Inertia::render('super-admin/dashboard', [
            'stats' => [
                // Archived rows are counted deliberately: filing a completed
                // document away must not make the work disappear from totals.
                'documents' => Document::query()->count(),
                'open' => Document::query()->active()->stillOpen()->count(),
                'overdue' => Document::query()->active()->overdue()->count(),
                'offices' => Office::query()->active()->count(),
                'users' => User::query()->active()->count(),
            ],
            'documents' => $this->page($request),
        ]);
    }

    /**
     * The paginated register behind §4's All Documents table.
     *
     * Paginated rather than a fixed `limit`, because this is the one screen
     * that promises to show everything: a hard cap here would quietly hide the
     * register from the only role allowed to audit it.
     *
     * @return array<string, mixed>
     */
    private function page(Request $request): array
    {
        $search = trim((string) $request->string('q'));
        $status = $request->string('status')->toString();

        $status = self::RETIRED_FILTERS[$status] ?? $status;
        $status = array_key_exists($status, self::STATUS_FILTERS) ? $status : null;

        // The stored statuses the chosen option covers; null for "All statuses".
        $statuses = $status === null ? null : self::STATUS_FILTERS[$status];

        $page = Document::query()
            ->active()
            ->with([
                'documentType:id,name',
                'creator:id,name',
                'openMovement.toOffice:id,name',
                'lastMovement.toOffice:id,name',
            ])
            ->when($search !== '', fn ($query) => $query->search($search))
            ->when($statuses, fn ($query, array $statuses) => $query->whereIn('documents.status', $statuses))
            ->orderByDesc('documents.created_at')
            ->paginate(perPage: 15)
            ->withQueryString();

        return [
            'filters' => ['q' => $search, 'status' => $status],
            'data' => collect($page->items())
                ->map(fn (Document $document) => [
                    ...$this->presenter->listItem($document),
                    'uploaded_by' => $document->creator?->name,
                ])
                ->all(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ];
    }
}
