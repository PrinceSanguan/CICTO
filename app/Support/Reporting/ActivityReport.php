<?php

namespace App\Support\Reporting;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Support\Deadlines;
use App\Support\Presenters\DocumentPresenter;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use stdClass;

/**
 * §19 artifact 4, user activity -- the line item that belongs to no other
 * feature, so it only surfaces at acceptance. Now the Reports page's "User
 * activity" card, by document and by person (client request, 2026-09-25).
 *
 * It used to be one table of every person with an action count, which grew
 * sideways and told a records officer nothing about WHAT anybody did. Now it
 * is two lists that stay folded until clicked:
 *
 *  - by document: one row per document with activity in the period ("Affidavit
 *    of Non-Filing, 09/25/2026"), opening onto that document's whole trail --
 *    every step, who took it and from which office;
 *  - by person: one row per member of staff, opening onto everything they did.
 *
 * Both are scoped exactly as the rest of §19 is, through visibleTo(): an Admin
 * sees the activity on their office's documents, a Super Admin on all of them.
 *
 * WHAT IS NOT HERE: remarks, files, comments. Those are the document itself,
 * and the document itself is behind the Security PIN on its own page. This is
 * the tracking record -- what happened, when, where and by whom -- which is
 * what an audit of staff activity needs.
 */
final class ActivityReport
{
    public const PER_PAGE = 10;

    /** How much of one person's history a single click loads. */
    public const USER_TRAIL_LIMIT = 100;

    public function __construct(private readonly DocumentPresenter $presenter) {}

    /**
     * The Reports page's period in months, from `?months=`: 1 to 36, the
     * configured default when absent. One rule for the page and for this
     * card's lists, so a click inside the card never reports a different
     * period from the one printed above it.
     */
    public static function months(Request $request): int
    {
        $months = (int) $request->query('months', (string) config('cicto.reports.default_months', 12));

        return max(1, min(36, $months));
    }

    /** The first day of the reporting period, the same window the charts use. */
    public static function since(int $months): CarbonInterface
    {
        return Deadlines::now()->startOfMonth()->subMonths(max(1, $months) - 1);
    }

    /**
     * Documents with activity in the period, most recently active first.
     *
     * @return array{data: list<array<string, mixed>>, page: int, last_page: int, total: int}
     */
    public function documents(User $viewer, CarbonInterface $since, ?string $search, int $page): array
    {
        [$rows, $total, $page, $lastPage] = $this->paginate(
            $this->documentGroups($viewer, $since, $search), 'last_activity_at', 'document_id', $page,
        );

        return ['data' => $this->documentRows($rows), 'page' => $page, 'last_page' => $lastPage, 'total' => $total];
    }

    /** How many rows the whole by-document list has, for an export's row cap. */
    public function documentTotal(User $viewer, CarbonInterface $since, ?string $search): int
    {
        return DB::query()->fromSub($this->documentGroups($viewer, $since, $search), 'grouped')->count();
    }

    /**
     * The whole by-document list, every page of it in list order, for an
     * export. Read in chunks so a long period never sits in memory at once.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function eachDocument(User $viewer, CarbonInterface $since, ?string $search): LazyCollection
    {
        return $this->documentGroups($viewer, $since, $search)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('document_id')
            ->lazy(500)
            ->chunk(500)
            ->flatMap(fn (LazyCollection $chunk): array => $this->documentRows($chunk->all()));
    }

    /**
     * One document's whole trail, oldest first -- not only the part inside the
     * period, because "how did this document get here" is the question.
     *
     * @return array<int, array<string, mixed>>
     */
    public function documentTrail(Document $document): array
    {
        $movements = $document->movements()
            ->with(['actor:id,name,role', 'fromOffice:id,name', 'toOffice:id,name'])
            ->orderBy('sequence')
            ->get();

        return array_map(static fn (array $step, DocumentMovement $movement): array => [
            'id' => $step['id'],
            'action' => $step['action'],
            'action_label' => $step['action_label'],
            'actor' => $step['actor'],
            'actor_office' => $step['actor_office'],
            // Where the folder came from, on a step that moved it. Not the
            // same as actor_office, which leaves a Super Admin's out: this is
            // where the folder was, not whom the sender works for. The
            // exports print it as its own column.
            'from_office' => $movement->from_office_id !== null && $movement->from_office_id !== $movement->to_office_id
                ? $movement->fromOffice?->name
                : null,
            'office' => $step['to_office'],
            'at' => $step['arrived_at'],
        ], $this->presenter->timeline($movements), $movements->all());
    }

    /**
     * Staff who acted on a visible document in the period, most recently
     * active first.
     *
     * @return array{data: list<array<string, mixed>>, page: int, last_page: int, total: int}
     */
    public function users(User $viewer, CarbonInterface $since, ?string $search, int $page): array
    {
        [$rows, $total, $page, $lastPage] = $this->paginate(
            $this->userGroups($viewer, $since, $search), 'last_activity_at', 'user_id', $page,
        );

        return [
            'data' => array_values(array_map($this->userRow(...), $rows)),
            'page' => $page,
            'last_page' => $lastPage,
            'total' => $total,
        ];
    }

    /** How many rows the whole by-person list has, for an export's row cap. */
    public function userTotal(User $viewer, CarbonInterface $since, ?string $search): int
    {
        return DB::query()->fromSub($this->userGroups($viewer, $since, $search), 'grouped')->count();
    }

    /**
     * The whole by-person list in list order, for an export.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function eachUser(User $viewer, CarbonInterface $since, ?string $search): LazyCollection
    {
        return $this->userGroups($viewer, $since, $search)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('user_id')
            ->lazy(500)
            ->map($this->userRow(...));
    }

    /**
     * What one person did in the period, newest first, on documents the viewer
     * may see.
     *
     * @return array{steps: list<array<string, mixed>>, truncated: bool}
     */
    public function userTrail(User $viewer, User $actor, CarbonInterface $since): array
    {
        $movements = $this->userMovements($viewer, $actor, $since)
            ->limit(self::USER_TRAIL_LIMIT + 1)
            ->get();

        return [
            'steps' => array_values($movements->take(self::USER_TRAIL_LIMIT)->map($this->userStep(...))->all()),
            'truncated' => $movements->count() > self::USER_TRAIL_LIMIT,
        ];
    }

    /** How many steps one person's whole trail has, for an export's row cap. */
    public function userTrailTotal(User $viewer, User $actor, CarbonInterface $since): int
    {
        return $this->userMovements($viewer, $actor, $since)->count();
    }

    /**
     * One person's whole trail in the period -- past the hundred a click
     * loads, since a printed record that stops short is no record -- for an
     * export.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function eachUserStep(User $viewer, User $actor, CarbonInterface $since): LazyCollection
    {
        return $this->userMovements($viewer, $actor, $since)->lazy(500)->map($this->userStep(...));
    }

    /** Whether the viewer may see this document's trail at all. */
    public function canSee(User $viewer, Document $document): bool
    {
        return Document::query()->visibleTo($viewer)->whereKey($document->id)->exists();
    }

    /** One row of the by-document list per document, before ordering or paging. */
    private function documentGroups(User $viewer, CarbonInterface $since, ?string $search): Builder
    {
        return DocumentMovement::query()
            ->toBase()
            ->from('document_movements')
            ->whereIn('document_movements.document_id', $this->visibleDocuments($viewer, $search))
            ->where('document_movements.arrived_at', '>=', $since)
            ->groupBy('document_movements.document_id')
            ->selectRaw('document_movements.document_id, count(*) as actions, max(document_movements.arrived_at) as last_activity_at');
    }

    /**
     * Grouped rows filled in with each document's number, title, type and
     * status, in the order given.
     *
     * @param  array<int, stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function documentRows(array $rows): array
    {
        $documents = Document::query()
            ->with('documentType:id,name')
            ->whereKey(array_map(static fn ($row): int => (int) $row->document_id, $rows))
            ->get(['id', 'control_number', 'title', 'status', 'document_type_id'])
            ->keyBy('id');

        $data = [];

        foreach ($rows as $row) {
            $document = $documents->get((int) $row->document_id);

            if ($document === null) {
                continue;
            }

            $data[] = [
                'id' => $document->id,
                'control_number' => $document->control_number,
                'title' => $document->title,
                'type' => $document->documentType?->name,
                'status' => $document->status->publicLabel(),
                'actions' => (int) $row->actions,
                'last_activity_at' => $this->iso($row->last_activity_at),
            ];
        }

        return $data;
    }

    /** One row of the by-person list per member of staff, before ordering or paging. */
    private function userGroups(User $viewer, CarbonInterface $since, ?string $search): Builder
    {
        $term = $this->like($search);

        return DocumentMovement::query()
            ->toBase()
            ->from('document_movements')
            ->join('users', 'users.id', '=', 'document_movements.actor_id')
            ->leftJoin('offices', 'offices.id', '=', 'users.office_id')
            ->whereIn('document_movements.document_id', $this->visibleDocuments($viewer, null))
            ->where('document_movements.arrived_at', '>=', $since)
            ->when($term !== null, fn (Builder $query) => $query->where(function (Builder $query) use ($term): void {
                $query->whereRaw('lower(users.name) like ?', [$term])
                    ->orWhereRaw('lower(offices.name) like ?', [$term]);
            }))
            ->groupBy('users.id', 'users.name', 'offices.name')
            ->selectRaw('users.id as user_id, users.name as user_name, offices.name as office_name, count(*) as actions, max(document_movements.arrived_at) as last_activity_at');
    }

    /** @return array<string, mixed> */
    private function userRow(stdClass $row): array
    {
        return [
            'id' => (int) $row->user_id,
            'name' => (string) $row->user_name,
            'office' => $row->office_name === null ? null : (string) $row->office_name,
            'actions' => (int) $row->actions,
            'last_activity_at' => $this->iso($row->last_activity_at),
        ];
    }

    /**
     * What one person did on documents the viewer may see, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Builder<DocumentMovement>
     */
    private function userMovements(User $viewer, User $actor, CarbonInterface $since): \Illuminate\Database\Eloquent\Builder
    {
        return DocumentMovement::query()
            ->with(['document:id,control_number,title,document_type_id', 'document.documentType:id,name', 'toOffice:id,name'])
            ->where('actor_id', $actor->id)
            ->whereIn('document_id', $this->visibleDocuments($viewer, null))
            ->where('arrived_at', '>=', $since)
            ->orderByDesc('arrived_at')
            ->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    private function userStep(DocumentMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'action' => $movement->action->value,
            'action_label' => $movement->action->label(),
            'office' => $movement->toOffice?->name,
            'at' => $movement->arrived_at?->toIso8601String(),
            'document' => [
                'id' => $movement->document->id,
                'control_number' => $movement->document->control_number,
                'title' => $movement->document->title,
                'type' => $movement->document->documentType?->name,
            ],
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Document>
     */
    private function visibleDocuments(User $viewer, ?string $search): \Illuminate\Database\Eloquent\Builder
    {
        $term = $this->like($search);

        return Document::query()
            ->visibleTo($viewer)
            // Control number and title as the document list searches them,
            // and the TYPE too -- "Affidavit" is how the client names a
            // document in this card.
            ->when($term !== null, fn ($query) => $query->where(function ($query) use ($search, $term): void {
                $query->search($search)
                    ->orWhereHas('documentType', fn ($type) => $type->whereRaw('lower(name) like ?', [$term]));
            }))
            ->select('documents.id');
    }

    /**
     * Page a grouped query by hand: one COUNT over the groups, then one page.
     *
     * Rows come back as the query builder hands them over: plain stdClass
     * objects carrying the selected aliases.
     *
     * @return array{0: array<int, stdClass>, 1: int, 2: int, 3: int}
     */
    private function paginate(Builder $grouped, string $orderBy, string $tieBreak, int $page): array
    {
        $total = DB::query()->fromSub($grouped, 'grouped')->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        $rows = (clone $grouped)
            ->orderByDesc($orderBy)
            ->orderByDesc($tieBreak)
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get()
            ->all();

        return [$rows, $total, $page, $lastPage];
    }

    /** A lowercase LIKE pattern with the metacharacters escaped, or null for no search. */
    private function like(?string $search): ?string
    {
        $search = is_string($search) ? trim($search) : '';

        if ($search === '') {
            return null;
        }

        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($search)).'%';
    }

    /**
     * An aggregate's timestamp as ISO 8601. max() comes back as a plain
     * string from every driver, in the connection's own format.
     */
    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}
