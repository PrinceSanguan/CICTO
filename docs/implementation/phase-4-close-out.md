# Phase 4 — Close-Out: Read Views, Archive, Help

> **Search, dashboard, archive, help, handover.**
> PHP 1,400 · features #4, #14, #16 · also delivers unpriced §23

Prerequisite reading: [`00-architecture.md`](00-architecture.md).

## Goal

Everything here is a **read view over data that already exists.** Phase 4 adds
**zero database schema** — which is precisely why it is the compression lever if
the calendar slips. Cutting or deferring it costs no migration and breaks no
foreign key.

That property is deliberate and worth protecting: do not let a Phase 4 feature
smuggle a column into a Phase 1 migration late.

## Demo at the end of this phase

Emarie searches `mpdo-2026` in lowercase and finds the document whose control
number is uppercase. She filters to *In Process*, narrows to one office, and the
URL updates so she can bookmark it and send the link to a colleague. Her dashboard
shows total documents, this month's processed count, how many are delayed, and an
approval rate. She archives a completed document, watches it leave the active list,
finds it again under Archive, and restores it. She opens Help and reads the
knowledge base.

---

## 1. Search and Filter (#4, PHP 500)

§8: search by control number, filter by status.

### The request

`IndexDocumentRequest` validates everything, and **`sort`/`dir` validation is
non-negotiable** — they are interpolated into `orderBy`.

```php
public function rules(): array
{
    return [
        'q'         => ['nullable', 'string', 'max:100'],
        'status'    => ['nullable', 'string', Rule::enum(DocumentStatus::class)],
        'office_id' => ['nullable', 'integer', 'exists:offices,id'],
        'priority'  => ['nullable', 'string', Rule::enum(DocumentPriority::class)],
        'from'      => ['nullable', 'date'],
        'to'        => ['nullable', 'date', 'after_or_equal:from'],
        'sort'      => ['nullable', Rule::in(['created_at', 'control_number', 'status'])],
        'dir'       => ['nullable', Rule::in(['asc', 'desc'])],
        'per_page'  => ['nullable', 'integer', Rule::in([10, 25, 50])],
    ];
}
```

### The scope

The `docs/DATABASE.md` lowercase rule, extended to escape LIKE metacharacters so a
clerk typing `100%` doesn't match every row:

```php
public function scopeSearch(Builder $query, ?string $term): Builder
{
    if (blank($term)) {
        return $query;
    }

    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($term)).'%';

    return $query->where(function (Builder $q) use ($like): void {
        $q->whereRaw('lower(documents.control_number) like ?', [$like])
          ->orWhereRaw('lower(documents.title) like ?', [$like]);
    });
}
```

> **Correction to inherited design.** Earlier drafts searched a `sender_name`
> column and filtered on `documents.current_office_id`. **Neither exists** —
> see D4. Filter by holding office through the open leg instead:
>
> ```php
> ->when($request->integer('office_id'), fn ($q, $id) => $q->whereExists(
>     fn ($sub) => $sub->from('document_movements')
>         ->whereColumn('document_movements.document_id', 'documents.id')
>         ->whereNull('document_movements.departed_at')
>         ->where('document_movements.to_office_id', $id)
> ))
> ```

### The query

```php
$documents = Document::query()
    ->visibleTo($request->user())          // office scoping — never omit
    ->active()                             // excludes archived; explicit, not a global scope (D16)
    ->search($request->input('q'))
    ->when($request->input('status'), fn ($q, $s) => $q->where('documents.status', $s))
    ->when($request->date('from'), fn ($q, $d) => $q->where('documents.created_at', '>=', $d->startOfDay()))
    ->when($request->date('to'),   fn ($q, $d) => $q->where('documents.created_at', '<=', $d->endOfDay()))
    ->orderBy("documents.{$sort}", $dir)
    ->orderBy('documents.id', 'desc')      // deterministic tiebreak — without it page 2 repeats rows
    ->paginate($request->integer('per_page') ?: 25)
    ->withQueryString()
    ->through(fn (Document $d) => [ /* explicit projection, never the raw model */ ]);
```

> **Say this plainly in the handover:** `lower(col) like '%term%'` cannot use a
> B-tree index — every search is a sequential scan. At LGU volume (single-digit
> thousands of documents a year) that is fine on shared hosting. Past roughly 200k
> rows it is not, and the fix is driver-specific (`pg_trgm` GIN vs MySQL
> `FULLTEXT`), which `docs/DATABASE.md` forbids. The *filters* do use indexes —
> `documents(status)`, `documents(created_at)`, `documents(archived_at)`.

### The page

Filter state lives in the **URL**, so a filtered view is bookmarkable and
shareable:

```tsx
router.get(documentsRoutes.index.url(), { ...filters, ...next, page: undefined }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,                       // don't stack a history entry per keystroke
    only: ['documents', 'filters'],      // partial reload
});
```

300 ms debounce on the search input, with a `mounted` ref so the effect does not
fire a redundant request on first render.

---

## 2. Dashboard (#14, PHP 500)

§18: total documents, monthly documents processed, delayed documents, approval
rate, plus a table of recent/pending documents.

**Every widget starts from `visibleTo($user)`.** User sees their own, Admin sees
their office, Super Admin sees all. `withoutGlobalScopes()` is banned in the
reporting namespace.

### Three counters in one query

Portable `CASE WHEN` conditional aggregates:

```php
$r = Document::query()
    ->visibleTo($user)
    ->selectRaw('count(*) as total')
    ->selectRaw('sum(case when completed_at >= ? and completed_at < ? then 1 else 0 end) as processed', [$start, $end])
    ->selectRaw('sum(case when due_at < ? and completed_at is null and status <> ? then 1 else 0 end) as delayed',
        [$now, DocumentStatus::Rejected->value])
    ->selectRaw('sum(case when status = ? then 1 else 0 end) as completed', [DocumentStatus::Completed->value])
    ->selectRaw('sum(case when status = ? then 1 else 0 end) as rejected', [DocumentStatus::Rejected->value])
    ->first();
```

`$now` is a **PHP-bound parameter**, never SQL `now()` — see D14.

**Cast every aggregate.** PDO returns `SUM()` as a string, and as `null` rather
than `0` when no rows match.

**Approval rate** = `completed / (completed + rejected)`, computed in PHP. Render
an em dash when the denominator is zero — never `0%`, which reads as "everything
was rejected".

### Monthly volume

`EXTRACT` is standard SQL and identical on PostgreSQL, MySQL and MariaDB, so this
does **not** branch on driver:

```php
->selectRaw('extract(year from completed_at) as y')
->selectRaw('extract(month from completed_at) as m')
->selectRaw('count(*) as c')
->groupByRaw('extract(year from completed_at), extract(month from completed_at)')
```

Group by the **full expressions**, not the output aliases — alias grouping happens
to work on both drivers today but breaks under `ONLY_FULL_GROUP_BY` on MariaDB.

Seed zero-months in PHP so the chart has no gaps; that is five lines, versus twelve
round-trips for a date-range loop.

> **Archived documents are counted.** Per D16 the dashboard does *not* apply
> `->active()`. Archiving is a filing action, not a deletion — excluding archived
> rows would silently undercount completed work, which is the opposite of what §20
> is for.

### Recent / pending table

Reuse the Phase 4 documents table component with a fixed filter. Do not build a
second table.

---

## 3. Archive Management (#16, PHP 400)

§20: completed or released documents can be archived and retrieved later without
cluttering active tracking views.

The columns already exist — `archived_at`, `archived_by_id`, `archive_reason` —
shipped in the Phase 1 migration. This phase adds behaviour only.

### Not a global scope

> ⚠️ An earlier draft implemented this as a `notArchived` **global scope** on
> `Document`. That is rejected — see **D16**. A global scope would silently drop
> archived documents out of monthly volume, status distribution, processing trend,
> every export and the "total documents" widget. It would also fight D3, which
> already rejects global scopes for office visibility.

Instead: an explicit local scope, applied on **list views only**.

```php
#[Scope]
protected function active(Builder $query): void
{
    $query->whereNull('documents.archived_at');
}
```

Qualify the column name. An unqualified `archived_at` is ambiguous the moment a
query joins `document_movements`.

### Actions

- Archive and restore are **policy-gated** and admin-only
- Only `Completed` or `Rejected` documents may be archived — a document still
  moving between offices is not "released"
- Both actions write a movement row (`action='archived'` / `'restored'`), so the
  audit trail records who filed it and when
- Route order matters: declare `documents/archived` **before**
  `documents/{document}`, or the literal is swallowed by the wildcard

### Archive is not deletion

Do not use `SoftDeletes` for this. Archiving is a retained business state with its
own timestamp, actor and reason; soft deletion is a different concept with
different semantics, and conflating them makes "restore" ambiguous.

---

## 4. Help & Support (§23) — specified but unpriced

> **Raise this with the client before building.** §23 names three pages —
> Knowledge Base, Submit a Support Ticket, Contact Support — and there is **no line
> item for any of them** in the 20-row cost breakdown. §24 says the documentation
> reflects the final agreed scope, so it is arguably included; the cost table says
> it was never priced. See **B5** in [`client-questions.md`](client-questions.md).

The cheapest honest implementation, assuming B5 lands on "include it":

| Page | Build |
| --- | --- |
| Knowledge Base | Static content in a shadcn `accordion`. No CMS, no editor |
| Contact Support | A card rendering office/contact details from `config/cicto.php` |
| Submit a Support Ticket | A form that **emails** — no ticket table, no admin queue |

> ⚠️ The ticket form is blocked by **B3**: `MAIL_MAILER=log` means nothing sends.
> If there is no mail service, this page cannot function as named, and that is a
> scope reduction that must be acknowledged in writing rather than shipped as a
> form that silently discards submissions.
>
> **Unblocked 2026-08-23.** The operator configured Google SMTP, so the ticket is
> delivered and the page does function as named. The warning shipped anyway and
> still fires — see *§23 Help & Support* below — because it is what a deployment
> without a mailer gets, and that is still a deployment somebody may make.

A stored-ticket model with an admin inbox, status and replies is a separate
feature and a §5 re-quote.

---

## 5. Components to add

```bash
npx shadcn@latest add table tabs popover pagination   # documents table + filters
npx shadcn@latest add calendar                        # date-range filter (pulls react-day-picker + date-fns)
npx shadcn@latest add accordion                       # §23 knowledge base
```

**Decline `npx shadcn@latest add form`** — it pulls `react-hook-form`,
`@hookform/resolvers` and `zod`, a second validation vocabulary alongside Laravel
FormRequests. The repo already uses Inertia `useForm` with server-side errors via
`components/input-error.tsx`. One source of validation truth.

---

## 6. Handover and deployment

Unbudgeted in the cost breakdown and undesigned anywhere else, but the contract
does not deliver itself. Contract §6 transfers source and full rights on final
payment and says nothing about who installs it — settle that.

- [ ] Deploy to the client's real host. **This is where the HTTPS, cron,
      `proc_open` and reverse-proxy scan-URL risks all detonate** — every one of
      them is a Phase 1 or Phase 3 assumption being cashed
- [ ] Verify `CICTO_SCAN_BASE_URL` emits `https://` on the real host **before** any
      label is printed at volume
- [x] Seed real reference data — offices, codes and document types. Done
      2026-08-18: `OfficeSeeder` carries the client's 53 offices with their own
      aliases as the codes, `DocumentTypeSeeder` the 43 real types. Retired
      placeholder codes are deactivated, never deleted, because existing documents
      and control numbers reference them
- [ ] Seed real **turnaround days** — still open, the remaining half of client
      question **A4**. Every type is seeded `turnaround_days` NULL and falls back to
      the provisional 3-day default, so no type has its own SLA yet. There is no
      admin screen for document types: the real numbers are a seeder edit and a
      deploy, not a settings change
- [ ] Create the real Super Admin account; remove every demo account
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, config and route caches warm
- [ ] `APP_KEY` escrowed per the Phase 3 procedure
- [ ] Run one real backup **and one real restore** — §22 says Recovery, not just
      Backup
- [ ] Walk the three roles through their own panels; note that training is not in
      the cost breakdown
- [ ] UAT sign-off against the §-numbered acceptance list, in writing
- [ ] Agree a warranty window — the contract does not define one

---

## Tests that must exist

- [ ] Lowercase search finds an uppercase control number **on both drivers** —
      this is the single test that proves the portability rule
- [ ] `100%` as a search term does not match everything
- [ ] An invalid `sort` value is rejected, not interpolated
- [ ] Page 2 does not repeat rows from page 1 (the `id` tiebreak)
- [ ] An Admin's document list excludes another office's documents
- [ ] Archiving removes a document from the active list and keeps it in dashboard
      totals
- [ ] Restore returns it to the active list
- [ ] A non-admin cannot archive
- [ ] `documents/archived` resolves to the archive page, not to a document named
      "archived"
- [ ] Approval rate renders an em dash, not `0%`, with no decided documents

---

## Exit criteria

- [ ] **B5 answered in writing** — Help & Support included or formally excluded
- [ ] Search is case-insensitive on **both** pgsql and mysql
- [ ] Filter state survives a page refresh and a shared link
- [ ] All four §18 widgets render correct numbers against seeded multi-month data,
      scoped correctly for each of the three roles
- [ ] Archived documents are absent from active lists and present in totals
- [ ] §4's navigation labels match the spec exactly, and the User-role nav question
      from Phase 1 has a written answer
- [ ] Deployed to the client's host, UAT signed off
- [ ] `composer ci:check` green on both driver legs

---

## Built — 10 Aug 2026

### Reports & Analytics

Rebuilt to the client's design: four icon tiles, a **Monthly Documents
Processed** grouped bar chart, a **Status Distribution** pie with its own export
buttons, and the existing processing-trend and activity tables underneath.

The bar chart splits into **five** buckets, not the four §8 names —
`DocumentStats::monthlyByStatus()`. The design's legend separates *In Process*
from *For Approval*, which is a genuinely useful distinction on a report: it is
the difference between work an office is still doing and work waiting on a
signature. `publicLabel()` merges them because a citizen tracking a folder does
not need that detail; a records officer reading a report does. The mapping lives
in one constant so the chart, its legend and any future export cannot disagree.

Bucketed by `created_at`, so a month's bar shows what **arrived**.
`monthlyProcessed()` still answers what was **finished**, and both are on the
page.

### §23 Help & Support

The client supplied designs for Help and the Knowledge Base, which settles
question **B5** — it is in scope. Built as the plan specified: static articles,
an emailed ticket, contact details from config. No CMS, no ticket table, no
admin inbox. A stored ticket model with statuses and replies remains a separate
feature and a re-quote.

Six articles live in `app/Support/Help/KnowledgeBase.php`. A test asserts every
listed article actually opens and that each belongs to a declared category — a
knowledge base whose links 404 is worse than an empty one.

**The B3 warning is honoured rather than papered over.** As built, `MAIL_MAILER=log`
meant nothing left the server, so:

- the ticket page shows the warning **before** the form, not after submitting —
  the user needs to know while deciding whether to type out their problem;
- a submission with no mail configured returns a **warning**, never a success:
  *"recorded in the system log but not delivered"*;
- every ticket is logged regardless, because a ticket that exists only inside an
  unconfigured mail transport is a ticket nobody will read.

`HelpTest::test_a_ticket_never_claims_to_be_sent_when_mail_is_not_configured`
pins that. It is the load-bearing test in the file.

The ticket form takes no name or email field — both come from the signed-in
account. A form that lets you type somebody else's address is a way to send mail
as them, and this one posts to a municipal inbox.

**2026-08-23 — mail is configured, and the honesty moved to the branch beside it.**
With `MAIL_MAILER=smtp` the warning does not render, the ticket is delivered, and the
page reports a real success. Everything above is untouched and still fires for a
deployment with no mailer. What had no answer at all was the case in between — a
transport that exists and then fails — which arrived as a 500 the moment one could.
`HelpController` catches `TransportExceptionInterface` on the send and reports *"your
ticket was recorded, but the email could not be delivered just now"* with the office
phone number, which is the same warning the no-transport branch gives and for the same
reason: the ticket is logged either way, and a Gmail hiccup is not the user's problem
to read a stack trace about. `resources/views/mail/support-ticket.blade.php` also
stopped HTML-escaping a `text/plain` body, which had been delivering an apostrophe in
somebody's problem description as `&#039;`.

### §16 Archive Management

Zero new schema, as the plan requires: `archived_at`, `archived_by_id` and
`archive_reason` already existed, as did `DocumentBuilder::active()/archived()`,
the policy and `MovementAction::Archived`. What was missing was every route,
controller and screen.

Archiving is **filing, not deletion**. The row, its files, its signatures and
its whole history stay; only membership of the working list changes. A ledger
row is appended because §13 says the ledger is the single history and "filed on
the 3rd, restored on the 9th" is part of it.

Two properties worth stating, both tested:

- **Archiving never opens a leg.** A terminal document is nowhere; filing must
  not put it back in an inbox, and an open leg would break the one-open-leg
  unique index outright.
- **Restoring is not re-opening.** A completed document comes back completed.

Archiving twice is a no-op rather than an error — two people clicking Archive is
not worth a stack trace, and the second click must not overwrite the first
one's reason.

### A footgun found while building it

`Document::movements()` bakes in `orderBy('sequence')` **ascending**. Appending
`orderByDesc('sequence')` does not replace it — the ascending clause still wins,
so you silently get the **first** leg. That produced a duplicate-sequence
constraint violation. `reorder('sequence', 'desc')` is the correct call, and the
test that caught it carries the explanation.

Also fixed: `User::$office` had no property annotation, so static analysis
resolved a nullable `belongsTo` as non-null. `office_id` is nullable and a user
can exist before being assigned to an office.

### Verified

- **207 tests green on SQLite, PostgreSQL 17 and MySQL 8** (14 new); PHPStan
  level 7, Pint, ESLint, Prettier, `tsc --noEmit` and a production build clean
- Every page at 375/390/768/1280 with **zero horizontal overflow**
- All Help routes 200, an unknown article slug 404s, and the archive list is
  office-scoped

### Not done

**#4 Search and Filter** and **#14 Dashboard** were already built in earlier
phases and were not re-examined against this plan's acceptance criteria in
detail. Handover and deployment (§6 of this document) is untouched.

---

## Phase 4 completed — 10 Aug 2026

The three items left open in the previous entry are now done.

### #4 Search and Filter — audited, already compliant

Checked line by line against this document's own specification. It matched
throughout: `sort`/`dir`/`per_page` validated through `Rule::in` before reaching
`orderBy`, LIKE metacharacters escaped, `lower()` on both sides, office filtered
through the open leg rather than a `current_office_id` column that does not
exist, deterministic `id` tiebreak, URL-held filter state with a 300 ms debounce
and a `mounted` ref.

What was missing was the **evidence**. `SearchAndFilterTest` now covers all six
of this plan's named cases, and the portability one is run on all three drivers:

- a lowercase search finds an uppercase control number — the single test that
  proves the `docs/DATABASE.md` rule, because MySQL's default collation is
  case-insensitive and PostgreSQL's is not;
- `100%` and `_` do not match every row;
- an invalid `sort`, `dir` or `per_page` is rejected, not interpolated;
- page 2 does not repeat page 1 when `created_at` ties;
- an Admin never sees another office's documents, including by searching for
  its control number directly;
- `documents/archived` is a 404, not a document lookup.

### #14 Dashboard — was NOT to spec, now is

The dashboard was showing operational counters (open here, overdue, due soon,
submitted) and **not** §18's four figures. It also applied `->active()`, which
this plan explicitly warns against: archiving is filing, not deletion, so
excluding filed rows made an office's completed total fall every time somebody
tidied up.

It now shows §18's **total, processed this month, delayed, approval rate**,
drawn from the same `DocumentStats::summary()` that Reports uses — so the two
screens can never quote different totals for the same office, which
`DashboardStatsTest` asserts directly. The operational counters remain
underneath as a second row, because they answer a genuinely different question.

Also fixed: "submitted by me" excluded archived submissions. Filing something
does not mean you did not submit it.

The dashboard moved into the top-nav shell — "Home" in §4's main navigation
points at it, and every user-facing design shows the top nav.

### §6 Handover — written, and kept honest

`docs/handover/DEPLOYMENT.md` is a runbook, not a summary: the four
prerequisites and what breaks without each, the environment values, the three
settings that are hard to undo (`CICTO_SCAN_BASE_URL`, HSTS, CSP enforcement),
the `APP_KEY` escrow procedure, a restore drill that ends by *querying the
restored database* rather than assuming the load worked, a go-live checklist,
the known limits to state in writing, and what the client still owes.

Two commands were written because the runbook needed them to exist:

- **`cicto:host-check`** probes PHP, `proc_open`, ZipArchive, GD, upload limits,
  both disks, the mailer, the scan URL and the backup driver, and prints what
  each missing capability costs. It exits 0 either way — it is a report, not a
  gate. When this was written it correctly reported the three real gaps on this
  machine: no outgoing mail, a non-HTTPS scan URL, and no restore ever drilled.
  Two of the three are still open. The mail row has read OK since 2026-08-23, and
  the command grew an **SMTP handshake** row that really connects and
  authenticates, so "OK smtp" can no longer be earned by a wrong App Password.
- **`cicto:create-super-admin`** prompts for a hidden password rather than
  taking one from a file, enforces a 12-character minimum, and creates the
  account verified so nobody is locked out of a new deployment waiting on an
  email service that may not exist yet.

`HandoverTest` greps the runbook for every `php artisan …` and `--class=…` it
names and asserts each exists. A runbook that names a command which does not
exist is worse than no runbook, and this one drifts the moment somebody renames
a command.

### Verified

- **223 tests green on SQLite, PostgreSQL 17 and MySQL 8** (16 new); PHPStan
  level 7, Pint, ESLint, Prettier, `tsc --noEmit` and a production build clean
- Dashboard, Track, Archive, Reports, Help and both panels all 200, with zero
  horizontal overflow at 375/390/768/1280
- `cicto:create-super-admin` run end to end and the resulting account inspected

## B3 answered — 20 Aug 2026

`DTS-Questions (3).docx` came back with one cell changed from "?", and it is the
cell that had been holding up the two features named in §3 and §12. The answer
is a refusal followed by an instruction:

> For email-related settings, CICTO cannot provide email credentials or
> configuration details, even when the system is being developed for school or
> academic purposes. … For password reset functionality, you may develop a
> module that allows the system administrator to reset the password of any user
> registered in your application.

So the recovery path for a forgotten password is a person, not an inbox.

### What was built

**The module they asked for.** `POST /super-admin/users/{user}/password`, behind
`EnsureRole::using(Role::SuperAdmin)` and `throttle:6,1`, rendered on Manage
Users as a per-row **Set password** panel. `App\Actions\Users\ResetAccountPassword`
owns the operation and the console shares it, because two implementations of
"give somebody a new password" is how one of them ends up skipping a step.

Setting a password is a complete account takeover, so it does five things rather
than one — the administrator's own password is required in the form,
`remember_token` is rotated, any outstanding emailed reset token is deleted, the
account's live sessions are destroyed, and second factors are removed on request.
The reasoning for each, and why `logoutOtherDevices()` cannot be used here, is in
[`client-questions.md`](client-questions.md) §B3.

It writes a **new** `SecurityEventType::PasswordResetByAdmin` rather than reusing
`auth.password_reset`, which `RecordSecurityEvents` renders as "*<email>* reset
their password" with the account holder as actor. Reusing it would have put the
wrong person's name against the one operation that hands over somebody else's
account.

**`cicto:user <email> --reset-password`**, for the situation the screen cannot
serve: every Super Admin locked out, so there is nobody left to sign in and press
the button, and no reset link either. Generates the password, prints it once,
and runs the identical action. No `--password` option — it would outlive the
deployment in a hosting panel's command history, and `AccountCommandsTest`
asserts its absence.

**The honesty guard on a page that had been lying.** This is the part nobody
asked for and it is the reason the answer mattered. Fortify's forgot-password
flow does not check whether mail works: under `MAIL_MAILER=log` it minted a real,
single-use, one-hour token for any address posted to it, wrote the whole message
— reset link included — into the shared unrotated log at debug level, and
returned the green *"We have emailed your password reset link."* Every deployment
of this system has been doing that. The page now says it cannot send email and
names the administrator; `RequireOutgoingMail` refuses the POST, with the same
message for a known and an unknown address so it cannot be used to enumerate
accounts; and the `log` mailer writes to its own 7-day channel via
`MAIL_LOG_CHANNEL` rather than into `stack`. *(Superseded 2026-08-23 — see* Mail
turned on *below. The guard is still in the stack; it no longer fires, because the
mailer is real.)*

**A Google SMTP path for an LGU that wants one.** `.env.example` and the runbook
§3 both carry the recipe, including the two things that bite: an App Password
rather than the account password, and `MAIL_FROM_ADDRESS` matching the
authenticated account or every message is rejected while the connection reports
healthy. `cicto:host-check` gained a row for the second.

**One bug the module would otherwise have been blamed for.** `StoreUserRequest`
did not lower-case the email address, while every other door into `users.email`
does — Fortify's `lowercase_usernames` on sign-in and on the reset request,
`cicto:user` on its argument. An account created as `Maria.Santos@…` cannot be
signed in to on PostgreSQL, where `=` is case-sensitive; the obvious remedy is
"reset their password", and it does not work, because the failure is the lookup
and not the credential. It canonicalises now, and two tests pin it.

**One predicate, deduplicated.** `App\Support\OutgoingMail` replaces a private
method on `HelpController` and a second copy inlined in `HostCheckCommand`. Two
copies of "can mail leave this server" is one drift away from a screen that says
it works while the probe says it does not.

### Found in review, and fixed before this shipped

An adversarial pass over the change produced nineteen candidate defects, of
which five survived independent verification. All five are fixed; the rest were
refuted, mostly as true-of-Laravel-in-general but not of this configuration.
They are listed because four of the five were introduced by this work.

1. **`.env.example` contained a duplicate-key trap of its own making.** The
   Google SMTP recipe shipped as a commented block of `MAIL_*` keys sitting
   directly above the live placeholder block with the same key names. Uncomment
   the six lines the file tells you to and dotenv's last-assignment-wins rule
   leaves `MAIL_MAILER=smtp` pointing at `127.0.0.1:2525` — a mailer the
   application now believes works, so the honesty guard steps aside, the form
   comes back, and a submitted request mints a live reset token and then 500s.
   Precisely the state this change exists to eliminate, reached by following the
   file's own instructions. The recipe is now prose over a single live block,
   with the last-wins rule stated.
2. **Two open panels shared input ids.** Both the Add-account form and the reset
   panel render `id="password"`; `label[for]` resolves to the first match, so
   clicking the reset panel's "New password" label put the caret in the *other*
   form. The administrator types a password into the wrong form and submits a
   reset with an empty one. The reset fields carry their own ids now, the two
   panels are mutually exclusive, and a test asserts the ids stay distinct.
3. **The panel opened off-screen.** Measured in Chrome on a 1366×768 laptop:
   pressing "Set password" on row 12 of 15 mounted the form ~517px above the
   viewport and pushed every row down by its height, so the row under the cursor
   became a different person with nothing visible to explain it. It now scrolls
   into view and takes focus.
4. **The success message overstated what it had removed.** "Two-factor and
   passkeys were removed" was unconditional, so an account that carried only a
   passkey was reported as having lost a two-factor enrolment it never had —
   contradicting the checkbox the administrator had just read. `PasswordResetOutcome`
   now carries the list of what was actually there, and the toast, the console
   output and the audit line are all built from it.
5. **A field posted as an array 500'd instead of failing validation.** Only
   `required` is an implicit rule, so `your_password[]=x` failed `string` and
   still reached `current_password`, which handed the array to
   `password_verify()` and threw an uncatchable `TypeError`. `bail` in
   `PasswordValidationRules::currentPasswordRules()` fixes it here and on the
   pre-existing `PUT /settings/password`, which had the same shape.

The two the review found in the console path — `--reset-password` creating an
account on a mistyped address, and its inability to clear a two-factor lock on
the one path that exists for a locked-out system — are described under *What was
built* above, because both were fixed into the feature rather than after it.

### Verified

- **378 tests green, 39 of them new** — 22 on the reset module, 6 on the
  mail-unavailable behaviour, 8 on the console flag, 3 on email canonicalisation
  and markup. Three existing `PasswordResetTest` cases also had to declare
  `mail.default = smtp`, because `phpunit.xml` pins `array` and the new guard
  correctly reads that as no mail
- **Green on both drivers**: 378/378 on SQLite and on PostgreSQL 17, including
  the session-eviction case, which does a raw delete against the `sessions`
  table
- PHPStan level 7, Pint, ESLint, Prettier, `tsc --noEmit` and a production build
  clean
- Session eviction is asserted with `SESSION_DRIVER` switched to `database`;
  under `phpunit.xml`'s pinned `array` it is a deliberate no-op and the test
  would have passed without testing anything
- Both handover guides regenerated to PDF, and `docs/qa/journeys.sh` gained
  three smoke assertions: the reset route 403s for a clerk and for an office
  Admin, and `/forgot-password` still renders without a mailer

### Not done, deliberately

- **No SMTP is configured anywhere.** The client said they will not supply it;
  inventing a service would be worse than the honest absence. *(Superseded
  2026-08-23: the operator configured Google SMTP on an account of their own. The
  client still supplies nothing — see* Mail turned on *below.)*
- **No in-app messaging.** Offered at our discretion, and declined: the client
  noted the existing DTS has none, so it is not a gap against the incumbent.
  §12 stays in-app *notifications*, which is a different feature and already
  built.
- **No emailed password reset built on top of a service we stand up.** Permitted
  by the answer, not required by it, and it would need an account somebody pays
  for and rotates. *(Superseded 2026-08-23: the operator provided exactly that
  account, and rotating it is theirs to own. Fortify's emailed reset works; the
  administrator panel stays as the route for anyone who cannot receive mail.)*
- **Second factors are not cleared by default.** A forgotten password and a
  stolen account want opposite answers, so it is a decision the administrator
  makes and the audit line records either way.
- **Deactivating an account still has no screen**, so an incident response is
  "set a new password" from the UI and `cicto:user --deactivate` from the shell.
  That gap predates this work and is named in the runbook; it is worth pricing
  alongside the role and office screens rather than absorbing.

---

## Mail turned on — 23 Aug 2026

Three days after B3 closed against us, the operator answered the half of it the
client had left open. *"We highly recommend using alternative email services, such
as Google SMTP"* was advice, not an obstacle, and somebody took it: a Gmail account
with 2-Step Verification, a 16-character App Password, `MAIL_MAILER=smtp` over
STARTTLS on port 587, and `MAIL_FROM_ADDRESS` equal to the authenticated account.
Confirmed with a real handshake and a real delivered message rather than a config
read. `cicto:host-check` reports *Outgoing mail: OK smtp*, *Mail From address: OK*,
*SMTP handshake: OK authenticated*.

**Nothing about the client's answer changed.** CICTO still supply no credentials and
no configuration, and the administrator-set-password module is still what they asked
for in place of email. What changed is that it is no longer the only way back into an
account.

### What turning it on exposed

The mail-off configuration had been carrying more load than anybody had written down.

**The `verified` middleware was a no-op, and nothing would have revealed it.** `User`
did not implement `MustVerifyEmail`, so the `verified` middleware on all six protected
route groups matched nothing: anyone could self-register and reach every authenticated
screen with an address they had never proved they own. That was survivable only
because accounts were created pre-verified and there was no verification mail to send
anyway. The interface is declared now, registration sends the link, and the middleware
finally means what every route file says it means. All five existing users were
already verified, so nobody was locked out. `docs/qa/FINDINGS.md` had carried this as
an open MEDIUM since 11 August; it is ticked.

**`POST /forgot-password` had no rate limiter, and had not needed one.**
`RequireOutgoingMail` refused every request before Fortify's broker ran, so the missing
mailer *was* the throttle, and turning mail on removed it without anyone touching a
file. `config/auth.php`'s `'throttle' => 60` does not cover this: it is keyed on the
email address, so it stops one address being mailed twice a minute and does nothing at
all about one client walking a staff list. N known addresses is N real messages a
minute out of the operator's own Gmail, against a free-account ceiling of roughly 500
recipients a day — after which Gmail refuses everything for 24 hours and takes support
tickets and verification mail down with it. `ThrottlePasswordResetRequests` caps it at
10 per IP per hour, registered beside `ThrottlePublicRegistration` for the same reason:
Fortify declares the route itself and exposes no limiter key for it.

**A dead SMTP connection was a 500.** `bootstrap/app.php` now renders
`TransportExceptionInterface` as a field error on the form the user is looking at, and
for `register.store` as a redirect to the verification notice — the account was
created, the message was not sent, and "resend" is the right next move rather than a
stack trace. `HelpController` catches the same exception on the ticket send.

**`config/mail.php` had no timeout.** `'timeout' => null` does not mean *no limit*;
Symfony falls back to PHP's `default_socket_timeout`, 60 seconds on most builds. Mail
here is sent inline, and `SESSION_DRIVER=database` means a wedged connection held the
user's session lock for that whole minute. It is `MAIL_TIMEOUT` now, defaulting to 15.

**`cicto:host-check` could not tell a good App Password from a bad one.** It read
`mail.default` and printed "OK smtp", which a typo'd credential passes. It opens a real
STARTTLS connection and authenticates now, sending nothing.

**The support-ticket template was HTML-escaping a `text/plain` body**, so an apostrophe
in somebody's problem description arrived as `&#039;`.

### Still true, and worth saying plainly

- **Mail is sent inline, not queued.** There is no worker and nothing runs
  `queue:work`, so a queued message would silently never send — the one failure this
  build refuses to ship. The cost is ~1–3 s on the requests that send, bounded by the
  15-second timeout.
- **The scheduler still needs its single crontab line** for deadline sweeps, signature
  checks and backups. A working mailer is not a working scheduler.
- **The Gmail account is a free one**: roughly 500 recipients a day, and exceeding it
  disables sending for 24 hours.
- **§12 notification email is still out of scope and still a change order.** A working
  mailer makes it possible, not included.
- **The App Password lives in `.env` and nowhere else.** It is not in this repository,
  must not be put there, and has to be rotated if the account changes hands.

---

### Still open — and not ours to close

Of the six client questions in §11 of the runbook, **B1 and B4 remain
unanswered.** **B3 closed on 2026-08-20** — closed against us, which is still an
answer: CICTO will not supply email credentials or configuration, recommends an
external service if the LGU wants one, and asked in their place for "a module
that allows the system administrator to reset the password of any user". That
module is built, and with it the honesty guard on a Forgot Password page that
had been reporting success for a link it never sent. **On 2026-08-23 the operator
stood up Google SMTP themselves** — which changes what the system does without
changing a word of the client's answer; see *Mail turned on* above. Three questions
moved on 2026-08-18 without closing:

- **A4** supplied the 53 offices with their aliases and the 43 document types, but
  not the turnaround days — that part went to the City Archive and Records Office,
  so every type is seeded NULL against a provisional 3-day default
- **B2** named the host, a cloud server, but not cron, `proc_open`, the dump
  binaries, the off-site destination, or who tests the restore
- **B6** gave a floor — "3 to 5 years minimum" — rather than a figure. Seeded as
  1095 days; the pruner is still disabled

**A6** also moved, and it is no longer ours to close: self-approval is a Super Admin
toggle now, defaulting to blocked, and the LGU sets it themselves.

Three contract gaps are still undefined: **who installs it**, **training** (not in
the cost breakdown), and **the warranty window**.
