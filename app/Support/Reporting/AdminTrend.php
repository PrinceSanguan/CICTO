<?php

namespace App\Support\Reporting;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Admin Panel's approved / pending / rejected trend.
 *
 * Extracted from AdminDashboardController because §4 shows this same chart on
 * two screens -- the panel dashboard and the panel's Reports page. Two copies
 * of the bucket mapping is exactly how "Pending" ends up meaning one thing on
 * the tile and another on the chart, which is the number a records officer
 * gets asked to defend.
 */
class AdminTrend
{
    /**
     * The client's four buckets, expressed in workflow states.
     *
     * `approved` and `completed` are one bucket: a completed document was
     * approved first, and splitting them would make the Approved figure read
     * lower than the number of documents the office actually approved.
     *
     * @var array<string, list<string>>
     */
    public const BUCKETS = [
        'pending' => ['initiated', 'under_review', 'returned'],
        'approved' => ['approved', 'completed'],
        'rejected' => ['rejected'],
    ];

    /** @var list<string> */
    public const PENDING_STATES = ['initiated', 'under_review', 'returned'];

    /** How many months of history the chart covers. */
    public const MONTHS = 12;

    /**
     * @return list<array<string, mixed>>
     */
    public function monthly(mixed $user): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths(self::MONTHS - 1);

        /*
         * Whole literal strings per driver.
         *
         * EXTRACT is identical on PostgreSQL and MySQL, but SQLite -- which the
         * tests run on -- has no EXTRACT at all and needs strftime.
         * Concatenating fragments is how a report ends up silently wrong on one
         * driver only, so each driver gets its own complete clause.
         */
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
            $bucket = self::bucketFor(DocumentStatus::from((string) $row->status));

            $buckets[$key][$bucket] = ($buckets[$key][$bucket] ?? 0) + (int) $row->total;
        }

        $series = [];

        // The month grid is built in PHP so a month with no documents is a zero
        // rather than a missing point -- a line chart that silently skips empty
        // months misreports the shape of the year.
        for ($i = 0; $i < self::MONTHS; $i++) {
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

    public static function bucketFor(DocumentStatus $status): string
    {
        foreach (self::BUCKETS as $name => $states) {
            if (in_array($status->value, $states, true)) {
                return $name;
            }
        }

        return 'pending';
    }
}
