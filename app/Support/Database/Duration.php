<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The ONLY place in the codebase that branches on database driver for date
 * maths -- the escape hatch docs/DATABASE.md explicitly allows.
 *
 * Column names passed here are hard-coded literals from application code, never
 * user input, so there is no injection surface.
 */
final class Duration
{
    /**
     * A SQL expression yielding whole minutes between two timestamp columns.
     *
     * Minute arithmetic has no portable standard-SQL form, so this branches.
     * Date bucketing does have one, so yearMonth() below does not.
     */
    public static function minutesBetween(string $from, string $to): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "(extract(epoch from ({$to} - {$from})) / 60)",
            'mysql', 'mariadb' => "timestampdiff(minute, {$from}, {$to})",
            'sqlite' => "((julianday({$to}) - julianday({$from})) * 1440)",
            default => throw new RuntimeException('Unsupported driver for duration maths.'),
        };
    }

    /**
     * Year and month parts for monthly reporting.
     *
     * EXTRACT is standard SQL and behaves identically on PostgreSQL, MySQL and
     * MariaDB, so this deliberately does not branch. Do not "fix" it into
     * to_char()/date_format().
     *
     * SQLite has no EXTRACT, so it gets strftime -- tests run there.
     *
     * Cast the results to (int) in PHP: EXTRACT returns numeric on PostgreSQL
     * and integer on MySQL, and PDO hands both back as strings.
     *
     * @return array{0: string, 1: string} [yearExpr, monthExpr]
     */
    public static function yearMonth(string $column): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return [
                "cast(strftime('%Y', {$column}) as integer)",
                "cast(strftime('%m', {$column}) as integer)",
            ];
        }

        return ["extract(year from {$column})", "extract(month from {$column})"];
    }
}
