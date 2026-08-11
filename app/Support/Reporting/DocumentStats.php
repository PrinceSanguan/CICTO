<?php

namespace App\Support\Reporting;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Support\Deadlines;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * §19's four report artifacts, plus the §18 dashboard counters.
 *
 * Three rules hold throughout:
 *
 *  1. Every query starts from visibleTo($user). A report is the easiest place
 *     to leak another office's data, because nobody notices a total that is
 *     slightly too big.
 *  2. Month bucketing uses EXTRACT, which is standard SQL and identical on
 *     PostgreSQL and MySQL. No DATE_FORMAT, no to_char, no driver branching.
 *  3. Every aggregate is cast in PHP. PDO returns SUM/COUNT/AVG as strings, and
 *     EXTRACT as numeric on PostgreSQL but integer on MySQL -- comparing them
 *     untyped is how a report silently sorts wrong on one driver only.
 *
 * Archived documents are INCLUDED. Filing a finished document away must not
 * erase the work from the statistics; that is the opposite of what §20 is for.
 */
class DocumentStats
{
    /**
     * The five report buckets, keyed by workflow status.
     *
     * Declared once so the chart, its legend and any future export cannot
     * disagree about what "For Approval" counts.
     */
    private const REPORT_BUCKETS = [
        'initiated' => 'pending',
        'returned' => 'pending',
        'under_review' => 'in_process',
        'approved' => 'for_approval',
        'completed' => 'completed',
        'rejected' => 'rejected',
    ];

    /**
     * §18: total, monthly processed, delayed, approval rate.
     *
     * Four numbers, one query. Conditional aggregates via CASE WHEN are
     * portable and avoid four table scans.
     *
     * The alias is `delayed_count`, not `delayed`: DELAYED is a reserved word
     * in MySQL and a bare alias of that name is a syntax error there while
     * working fine on PostgreSQL.
     *
     * @return array{total: int, processed: int, delayed: int, approval_rate: float|null}
     */
    public function summary(User $user): array
    {
        $now = Deadlines::now();
        $start = $now->copy()->startOfMonth();
        $end = $start->copy()->addMonth();

        $row = Document::query()
            ->visibleTo($user)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when completed_at >= ? and completed_at < ? then 1 else 0 end) as processed', [$start, $end])
            ->selectRaw('sum(case when due_at < ? and completed_at is null and status <> ? then 1 else 0 end) as delayed_count', [$now, DocumentStatus::Rejected->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as completed', [DocumentStatus::Completed->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as rejected', [DocumentStatus::Rejected->value])
            ->first();

        $completed = (int) ($row->completed ?? 0);
        $rejected = (int) ($row->rejected ?? 0);
        $decided = $completed + $rejected;

        return [
            'total' => (int) ($row->total ?? 0),
            'processed' => (int) ($row->processed ?? 0),
            'delayed' => (int) ($row->delayed_count ?? 0),
            // Null, not zero, when nothing has been decided. "0%" reads as
            // "everything was rejected", which is a very different statement.
            'approval_rate' => $decided > 0 ? round($completed / $decided * 100, 1) : null,
        ];
    }

    /**
     * §19 artifact 1: documents processed per month.
     *
     * @return array<int, array{month: string, label: string, count: int}>
     */
    public function monthlyProcessed(User $user, int $months): array
    {
        $start = Deadlines::now()->startOfMonth()->subMonths($months - 1);

        // Written as whole literal strings per driver so static analysis can
        // see there is no interpolation here. EXTRACT is standard SQL and
        // identical on PostgreSQL and MySQL; SQLite (tests) needs strftime.
        $select = $this->sqlite()
            ? "cast(strftime('%Y', completed_at) as integer) as y, cast(strftime('%m', completed_at) as integer) as m, count(*) as c"
            : 'extract(year from completed_at) as y, extract(month from completed_at) as m, count(*) as c';

        $group = $this->sqlite()
            ? "strftime('%Y', completed_at), strftime('%m', completed_at)"
            : 'extract(year from completed_at), extract(month from completed_at)';

        $rows = Document::query()
            ->visibleTo($user)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $start)
            // toBase(): these are aggregate rows, not documents. Hydrating them
            // into Eloquent models would invent properties that do not exist.
            ->toBase()
            ->selectRaw($select)
            ->groupByRaw($group)
            ->get()
            ->keyBy(fn (object $r) => sprintf('%04d-%02d', (int) $r->y, (int) $r->m));

        $out = [];

        for ($i = 0; $i < $months; $i++) {
            $date = $start->copy()->addMonths($i);
            $bucket = $date->format('Y-m');
            $row = $rows->get($bucket);

            $out[] = [
                'month' => $bucket,
                'label' => $date->format('M Y'),
                'count' => $row === null ? 0 : (int) $row->c,
            ];
        }

        return $out;
    }

    /**
     * Monthly volume split by status, for the client's "Monthly Documents
     * Processed" chart.
     *
     * FIVE buckets, not the four §8 names. The design's legend separates "In
     * Process" from "For Approval", which is a genuinely useful distinction
     * here: it is the difference between work an office is still doing and work
     * waiting on a signature. DocumentStatus::publicLabel() merges them because
     * a citizen tracking a folder does not need that detail; a records officer
     * reading a report does.
     *
     * Bucketed by created_at rather than completed_at, so a month's bar shows
     * everything that ARRIVED in it. monthlyProcessed() answers the other
     * question -- what was finished -- and both are on the page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthlyByStatus(User $user, int $months): array
    {
        $start = Deadlines::now()->startOfMonth()->subMonths($months - 1);

        // Whole literal strings per driver, as above: EXTRACT is identical on
        // PostgreSQL and MySQL, SQLite has none.
        $select = $this->sqlite()
            ? "cast(strftime('%Y', created_at) as integer) as y, cast(strftime('%m', created_at) as integer) as m, status, count(*) as c"
            : 'extract(year from created_at) as y, extract(month from created_at) as m, status, count(*) as c';

        $group = $this->sqlite()
            ? "strftime('%Y', created_at), strftime('%m', created_at), status"
            : 'extract(year from created_at), extract(month from created_at), status';

        $rows = Document::query()
            ->visibleTo($user)
            ->where('created_at', '>=', $start)
            ->toBase()
            ->selectRaw($select)
            ->groupByRaw($group)
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', (int) $row->y, (int) $row->m);
            $bucket = self::REPORT_BUCKETS[(string) $row->status] ?? 'pending';

            $buckets[$key][$bucket] = ($buckets[$key][$bucket] ?? 0) + (int) $row->c;
        }

        $out = [];

        for ($i = 0; $i < $months; $i++) {
            $date = $start->copy()->addMonths($i);
            $key = $date->format('Y-m');

            $out[] = [
                'month' => $key,
                'label' => $date->format('M'),
                'pending' => $buckets[$key]['pending'] ?? 0,
                'in_process' => $buckets[$key]['in_process'] ?? 0,
                'for_approval' => $buckets[$key]['for_approval'] ?? 0,
                'completed' => $buckets[$key]['completed'] ?? 0,
                'rejected' => $buckets[$key]['rejected'] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * §19 artifact 2: status distribution.
     *
     * Grouped by the four client-facing §8 names rather than the six internal
     * statuses, because those are the words on the acceptance criteria.
     *
     * @return array<int, array{status: string, count: int}>
     */
    public function statusDistribution(User $user): array
    {
        $counts = Document::query()
            ->visibleTo($user)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $grouped = [];

        foreach (DocumentStatus::cases() as $case) {
            $label = $case->publicLabel();
            $grouped[$label] = ($grouped[$label] ?? 0) + (int) ($counts[$case->value] ?? 0);
        }

        return array_map(
            static fn (string $status, int $count) => ['status' => $status, 'count' => $count],
            array_keys($grouped),
            array_values($grouped),
        );
    }

    /**
     * §19 artifact 3: processing trend -- average days from registration to
     * completion, by month.
     *
     * Computed from the two timestamps through the one driver-branching helper
     * rather than a stored processing_days column, so it stays correct if a
     * completion date is ever corrected.
     *
     * @return array<int, array{month: string, label: string, days: float|null}>
     */
    public function processingTrend(User $user, int $months): array
    {
        $start = Deadlines::now()->startOfMonth()->subMonths($months - 1);

        // One whole literal per driver. Concatenating the duration expression
        // in would make this a computed string, and raw SQL that is assembled
        // at runtime is exactly what should not be reaching selectRaw.
        $select = match (DB::connection()->getDriverName()) {
            'sqlite' => "cast(strftime('%Y', completed_at) as integer) as y, cast(strftime('%m', completed_at) as integer) as m, avg((julianday(completed_at) - julianday(created_at)) * 1440) as avg_minutes",
            'pgsql' => 'extract(year from completed_at) as y, extract(month from completed_at) as m, avg(extract(epoch from (completed_at - created_at)) / 60) as avg_minutes',
            default => 'extract(year from completed_at) as y, extract(month from completed_at) as m, avg(timestampdiff(minute, created_at, completed_at)) as avg_minutes',
        };

        $group = $this->sqlite()
            ? "strftime('%Y', completed_at), strftime('%m', completed_at)"
            : 'extract(year from completed_at), extract(month from completed_at)';

        $rows = Document::query()
            ->visibleTo($user)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $start)
            ->toBase()
            ->selectRaw($select)
            ->groupByRaw($group)
            ->get()
            ->keyBy(fn (object $r) => sprintf('%04d-%02d', (int) $r->y, (int) $r->m));

        $out = [];

        for ($i = 0; $i < $months; $i++) {
            $date = $start->copy()->addMonths($i);
            $bucket = $date->format('Y-m');
            $row = $rows->get($bucket);

            // avg() returns numeric on PostgreSQL and decimal on MySQL, both as
            // PHP strings. Cast before dividing or the maths is string maths.
            $out[] = [
                'month' => $bucket,
                'label' => $date->format('M Y'),
                'days' => $row === null || $row->avg_minutes === null
                    ? null
                    : round(((float) $row->avg_minutes) / 1440, 1),
            ];
        }

        return $out;
    }

    /**
     * §19 artifact 4: user activity.
     *
     * The one everybody forgets -- it belongs to no other feature, so it only
     * surfaces during acceptance of a PHP 900 line item. Derived from
     * document_movements.actor_id; no extra table.
     *
     * @return array<int, array{user: string, office: string|null, actions: int, approvals: int}>
     */
    public function userActivity(User $user, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $visible = Document::query()->visibleTo($user)->select('documents.id');

        return DocumentMovement::query()
            ->toBase()
            ->from('document_movements')
            ->join('users', 'users.id', '=', 'document_movements.actor_id')
            ->leftJoin('offices', 'offices.id', '=', 'users.office_id')
            ->whereIn('document_movements.document_id', $visible)
            ->when($from, fn ($q) => $q->where('document_movements.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('document_movements.created_at', '<=', $to))
            ->groupBy('users.id', 'users.name', 'offices.name')
            ->selectRaw('users.name as user_name, offices.name as office_name, count(*) as actions')
            ->selectRaw('sum(case when document_movements.action = ? then 1 else 0 end) as approvals', ['approved'])
            ->orderByDesc('actions')
            ->limit(100)
            ->get()
            ->map(fn ($r) => [
                'user' => (string) $r->user_name,
                'office' => $r->office_name === null ? null : (string) $r->office_name,
                'actions' => (int) $r->actions,
                'approvals' => (int) $r->approvals,
            ])
            ->all();
    }

    /**
     * Average dwell per office, in whole minutes.
     *
     * @return array<int, array{office: string, legs: int, average_minutes: int}>
     */
    public function officePerformance(User $user): array
    {
        $visible = Document::query()->visibleTo($user)->select('documents.id');

        $select = match (DB::connection()->getDriverName()) {
            'sqlite' => 'offices.name as office_name, count(*) as legs, avg((julianday(document_movements.departed_at) - julianday(document_movements.arrived_at)) * 1440) as avg_minutes',
            'pgsql' => 'offices.name as office_name, count(*) as legs, avg(extract(epoch from (document_movements.departed_at - document_movements.arrived_at)) / 60) as avg_minutes',
            default => 'offices.name as office_name, count(*) as legs, avg(timestampdiff(minute, document_movements.arrived_at, document_movements.departed_at)) as avg_minutes',
        };

        return DocumentMovement::query()
            ->toBase()
            ->from('document_movements')
            ->join('offices', 'offices.id', '=', 'document_movements.to_office_id')
            ->whereIn('document_movements.document_id', $visible)
            ->whereNotNull('document_movements.departed_at')
            ->groupBy('offices.id', 'offices.name')
            ->selectRaw($select)
            // Ordering by the select alias, not by repeating the expression:
            // both drivers accept it here and it keeps the raw SQL to one place.
            ->orderByDesc('avg_minutes')
            ->get()
            ->map(fn ($r) => [
                'office' => (string) $r->office_name,
                'legs' => (int) $r->legs,
                'average_minutes' => (int) round((float) $r->avg_minutes),
            ])
            ->all();
    }

    private function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
