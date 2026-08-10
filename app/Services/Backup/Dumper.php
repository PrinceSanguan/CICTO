<?php

namespace App\Services\Backup;

interface Dumper
{
    /**
     * Write a restorable dump of the current database to $target.
     *
     * @return int bytes written
     */
    public function dump(string $target): int;

    /** Whether this dumper can actually run on this host, right now. */
    public function isSupported(): bool;

    /** 'shell' | 'php' -- recorded on the backup run. */
    public function name(): string;

    /**
     * True when the output contains schema as well as data.
     *
     * A data-only dump is restorable only after `artisan migrate` against the
     * matching migration set, which is why backup_runs records last_migration.
     */
    public function includesSchema(): bool;
}
