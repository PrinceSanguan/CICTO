<?php

namespace App\Support\Reporting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Counts rows per calendar month, for any query, on any driver.
 *
 * The Super Admin analytics screen draws six of these lines from four
 * different tables, and writing the driver dance out six times is how one of
 * them ends up silently wrong on the one driver production runs.
 */
class MonthlySeries
{
    /** How many months a chart covers. Matches AdminTrend. */
    public const MONTHS = 12;

    /**
     * The timestamp columns a series can be grouped by.
     *
     * An allow-list rather than an interpolated column name, and not merely to
     * satisfy static analysis: a column name reaches selectRaw as SQL, so the
     * set of legal values has to be closed at compile time. Adding a chart
     * means adding a case here, which is the review this deserves.
     */
    public const COLUMNS = [
        'security_events.created_at',
        'document_files.created_at',
        'documents.created_at',
        'document_movements.created_at',
    ];

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int> Keyed 'YYYY-MM'.
     */
    public function monthly(Builder $query, string $column): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths(self::MONTHS - 1);

        /*
         * Whole literal strings, per column AND per driver.
         *
         * EXTRACT is identical on PostgreSQL and MySQL, but SQLite -- which the
         * tests run on -- has no EXTRACT at all and needs strftime. Building
         * these by concatenating fragments is how a report goes wrong on one
         * driver only, so every combination is spelled out.
         */
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        [$select, $group] = match ([$column, $sqlite]) {
            ['security_events.created_at', true] => [
                "cast(strftime('%Y', security_events.created_at) as integer) as y, cast(strftime('%m', security_events.created_at) as integer) as m, count(*) as total",
                "strftime('%Y', security_events.created_at), strftime('%m', security_events.created_at)",
            ],
            ['security_events.created_at', false] => [
                'extract(year from security_events.created_at) as y, extract(month from security_events.created_at) as m, count(*) as total',
                'extract(year from security_events.created_at), extract(month from security_events.created_at)',
            ],
            ['document_files.created_at', true] => [
                "cast(strftime('%Y', document_files.created_at) as integer) as y, cast(strftime('%m', document_files.created_at) as integer) as m, count(*) as total",
                "strftime('%Y', document_files.created_at), strftime('%m', document_files.created_at)",
            ],
            ['document_files.created_at', false] => [
                'extract(year from document_files.created_at) as y, extract(month from document_files.created_at) as m, count(*) as total',
                'extract(year from document_files.created_at), extract(month from document_files.created_at)',
            ],
            ['documents.created_at', true] => [
                "cast(strftime('%Y', documents.created_at) as integer) as y, cast(strftime('%m', documents.created_at) as integer) as m, count(*) as total",
                "strftime('%Y', documents.created_at), strftime('%m', documents.created_at)",
            ],
            ['documents.created_at', false] => [
                'extract(year from documents.created_at) as y, extract(month from documents.created_at) as m, count(*) as total',
                'extract(year from documents.created_at), extract(month from documents.created_at)',
            ],
            ['document_movements.created_at', true] => [
                "cast(strftime('%Y', document_movements.created_at) as integer) as y, cast(strftime('%m', document_movements.created_at) as integer) as m, count(*) as total",
                "strftime('%Y', document_movements.created_at), strftime('%m', document_movements.created_at)",
            ],
            ['document_movements.created_at', false] => [
                'extract(year from document_movements.created_at) as y, extract(month from document_movements.created_at) as m, count(*) as total',
                'extract(year from document_movements.created_at), extract(month from document_movements.created_at)',
            ],
            default => throw new \InvalidArgumentException(
                "monthly() cannot group by [{$column}]. Add it to MonthlySeries::COLUMNS and give it a case."
            ),
        };

        $rows = $query
            ->where($column, '>=', $start)
            ->selectRaw($select)
            ->groupByRaw($group)
            ->toBase()
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            // Aliased and cast explicitly: PostgreSQL returns numeric strings
            // for extract(), and an unaliased count(*) is named differently on
            // every driver.
            $counts[sprintf('%04d-%02d', (int) $row->y, (int) $row->m)] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Merge named series onto one 12-month grid.
     *
     * The grid is built in PHP so a month with no rows is a zero rather than a
     * missing point -- a line chart that silently skips empty months misreports
     * the shape of the year, and these charts exist to show shape.
     *
     * @param  array<string, array<string, int>>  $series
     * @return list<array<string, mixed>>
     */
    public function combine(array $series): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths(self::MONTHS - 1);
        $out = [];

        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');

            $point = ['month' => $key, 'label' => $month->format('M')];

            foreach ($series as $name => $counts) {
                $point[$name] = $counts[$key] ?? 0;
            }

            $out[] = $point;
        }

        return $out;
    }
}
