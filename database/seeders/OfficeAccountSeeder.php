<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\SecurityEventType;
use App\Models\Office;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * One Admin and one User for every active office. Meant to run on the live
 * database, once, at rollout.
 *
 * THE PROBLEM IT SOLVES. §5's "send to" dropdown is
 * `Office::query()->active()->ordered()` -- every active office, whether or not
 * a single person works there. Forwarding is therefore always possible and
 * receiving is not: DocumentPolicy::view grants office-scoped read only to
 * Role::Admin with a matching office_id, and act() needs view() before it looks
 * at anything else. So a document sent to an office with no Admin account
 * arrives, sits as the open leg, counts as overdue on every report, and cannot
 * be opened by anybody except its submitter and a Super Admin. Nothing in the
 * application reports that state; the folder simply stops.
 *
 * Two accounts rather than one, because the two roles do different halves of
 * the job: the Admin RECEIVES (view, receive, forward, approve, return), and
 * the User FILES (submit, and track what they submitted, and nothing else).
 * An office with only an Admin cannot demonstrate the submitter's half of §5,
 * which is the half the client's staff will be trained on first.
 *
 * NOT wired into DatabaseSeeder. That one is reference data -- offices and
 * document types -- and is called by `db:seed` on every deployment. Minting
 * staff accounts is a rollout decision made once, with a credential sheet
 * somebody has to physically distribute, so it is invoked by name:
 *
 *     php artisan db:seed --class="Database\Seeders\OfficeAccountSeeder" --force
 *
 * Re-running it is safe and creates only what is missing: the addresses are
 * derived from the office CODE, so the second run finds every account it made
 * on the first and leaves the passwords alone. That is what makes it the right
 * tool when the client adds an office six months from now.
 *
 * EVERY ACCOUNT IT CREATES SHARES ONE PASSWORD, and config/cicto.php states
 * plainly what that costs: one string opens 52 office Admins, and the movement
 * ledger stops being able to answer *who* even though it still records *which
 * account*. It is a rollout convenience with an exit -- replace each shared
 * account with a named one via `cicto:user` as the office supplies a real
 * address -- and run() says so on the console every time it creates any.
 */
class OfficeAccountSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The pair, keyed by the address suffix.
     *
     * Titles match the accounts DatabaseSeeder already creates for local work
     * ("OCM Admin", "OCM Clerk") so the ledger reads the same in both places.
     * The name is the office CODE and not its full name on purpose: it appears
     * in every movement row and every notification, and "Office of the City
     * Environmental and Natural Resources Officer - Sanitation Services Admin"
     * is not a signature line.
     *
     * @var array<string, array{role: Role, title: string, position: string}>
     */
    private const SLOTS = [
        'admin' => ['role' => Role::Admin, 'title' => 'Admin', 'position' => 'Department Head'],
        'clerk' => ['role' => Role::User, 'title' => 'Clerk', 'position' => 'Administrative Aide'],
    ];

    public function run(): void
    {
        $this->refuseAStaleOfficeList();

        $offices = Office::query()->active()->ordered()->get();

        if ($offices->isEmpty()) {
            $this->command->line('  No active offices, so there is nobody to create for. Run OfficeSeeder first.');

            return;
        }

        $password = $this->sharedPassword();
        $planned = $this->plan($offices);

        $existing = User::query()
            ->whereIn('email', array_column($planned, 'email'))
            ->get()
            ->keyBy('email');

        $missing = array_values(array_filter(
            $planned,
            static fn (array $row): bool => ! $existing->has($row['email']),
        ));

        /*
         * All or nothing.
         *
         * A run that died halfway would leave accounts whose passwords were
         * generated, never printed and never recoverable -- each one then
         * needing `cicto:user --reset-password` by hand to become usable. The
         * transaction means the operator's fix is always the same one: run it
         * again.
         */
        $created = DB::transaction(fn (): array => $this->create($missing, $password));

        /*
         * §21 audit lines are written AFTER the commit, not inside it. On
         * PostgreSQL a failed statement poisons the surrounding transaction,
         * and SecurityEvent::log deliberately swallows its own failures -- so a
         * log write that failed in there would silently take all 104 accounts
         * down with it. Logging afterwards means the audit trail can only ever
         * describe accounts that really exist.
         */
        foreach ($created as $row) {
            SecurityEvent::log(
                type: SecurityEventType::UserCreated,
                summary: sprintf(
                    'OfficeAccountSeeder created the account %s as %s for %s',
                    $row['email'],
                    $row['role']->label(),
                    $row['code'],
                ),
                subjectLabel: $row['email'],
            );
        }

        $this->report($created, count($planned) - count($created), $offices->count(), $password);
    }

    /**
     * Refuse to run against the pre-2026-08-18 placeholder offices.
     *
     * Both the dev database and the deployed one were seeded from an invented
     * 11-office list before the client supplied their real one, and OfficeSeeder
     * retires those codes rather than deleting them. Minting two accounts per
     * office against that state would put 22 staff into offices that the very
     * next seeder run deactivates -- accounts belonging to departments that no
     * longer appear in any dropdown, which is worse than having no accounts at
     * all, because nothing in the UI would explain why they cannot receive
     * anything.
     */
    private function refuseAStaleOfficeList(): void
    {
        $stale = Office::query()
            ->active()
            ->whereIn('code', OfficeSeeder::RETIRED_PLACEHOLDER_CODES)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        if ($stale === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Nothing was created. This database still has the placeholder offices active (%s), '
            .'so it has not been re-seeded onto the client\'s real office list. Run '
            .'`php artisan db:seed --class="Database\Seeders\OfficeSeeder" --force` first, '
            .'then run this seeder.',
            implode(', ', $stale),
        ));
    }

    /**
     * Every account this database should end up with, whether it exists yet or
     * not.
     *
     * Built in full before a single row is written so a collision is a refusal
     * rather than a half-finished run: two office codes that reduce to the same
     * address would otherwise surface as a unique-constraint violation
     * somewhere in the middle of the loop.
     *
     * @param  Collection<int, Office>  $offices
     * @return list<array{code: string, office: string, office_id: int, email: string, name: string, role: Role, position: string}>
     */
    private function plan(Collection $offices): array
    {
        $domain = $this->domain();
        $rows = [];

        foreach ($offices as $office) {
            $slug = mb_strtolower((string) preg_replace('/[^A-Za-z0-9\-]/', '', $office->code));

            if ($slug === '') {
                throw new RuntimeException(
                    "Nothing was created. Office {$office->name} has a code that contains no "
                    .'letters or digits, so no address can be built from it.',
                );
            }

            foreach (self::SLOTS as $suffix => $slot) {
                $rows[] = [
                    'code' => $office->code,
                    'office' => $office->name,
                    'office_id' => $office->id,
                    'email' => "{$slug}.{$suffix}@{$domain}",
                    'name' => "{$office->code} {$slot['title']}",
                    'role' => $slot['role'],
                    'position' => $slot['position'],
                ];
            }
        }

        $collisions = array_keys(array_filter(
            array_count_values(array_column($rows, 'email')),
            static fn (int $count): bool => $count > 1,
        ));

        if ($collisions !== []) {
            throw new RuntimeException(sprintf(
                'Nothing was created. Two offices reduce to the same address (%s). '
                .'Give one of them a distinct code before seeding.',
                implode(', ', $collisions),
            ));
        }

        return $rows;
    }

    /**
     * @param  list<array{code: string, office: string, office_id: int, email: string, name: string, role: Role, position: string}>  $missing
     * @return list<array{code: string, office: string, office_id: int, email: string, name: string, role: Role, position: string, password: string}>
     */
    private function create(array $missing, string $password): array
    {
        $created = [];

        foreach ($missing as $row) {
            $user = new User;

            /*
             * forceFill for the same reason CreateUserAccount does it: `role`
             * and `office_id` are excluded from the model's fillable whitelist
             * by construction, and that exclusion is the thing standing between
             * a mass assignment and a privilege grant.
             *
             * email_verified_at is set because User implements MustVerifyEmail
             * and the `verified` middleware gates every protected route group.
             * These addresses are login identifiers, not mailboxes -- there is
             * no ocm.clerk@ inbox for a verification link to arrive in -- so an
             * unverified account here is an account nobody can ever sign in to.
             */
            $user->forceFill([
                'name' => $row['name'],
                'email' => $row['email'],
                'password' => $password,
                'role' => $row['role']->value,
                'office_id' => $row['office_id'],
                'position' => $row['position'],
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();

            $created[] = $row + ['password' => $password];
        }

        return $created;
    }

    /**
     * The mail domain the addresses are built on, with a leading `@` and any
     * stray whitespace trimmed off -- an operator setting
     * CICTO_OFFICE_ACCOUNT_DOMAIN="@baliwag.gov.ph" is copying the shape of an
     * address, not making a mistake worth failing a rollout over.
     */
    private function domain(): string
    {
        return trim((string) config('cicto.office_accounts.domain'), " \t\n\r\0\x0B@");
    }

    /**
     * The one password every account created by this seeder shares.
     *
     * Read once per run rather than per account, so a config change cannot land
     * mid-loop and split a rollout across two secrets -- which would be
     * invisible until half the offices reported that their slip did not work.
     *
     * See config/cicto.php for what sharing it costs and how to retire it. The
     * empty check is here because a blank CICTO_OFFICE_ACCOUNT_PASSWORD -- an
     * env line with nothing after the `=` -- would otherwise hash the empty
     * string into 104 accounts that anybody could open by pressing Enter.
     */
    private function sharedPassword(): string
    {
        $password = (string) config('cicto.office_accounts.password');

        if (trim($password) === '') {
            throw new RuntimeException(
                'Nothing was created. CICTO_OFFICE_ACCOUNT_PASSWORD is empty, and an account '
                .'with a blank password is one anybody can open.',
            );
        }

        return $password;
    }

    /**
     * Four lines and a warning, not a 104-line dump.
     *
     * The dump was worth its noise while every account carried a password that
     * existed nowhere else -- lose the scrollback and the only copy was gone.
     * A shared password is recoverable from config at any time, so printing all
     * of it now buys nothing and puts the whole rollout's credentials on a
     * screen somebody has to remember to clear. The sheet on disk carries the
     * office-by-office breakdown for whoever distributes it.
     *
     * @param  list<array{code: string, office: string, office_id: int, email: string, name: string, role: Role, position: string, password: string}>  $created
     */
    private function report(array $created, int $kept, int $offices, string $password): void
    {
        $orphaned = $this->activeOfficesWithNoAdmin();

        if ($created === []) {
            $this->command->line(sprintf(
                '  Every one of the %d active offices already has its pair. Nothing was created.',
                $offices,
            ));
        } else {
            $path = $this->writeCredentialSheet($created);

            $this->command->line(sprintf(
                '  Created %d accounts across %d active offices (%d already existed and were left alone).',
                count($created),
                $offices,
                $kept,
            ));
            $this->command->line(sprintf(
                '  Addresses are {office code}.admin@%s and {office code}.clerk@%s, lower-cased.',
                $this->domain(),
                $this->domain(),
            ));
            $this->command->line('  Every one of them signs in with the password: '.$password);
            $this->command->line('  Distribution sheet: '.$path);

            /*
             * Said at the point the risk is created rather than left to the
             * runbook, because this is the moment somebody is standing at a
             * console deciding whether the rollout is finished.
             */
            $this->command->warn(sprintf(
                '  ONE PASSWORD NOW OPENS %d ACCOUNTS, %d of them office Admins who can read, '
                .'forward, approve and reject their office\'s documents. Until it is retired, '
                .'the movement log records which ACCOUNT acted and cannot tell you which PERSON. '
                .'Replace each shared account with a named one as the office supplies a real '
                .'address: `php artisan cicto:user <email> --name="Their Name" --role=admin --office=<CODE>`, '
                .'then `--deactivate` the shared account.',
                count($created),
                $offices,
            ));
        }

        if ($orphaned !== []) {
            /*
             * The one failure this seeder cannot fix by itself: an address it
             * wanted was already taken by a real person's account, so the slot
             * was skipped -- and if that account is not an active Admin of that
             * office, the office is still one nobody can receive into.
             */
            $this->command->warn(sprintf(
                '  STILL UNRECEIVABLE -- these active offices have no active Admin, so a document '
                .'forwarded to them can be opened by nobody: %s. Fix each with '
                .'`php artisan cicto:user <email> --role=admin --office=<CODE>`.',
                implode(', ', $orphaned),
            ));
        }
    }

    /**
     * The whole point of the seeder, asked as a question rather than assumed.
     *
     * @return list<string>
     */
    private function activeOfficesWithNoAdmin(): array
    {
        return array_values(
            Office::query()
                ->active()
                ->whereDoesntHave('users', static function (Builder $query): void {
                    $query->where('users.role', Role::Admin->value)
                        ->where('users.is_active', true);
                })
                ->orderBy('code')
                ->get(['id', 'code'])
                ->map(static fn (Office $office): string => $office->code)
                ->all(),
        );
    }

    /**
     * @param  list<array{code: string, office: string, office_id: int, email: string, name: string, role: Role, position: string, password: string}>  $created
     */
    private function writeCredentialSheet(array $created): string
    {
        $path = 'office-accounts-'.now()->format('Y-m-d-His').'.csv';

        Storage::disk('local')->put($path, $this->csv($created));

        return 'storage/app/private/'.$path;
    }

    /**
     * @param  list<array{code: string, office: string, office_id: int, email: string, name: string, role: Role, position: string, password: string}>  $created
     */
    private function csv(array $created): string
    {
        // Quoted unconditionally: office names carry commas ("City Arts,
        // Culture, and Tourism Office") and apostrophes ("Prosecutor's Office").
        $lines = [$this->csvRow(['office_code', 'office_name', 'role', 'name', 'email', 'password'])];

        foreach ($created as $row) {
            $lines[] = $this->csvRow([
                $row['code'],
                $row['office'],
                $row['role']->value,
                $row['name'],
                $row['email'],
                $row['password'],
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /** @param  list<string>  $values */
    private function csvRow(array $values): string
    {
        return implode(',', array_map(
            static fn (string $value): string => '"'.str_replace('"', '""', $value).'"',
            $values,
        ));
    }
}
