<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * The fast path: pg_dump / mysqldump.
 *
 * Produces schema AND data, so a restore is a single load with no migration
 * step. Preferred whenever the host allows it.
 *
 * It frequently does not. LGU shared hosting disables proc_open and exec, and
 * ships no database client binaries at all -- which is the entire reason
 * PhpDumper exists.
 */
class ShellDumper implements Dumper
{
    public function name(): string
    {
        return 'shell';
    }

    public function includesSchema(): bool
    {
        return true;
    }

    public function isSupported(): bool
    {
        // There is no pg_dump/mysqldump equivalent worth shelling out to for a
        // single-file database, and the connection config has no host/port/user
        // to build a command from. PhpDumper handles it.
        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
            return false;
        }

        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (in_array('proc_open', $disabled, true)) {
            return false;
        }

        return $this->binary() !== null;
    }

    public function dump(string $target): int
    {
        $binary = $this->binary();

        if ($binary === null) {
            throw new RuntimeException('No database dump binary is available on this host.');
        }

        $config = DB::connection()->getConfig();
        $driver = DB::connection()->getDriverName();

        // The password goes through the ENVIRONMENT for both drivers. An
        // earlier version only did this for PostgreSQL and passed --password=
        // to mysqldump in argv, where any other account on a shared host can
        // read it straight out of `ps`.
        $env = $driver === 'pgsql'
            ? ['PGPASSWORD' => (string) $config['password']]
            : ['MYSQL_PWD' => (string) $config['password']];

        $process = new Process(
            $this->command($driver, $binary, $config, $target),
            base_path(),
            $env,
            null,
            600,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        clearstatcache(true, $target);

        return (int) filesize($target);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function command(string $driver, string $binary, array $config, string $target): array
    {
        if ($driver === 'pgsql') {
            return [
                $binary,
                '--host='.$config['host'],
                '--port='.$config['port'],
                '--username='.$config['username'],
                '--no-owner',
                '--no-privileges',
                '--clean',
                '--if-exists',
                '--file='.$target,
                (string) $config['database'],
            ];
        }

        return [
            $binary,
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            // No --password here: it is supplied via MYSQL_PWD above.
            '--single-transaction',
            '--skip-lock-tables',
            '--result-file='.$target,
            (string) $config['database'],
        ];
    }

    private function binary(): ?string
    {
        $name = DB::connection()->getDriverName() === 'pgsql' ? 'pg_dump' : 'mysqldump';

        // Postgres.app and Homebrew both keep their binaries off the default
        // web-server PATH, so `which` alone is not enough.
        $candidates = [
            $name,
            "/opt/homebrew/bin/{$name}",
            "/usr/local/bin/{$name}",
            "/usr/bin/{$name}",
            "/Applications/Postgres.app/Contents/Versions/latest/bin/{$name}",
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === $name) {
                $found = trim((string) @shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

                if ($found !== '' && is_executable($found)) {
                    return $found;
                }

                continue;
            }

            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
