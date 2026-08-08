# Database portability

CICTO must run on **PostgreSQL** and **MySQL/MariaDB**. LGU hosting varies, and
the choice is often not ours to make, so the schema and every query stay
driver-agnostic.

PostgreSQL is the default in `.env.example`. Both connections are already
defined in `config/database.php` (along with `sqlite`, `mariadb`, `sqlsrv`) —
switching is only ever an `.env` change.

## Switching

Uncomment one block in `.env`:

```dotenv
# PostgreSQL
DB_CONNECTION=pgsql
DB_PORT=5432

# MySQL / MariaDB
DB_CONNECTION=mysql
DB_PORT=3306
```

Then `php artisan migrate:fresh`.

## Rules for new migrations

The remaining features in the project scope add a lot of schema. These are the
things that silently work on one driver and break on the other.

**Avoid `->after('column')`.** MySQL/MariaDB only. PostgreSQL and SQLite ignore
it without error, so column order differs between environments. Harmless today
(`add_two_factor_columns_to_users_table` uses it, inherited from the starter
kit) but do not add more.

**No `enum` columns.** PostgreSQL implements these as real user-defined types,
which makes later changes painful and `doctrine/dbal`-dependent. Use a
`string` column plus validation, or a lookup table. This matters for document
status (`Pending`, `In Process`, `Rejected`, `Completed`) and priority.

**No raw MySQL functions.** `DATE_FORMAT()`, `IFNULL()`, `GROUP_CONCAT()`,
`NOW()` have different names or no equivalent in PostgreSQL. Reach for query
builder methods; where raw SQL is unavoidable, branch on
`DB::connection()->getDriverName()`.

**Be careful with `json()` columns.** The column type is portable, but querying
is not — `whereJsonContains` compiles differently per driver, and PostgreSQL
distinguishes `json` from `jsonb`. Fine for write-and-read-whole-value (as
`passkeys.credential` does); think twice before querying inside one.

**No `fulltext` indexes.** MySQL-specific. PostgreSQL uses `tsvector` + GIN.
If document search needs more than `LIKE`, implement it per driver behind one
method.

**Index length.** `string()` defaults to `varchar(255)`. Unique indexes on
those are fine on MySQL 8 / MariaDB 10.4+ and PostgreSQL, but not on MySQL 5.7
with `utf8mb4`. Pass an explicit length for anything indexed and long.

## The one that will actually bite: case-insensitive search

**MySQL `LIKE` is case-insensitive** (collation-driven).
**PostgreSQL `LIKE` is case-sensitive.**

So this finds nothing on PostgreSQL when the user types lowercase:

```php
// WRONG — behaves differently per driver
Document::where('control_number', 'like', "%{$term}%")->get();
```

Lower-case both sides instead. Portable, and works everywhere:

```php
Document::whereRaw('lower(control_number) like ?', ['%'.strtolower($term).'%'])
    ->get();
```

This applies to the Search and Filter feature (scope §8) and anywhere staff
type a value — titles, remarks, office names.

Related: `ORDER BY` puts `NULL` last by default on MySQL and first on
PostgreSQL. If null ordering matters, say so explicitly.

## Before merging schema changes

Run both. It takes a minute and it is the only thing that actually proves it:

```bash
DB_CONNECTION=pgsql  php artisan migrate:fresh && DB_CONNECTION=pgsql  php artisan test
DB_CONNECTION=mysql  php artisan migrate:fresh && DB_CONNECTION=mysql  php artisan test
```

Environment variables override the `<env>` entries in `phpunit.xml` (PHPUnit
does not force them), so the second command really does exercise MySQL.

CI runs the same matrix on every push — see `.github/workflows/tests.yml`.
