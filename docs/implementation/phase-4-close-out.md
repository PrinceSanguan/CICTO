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
- [ ] Seed real reference data — offices, codes, document types, turnaround days
      (client question **A4**)
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
