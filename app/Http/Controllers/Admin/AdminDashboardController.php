<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Builders\DocumentBuilder;
use App\Models\Document;
use App\Support\Presenters\DocumentPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §4 Admin Panel. Scoped to the admin's own office by DocumentBuilder::visibleTo.
 *
 * The client's design names four tiles -- Total, Pending, Approved, Rejected --
 * over a workflow that has six states. The mapping is spelled out in
 * BUCKETS below rather than left implicit, because "Pending" is the one a
 * records officer will be asked to defend and it has to mean the same thing on
 * the tile, in the table filter and on the chart.
 */
class AdminDashboardController extends Controller
{
    public function __construct(private readonly DocumentPresenter $presenter) {}

    /**
     * The client's four buckets, expressed in workflow states.
     *
     * `approved` and `completed` are one bucket: a completed document was
     * approved first, and showing them apart would make the Approved tile read
     * lower than the number of documents the office actually approved.
     *
     * @var array<string, list<string>>
     */
    private const BUCKETS = [
        'pending' => ['initiated', 'under_review', 'returned'],
        'approved' => ['approved', 'completed'],
        'rejected' => ['rejected'],
    ];

    /** How many months of history the trend chart covers. */
    private const TREND_MONTHS = 12;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $search = trim((string) $request->string('q'));
        $sort = $request->string('sort')->toString();
        $bucket = $request->string('status')->toString();

        $sort = in_array($sort, ['priority', 'updated', 'created', 'title'], true) ? $sort : 'priority';
        $bucket = array_key_exists($bucket, self::BUCKETS) ? $bucket : null;

        $base = fn (): DocumentBuilder => Document::query()->visibleTo($user);

        // Resolved before the closure so the states are a plain list rather
        // than a lookup on a value the closure still sees as nullable.
        $bucketStates = $bucket === null ? null : self::BUCKETS[$bucket];

        $listing = $base()
            ->with([
                'documentType:id,name',
                'creator:id,name',
                'openMovement.toOffice:id,name',
                // No column list: currentFile is a latestOfMany relation, so
                // it joins a subquery and an unqualified select is ambiguous.
                'currentFile',
            ])
            ->when($search !== '', fn ($query) => $query->search($search))
            ->when($bucketStates !== null, fn ($query) => $query->whereIn('documents.status', $bucketStates));

        $listing = match ($sort) {
            'updated' => $listing->orderByDesc('documents.updated_at'),
            'created' => $listing->orderByDesc('documents.created_at'),
            // lower() on both sides: the collation is not guaranteed to be
            // case-insensitive on either driver.
            'title' => $listing->orderByRaw('lower(documents.title) asc'),
            default => $this->byPriority($listing),
        };

        $page = $listing
            ->paginate(perPage: 10)
            ->withQueryString();

        return Inertia::render('admin/dashboard', [
            'office' => $user->office?->only(['id', 'code', 'name']),
            'filters' => [
                'q' => $search,
                'sort' => $sort,
                'status' => $bucket,
            ],
            'stats' => $this->stats($user),
            'documents' => [
                'data' => collect($page->items())
                    ->map(fn (Document $document) => $this->row($document))
                    ->all(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            // Retained under its original name: this is §4's office work queue
            // and AdminQueueOrderTest pins its ordering. The paginated table
            // above is the client's Document Management view; they are the same
            // records seen two ways, so both stay.
            'queue' => collect($page->items())
                ->map(fn (Document $document) => $this->row($document))
                ->all(),
            'trend' => $this->trend($user),
            'pending' => $base()
                // currentFile too: row() reads it, and without this the five
                // pending rows each cost their own query.
                ->with([
                    'creator:id,name',
                    // No column list: currentFile is a latestOfMany relation, so
                    // it joins a subquery and an unqualified select is ambiguous.
                    'currentFile',
                ])
                ->whereIn('documents.status', self::BUCKETS['pending'])
                ->orderByDesc('documents.updated_at')
                ->limit(5)
                ->get()
                ->map(fn (Document $document) => $this->row($document))
                ->all(),
        ]);
    }

    /**
     * Urgent work first -- the default, because this table IS the office work
     * queue and a records officer opening it should see what is late, not what
     * was touched most recently.
     *
     * priority is a STRING column, so a plain ORDER BY sorts it alphabetically:
     * high < low < normal < urgent. That puts "high" below "low", exactly
     * backwards. Literal SQL with bound values is portable across all three
     * drivers and interpolates nothing. AdminQueueOrderTest asserts this
     * ranking still matches DocumentPriority::weight(), so adding a case to the
     * enum without updating this fails the build.
     */
    private function byPriority(DocumentBuilder $query): DocumentBuilder
    {
        return $query
            ->orderByRaw(
                'case documents.priority when ? then 3 when ? then 2 when ? then 1 else 0 end desc',
                [
                    DocumentPriority::Urgent->value,
                    DocumentPriority::High->value,
                    DocumentPriority::Normal->value,
                ],
            )
            // due_at is nullable, and NULLs sort first on PostgreSQL and last
            // on MySQL. Force undated documents last on both.
            ->orderByRaw('case when documents.due_at is null then 1 else 0 end')
            ->orderBy('documents.due_at');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Document $document): array
    {
        $file = $document->currentFile;

        return [
            ...$this->presenter->listItem($document),
            'uploaded_by' => $document->creator?->name,
            'updated_at' => $document->updated_at?->toIso8601String(),
            'bucket' => $this->bucketFor($document->status),

            // Null when the document has no attachment, or when the retention
            // pruner has reclaimed the bytes. Either way the row must not offer
            // a download that can only answer 404 or 410.
            'current_file_id' => $file !== null && ! $file->isPurged() ? $file->id : null,
        ];
    }

    private function bucketFor(DocumentStatus $status): string
    {
        foreach (self::BUCKETS as $name => $states) {
            if (in_array($status->value, $states, true)) {
                return $name;
            }
        }

        return 'pending';
    }

    /**
     * The four tiles.
     *
     * One grouped query rather than four counts: on a register of any size the
     * dashboard should not cost four table scans to draw a header.
     *
     * @return array<string, int>
     */
    private function stats(mixed $user): array
    {
        // Aliased explicitly. Plucking an unaliased `count(*)` works on MySQL
        // and SQLite, which name the result column after the expression, but
        // PostgreSQL names it `count` -- and the mismatch surfaces as an
        // "Undefined property" 500 on the one driver production runs.
        $counts = Document::query()
            ->visibleTo($user)
            ->selectRaw('documents.status as status, count(*) as total')
            ->groupBy('documents.status')
            ->toBase()
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value);

        $sum = fn (string $bucket): int => collect(self::BUCKETS[$bucket])
            ->sum(fn (string $status) => $counts[$status] ?? 0);

        return [
            'total' => (int) $counts->sum(),
            'pending' => $sum('pending'),
            'approved' => $sum('approved'),
            'rejected' => $sum('rejected'),
        ];
    }

    /**
     * Approved / pending / rejected per month for the last year.
     *
     * EXTRACT rather than DATE_FORMAT, and the month grid is built in PHP so a
     * month with no documents is a zero rather than a missing point -- a line
     * chart that silently skips empty months misreports the shape of the year.
     *
     * @return list<array<string, mixed>>
     */
    private function trend(mixed $user): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths(self::TREND_MONTHS - 1);

        // Whole literal strings per driver, matching DocumentStats: EXTRACT is
        // identical on PostgreSQL and MySQL, but SQLite (tests) has no EXTRACT
        // at all and needs strftime. Concatenating fragments is how a report
        // ends up silently wrong on one driver only.
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        $select = $sqlite
            ? "cast(strftime('%Y', documents.created_at) as integer) as y, cast(strftime('%m', documents.created_at) as integer) as m, documents.status as status, count(*) as total"
            : 'extract(year from documents.created_at) as y, extract(month from documents.created_at) as m, documents.status as status, count(*) as total';

        $group = $sqlite
            ? "strftime('%Y', documents.created_at), strftime('%m', documents.created_at), documents.status"
            : 'extract(year from documents.created_at), extract(month from documents.created_at), documents.status';

        $rows = Document::query()
            ->visibleTo($user)
            ->where('documents.created_at', '>=', $start)
            ->selectRaw($select)
            ->groupByRaw($group)
            ->toBase()
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', (int) $row->y, (int) $row->m);
            $bucket = $this->bucketFor(DocumentStatus::from((string) $row->status));

            $buckets[$key][$bucket] = ($buckets[$key][$bucket] ?? 0) + (int) $row->total;
        }

        $series = [];

        for ($i = 0; $i < self::TREND_MONTHS; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');

            $series[] = [
                'month' => $key,
                'label' => $month->format('M'),
                'approved' => $buckets[$key]['approved'] ?? 0,
                'pending' => $buckets[$key]['pending'] ?? 0,
                'rejected' => $buckets[$key]['rejected'] ?? 0,
            ];
        }

        return $series;
    }
}
