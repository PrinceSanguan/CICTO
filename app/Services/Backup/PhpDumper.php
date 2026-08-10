<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * The fallback for hosts with no proc_open and no client binaries -- which is
 * most LGU shared hosting, so this is the path that matters most.
 *
 * IMPORTANT: emits DATA ONLY, not schema. Restore is `php artisan migrate`
 * first, then load this file. backup_runs records last_migration because a dump
 * is only valid against the migration set it was taken from.
 *
 * Three things this has to get right, all of which were found by actually
 * restoring a dump rather than by reading it:
 *
 *  1. NO `SET session_replication_role` on PostgreSQL. It requires superuser,
 *     and the application role deliberately is not one, so the statement fails
 *     and every foreign key is then enforced during the load. Table order is
 *     used instead. (MySQL's FOREIGN_KEY_CHECKS=0 needs no privileges, so it
 *     is still used there.)
 *
 *  2. Values are emitted BY TYPE. PDO hands back PHP booleans for pgsql
 *     boolean columns, and (string) false is the empty string -- which reloads
 *     as `''` and fails with "invalid input syntax for type boolean".
 *
 *  3. Tables are written in FOREIGN KEY DEPENDENCY order, not alphabetically.
 *     document_files sorts before documents but depends on it. Cycles (offices
 *     references users, users references offices) are broken by inserting the
 *     cyclic column as NULL and re-applying it with UPDATEs at the end.
 */
class PhpDumper implements Dumper
{
    public function name(): string
    {
        return 'php';
    }

    public function includesSchema(): bool
    {
        return false;
    }

    public function isSupported(): bool
    {
        return true;
    }

    /** Ephemeral state. Restoring a stale session or queued job is harmful. */
    private const SKIP = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    /** @param resource $handle */
    private function write($handle, string $sql): void
    {
        $written = gzwrite($handle, $sql);

        // A short write means the disk filled or the quota was hit. Ignoring it
        // produces a truncated dump that is still recorded as Completed -- a
        // backup that looks fine and restores into a partial database.
        if ($written === false || $written < strlen($sql)) {
            throw new RuntimeException('Short write while building the dump; the disk may be full.');
        }
    }

    public function dump(string $target): int
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $pdo = $connection->getPdo();

        $handle = gzopen($target, 'wb6');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$target} for writing.");
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Unbuffered, or a large table is pulled entirely into memory and
            // defeats the point of cursoring.
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        $completed = false;

        try {
            [$ordered, $deferred] = $this->plan();

            $this->write($handle, $this->header($driver, $ordered));

            // Delete in reverse dependency order so children go before parents.
            foreach (array_reverse($ordered) as $table) {
                $this->write($handle, 'DELETE FROM '.$this->quoteIdentifier($table).";\n");
            }

            $deferredUpdates = [];

            foreach ($ordered as $table) {
                $this->dumpTable($handle, $table, $pdo, $deferred[$table] ?? [], $deferredUpdates);
            }

            // Re-apply the columns that were nulled to break a cycle.
            if ($deferredUpdates !== []) {
                $this->write($handle, "\n-- deferred foreign keys (cycle-breaking)\n");

                foreach ($deferredUpdates as $statement) {
                    $this->write($handle, $statement);
                }
            }

            $this->write($handle, $this->footer($driver, $ordered));
            $completed = true;
        } finally {
            // Restored even when the dump throws, or every later query on this
            // connection -- including the one that records the FAILURE -- runs
            // unbuffered.
            $closed = gzclose($handle);

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }

            // gzclose flushes the last compressed block, so it is the write
            // most likely to hit a full disk. Only raised when nothing else is
            // already in flight -- throwing from a finally would swallow the
            // real cause.
            if ($completed && $closed === false) {
                throw new RuntimeException('The dump file could not be flushed and closed; it may be truncated.');
            }
        }

        clearstatcache(true, $target);

        return (int) filesize($target);
    }

    /**
     * @param  resource  $handle
     * @param  array<int, string>  $deferredColumns
     * @param  array<int, string>  $deferredUpdates
     */
    private function dumpTable($handle, string $table, PDO $pdo, array $deferredColumns, array &$deferredUpdates): void
    {
        $quoted = $this->quoteIdentifier($table);
        $this->write($handle, "\n-- {$table}\n");

        $columns = null;
        $key = $this->primaryKey($table);

        foreach (DB::table($table)->cursor() as $row) {
            $data = (array) $row;

            $columns ??= implode(', ', array_map(
                fn (string $c) => $this->quoteIdentifier($c),
                array_keys($data),
            ));

            $insert = $data;

            foreach ($deferredColumns as $column) {
                if (($insert[$column] ?? null) !== null) {
                    // Re-apply once both sides of the cycle exist.
                    if ($key !== null && isset($data[$key])) {
                        $deferredUpdates[] = sprintf(
                            "UPDATE %s SET %s = %s WHERE %s = %s;\n",
                            $quoted,
                            $this->quoteIdentifier($column),
                            $this->literal($data[$column], $pdo),
                            $this->quoteIdentifier($key),
                            $this->literal($data[$key], $pdo),
                        );
                    }

                    $insert[$column] = null;
                }
            }

            $values = implode(', ', array_map(
                fn ($value) => $this->literal($value, $pdo),
                array_values($insert),
            ));

            $this->write($handle, "INSERT INTO {$quoted} ({$columns}) VALUES ({$values});\n");
        }
    }

    /**
     * A SQL literal for a PHP value, by type.
     *
     * Casting everything to string is what produced `''` for false and broke
     * every boolean column on reload.
     */
    private function literal(mixed $value, PDO $pdo): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            default => $pdo->quote((string) $value),
        };
    }

    /**
     * Order tables so a row is never inserted before the row it references,
     * and report which columns had to be deferred to break a cycle.
     *
     * @return array{0: array<int, string>, 1: array<string, array<int, string>>}
     */
    private function plan(): array
    {
        $schema = DB::connection()->getSchemaBuilder();

        // getTables() returns every schema the CONNECTION can see, not just
        // the current database. On a shared MySQL server that means other
        // applications' tables -- which would then fail to dump, or worse, end
        // up inside this application's backup file.
        //
        // What counts as "ours" is driver-specific: MySQL reports the database
        // name, PostgreSQL reports `public`, SQLite reports `main`.
        $ours = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => [DB::connection()->getDatabaseName()],
            'pgsql' => ['public'],
            default => ['main', DB::connection()->getDatabaseName()],
        };

        $tables = collect($schema->getTables())
            ->filter(function (array $table) use ($ours): bool {
                $owner = (string) ($table['schema'] ?? '');

                // No schema reported at all: nothing to disambiguate, keep it.
                return $owner === '' || in_array($owner, $ours, true);
            })
            ->pluck('name')
            ->map(fn ($n) => (string) $n)
            ->reject(fn (string $n) => in_array($n, self::SKIP, true))
            ->values()
            ->all();

        sort($tables);

        /** @var array<string, array<string, string>> $edges  table => [column => referencedTable] */
        $edges = [];

        foreach ($tables as $table) {
            $edges[$table] = [];

            foreach ($schema->getForeignKeys($table) as $fk) {
                $referenced = (string) $fk['foreign_table'];
                $column = (string) ($fk['columns'][0] ?? '');

                if ($referenced === '' || $column === '' || ! in_array($referenced, $tables, true)) {
                    continue;
                }

                $edges[$table][$column] = $referenced;
            }
        }

        $ordered = [];
        $deferred = [];
        $state = []; // 0 unvisited, 1 visiting, 2 done

        $visit = function (string $table, array $stack) use (&$visit, &$edges, &$ordered, &$deferred, &$state): void {
            if (($state[$table] ?? 0) === 2) {
                return;
            }

            $state[$table] = 1;

            foreach ($edges[$table] as $column => $referenced) {
                // A self-reference (offices.parent_id) or a back-edge into a
                // table already being visited closes a cycle. Null the column
                // on insert and fix it up afterwards.
                if ($referenced === $table || ($state[$referenced] ?? 0) === 1) {
                    $deferred[$table][] = $column;

                    continue;
                }

                $visit($referenced, [...$stack, $table]);
            }

            $state[$table] = 2;
            $ordered[] = $table;
        };

        foreach ($tables as $table) {
            $visit($table, []);
        }

        return [$ordered, $deferred];
    }

    private function primaryKey(string $table): ?string
    {
        foreach (DB::connection()->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['primary'] === true) {
                $columns = $index['columns'];

                return count($columns) === 1 ? (string) $columns[0] : null;
            }
        }

        return null;
    }

    /** @param array<int, string> $ordered */
    private function header(string $driver, array $ordered): string
    {
        // MySQL lets an ordinary user turn FK checks off; PostgreSQL does not
        // (session_replication_role is superuser-only), which is exactly why
        // the table ordering above exists.
        $preamble = match ($driver) {
            'mysql', 'mariadb' => "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n",
            default => "-- Tables are ordered by foreign key dependency; no privileged\n"
                ."-- session settings are required to load this file.\n",
        };

        return "-- CICTO data-only dump\n"
            ."-- Driver: {$driver}\n"
            .'-- Generated: '.now()->toIso8601String()."\n"
            .'-- Tables: '.implode(', ', $ordered)."\n"
            ."--\n"
            ."-- RESTORE: run `php artisan migrate` FIRST, then load this file.\n"
            ."--          This dump contains no schema.\n\n"
            .$preamble."\n";
    }

    /** @param array<int, string> $ordered */
    private function footer(string $driver, array $ordered): string
    {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            // InnoDB advances its AUTO_INCREMENT counter when an explicit id is
            // inserted, so nothing to fix up. Same for SQLite's sqlite_sequence.
            return "\nSET FOREIGN_KEY_CHECKS=1;\n";
        }

        if ($driver !== 'pgsql') {
            return "\n-- end\n";
        }

        // PostgreSQL does NOT advance a sequence when a row is inserted with an
        // explicit id. Restore a dump and every sequence is still at 1, so the
        // first new document collides with id 1 and keeps colliding, one row at
        // a time, until it walks past the highest restored id. The restore
        // looks perfect and the application is broken.
        //
        // pg_get_serial_sequence RAISES on a table that has no such column
        // (password_reset_tokens is keyed by email) rather than returning NULL,
        // so the set of tables is filtered here instead of in SQL. It does
        // return NULL for a non-serial id, which the WHERE still handles.
        $sql = "\n-- Advance identity sequences past the restored rows.\n";

        $schema = DB::connection()->getSchemaBuilder();

        foreach ($ordered as $table) {
            if (! $schema->hasColumn($table, 'id')) {
                continue;
            }

            $literal = str_replace("'", "''", $table);
            $quoted = $this->quoteIdentifier($table);

            // is_called is the MAX IS NOT NULL flag rather than a constant
            // true: on an empty table that makes the next value 1 instead of
            // burning it.
            $sql .= sprintf(
                "SELECT setval(pg_get_serial_sequence('%s', 'id'), "
                .'COALESCE((SELECT MAX(id) FROM %s), 1), '
                .'(SELECT MAX(id) FROM %s) IS NOT NULL) '
                ."WHERE pg_get_serial_sequence('%s', 'id') IS NOT NULL;\n",
                $literal,
                $quoted,
                $quoted,
                $literal,
            );
        }

        return $sql."\n-- end\n";
    }

    private function quoteIdentifier(string $name): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => '`'.str_replace('`', '``', $name).'`',
            default => '"'.str_replace('"', '""', $name).'"',
        };
    }
}
