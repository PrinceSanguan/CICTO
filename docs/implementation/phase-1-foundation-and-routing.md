# Phase 1 — Foundation & Routing

> **Document → QR → scan → routed between offices.**
> PHP 4,500 · features #1, #2, #3, #5, #11, #17 · also delivers unpriced §3 and §4

Prerequisite reading: [`00-architecture.md`](00-architecture.md).

## Goal

Retire every assumption that can kill the estimate, in the week where a phone call
still fixes it.

Both PHP 1,200 line items land here. Workflow Management (#5) because it defines
the movement ledger that PHP 2,900 of later features read from. QR Tracking (#17)
because its risk is *environmental* rather than technical — `getUserMedia` requires
an HTTPS secure context, `APP_URL` is `http://localhost:8000`, no deployment host
is confirmed, and there is no decode library in `package.json`.

Registration, Upload and Classification come **with** them rather than after,
because §5 describes one form capturing title, department, document type, priority,
description, remarks *and* file — and because the private-disk decision must be
made on the first line of upload code, not retrofitted.

RBAC (#11) is here not because it is hard but because it is cross-cutting. There is
no `role` column on `users` today, and every policy, route guard and panel view
written before it exists gets revisited.

## Demo at the end of this phase

Emarie opens **Submit Document**, fills in title, department, document type,
priority, description and remarks, attaches a file, and gets back a control number
and a printable QR label. She prints it, tapes it to a physical folder, scans it
with her own phone against the real deployment host, and sees the control number,
the holding office and the routing history.

She logs in as the receiving office's Admin, forwards the folder from Office A to
Office B, rescans the same taped label, and watches the location change.

Then she logs in as User, Admin and Super Admin and sees three visibly different
sidebars — and a User typing an Admin URL directly gets a **403**, not a hidden
button.

---

## Before you start

Two questions from [`client-questions.md`](client-questions.md) block this phase
and cannot be discovered in week two:

- **A1** — does "via camera or scanner" (§7) mean a phone camera (needs HTTPS on
  the deployment host), a USB keyboard-wedge scanner (effectively free), or both?
  Deployment target and TLS confirmed, or camera scanning descoped **in writing**.
- **A3** — is the §9 stage list a fixed pipeline, or Super-Admin-configurable per
  document type? A configurable workflow engine is several times PHP 1,200 and is
  a §5 re-quote, not an absorbed revision.

Also useful now: **A4** (real office list, codes, document types, turnaround days),
**A5** (who creates accounts), **A6** (can an Admin approve their own submission).

---

## Naming reconciliation

The design work produced two names for several things. These are the bindings.
Use them everywhere; do not reintroduce the alternates.

| Use this | Not this | Why |
| --- | --- | --- |
| `document_number_sequences` | `control_number_sequences` | Matches the model name `DocumentNumberSequence` |
| `arrived_at` / `departed_at` | `received_at` / `released_at` | Consistent with the movement/leg vocabulary |
| `actor_id` | `acted_by` | Matches every other `*_id` FK in the schema |
| `documents.qr_token` `string(26)` | `char(26)`, `string(32)`, ULID | `char()` blank-pads on PostgreSQL. 26 chars of lowercase Crockford Base32 = 130 bits of CSPRNG randomness. **Not a ULID** — its 48-bit timestamp prefix leaks creation ordering to anyone photographing two labels |
| `documents.due_at` | `expected_completion_at` | One name for the overall SLA. Per-leg SLA is `document_movements.due_at` |
| **No** `documents.current_office_id` | a denormalised holder column | Current holder = the open leg. With `is_open` indexed this is a cheap lookup, and it cannot drift |

### One change adopted from the workflow design

`00-architecture.md` says the one-open-leg invariant "cannot be a portable database
constraint". **It can, with a trick, and we are using it:**

```php
$table->unsignedTinyInteger('is_open')->nullable();   // 1 while open, NULL once departed
$table->unique(['document_id', 'is_open'], 'dm_document_open_unique');
```

Both MySQL and PostgreSQL permit *many* NULLs inside a unique index, so this
enforces **at most one open leg per document in the database itself, on both
drivers.** Keep the test as well — but the constraint is now real.

---

## Work breakdown

### 1. Repo hygiene (do this first — under an hour)

- [ ] **Restore `.env.example`** — it is *tracked in git and deleted from the
      working tree*, not missing. `git checkout -- .env.example` brings it back;
      do not hand-write a new one. Until then `composer setup` copies a file that
      is not there, and a fresh clone silently gets no environment
- [ ] Reconcile the database name. The tracked `.env.example` says
      `DB_DATABASE=cicto` (lowercase); the live local database is `CICTO`
      (uppercase). Pick one — lowercase is the better choice, since an uppercase
      identifier needs quoting in every hand-written `psql` command
- [ ] Create `.github/workflows/tests.yml` running the pgsql + mysql matrix that
      `docs/DATABASE.md:97` already claims exists
- [ ] `config/cicto.php` with `scan_base_url` (see QR below)
- [ ] Remove the starter-kit links in `resources/js/components/app-sidebar.tsx:30,35`
      (`github.com/laravel/react-starter-kit`, `laravel.com/docs`)
- [ ] Seed demo accounts **pre-verified** — `MAIL_MAILER=log` means no verification
      email can be delivered and `verified` middleware would lock out every account

### 2. Migrations

Eight, in this order. Full column bodies are in `00-architecture.md` §3–4; the
notes below are the parts that are easy to get wrong.

| # | Migration | Watch out for |
| --- | --- | --- |
| 1 | `create_offices_table` | Self-referencing `parent_id` covers department-vs-office. `users` already exists so the `head_user_id` FK is safe here |
| 2 | `add_organisation_columns_to_users_table` | `role` string(32) default `'user'`, `office_id` nullable, `position`, `phone`, `is_active`, `last_login_at`. **No `->after()`** |
| 3 | `create_document_types_table` | `turnaround_days` unsignedSmallInteger — this funds §11 deadlines with no later migration |
| 4 | `create_document_number_sequences_table` | `period_year`, not `year` — `YEAR` is a MySQL type keyword. `unique(office_id, period_year)` |
| 5 | `create_documents_table` | Ships `due_at`, `completed_at`, `archived_at`, `archived_by_id`, `archive_reason` **unused** — the Phase 1 tax. No `current_office_id`, no `file_path` |
| 6 | `create_document_movements_table` | The ledger. `is_open` + `unique(document_id, is_open)`. Indexes `(to_office_id, is_open)`, `(document_id, arrived_at)`, `(action, created_at)` |
| 7 | `create_document_files_table` | `version` + `unique(document_id, version)` from line one. `disk`, `path`, `checksum_sha256`. **No `is_current`** |
| 8 | `create_document_scans_table` | A scan is a lookup, not a transfer — deliberately outside the ledger |

Verify after each:

```bash
DB_CONNECTION=pgsql php artisan migrate:fresh && DB_CONNECTION=mysql php artisan migrate:fresh
```

### 3. Enums

`app/Enums/` — backed string enums, cast on the models. No `enum` columns.

```php
enum DocumentStatus: string
{
    case Initiated   = 'initiated';
    case UnderReview = 'under_review';
    case Approved    = 'approved';
    case Returned    = 'returned';
    case Rejected    = 'rejected';
    case Completed   = 'completed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected], true);
    }

    public function tone(): string   // Tailwind token for <StatusBadge>
    {
        return match ($this) {
            self::Initiated => 'slate',  self::UnderReview => 'amber',
            self::Approved  => 'sky',    self::Returned    => 'orange',
            self::Rejected  => 'red',    self::Completed   => 'emerald',
        };
    }
}
```

Also: `MovementAction` (`registered`, `forwarded`, `received`, `approved`,
`rejected`, `returned`, `completed`, `archived`), `Role`, `OfficeType`, and:

```php
enum DocumentPriority: string
{
    case Low    = 'low';
    case Normal = 'normal';   // documents.priority default
    case High   = 'high';
    case Urgent = 'urgent';
}
```

`priority` is a required field on the §5 Submit Document form, one of the three
§6 classification axes funding #3, a filter on the Track Documents page, and the
band printed on the QR label. It is easy to lose because it appears in four places
and is owned by none of them. The column is in `create_documents_table`
(`string(16)` default `'normal'`).

Whether priority *shortens* the due date is a Phase 2 rule — see
[`phase-2-workflow-and-trail.md`](phase-2-workflow-and-trail.md).

> **Client-facing status names.** §8 names the filter values as *Pending, In
> Process, Rejected, Completed* — those are literal acceptance criteria. The
> mapping to the internal vocabulary is in
> [`00-architecture.md`](00-architecture.md) §2. Implement it in the label layer,
> and confirm it at the demo.

### Registration remarks vs approval remarks

§5 lists `description` **and** `remarks` as separate fields on the Submit Document
form. Both are columns on `documents` — do not confuse them with comments.

In Phase 1, an approval/forward remark is written to `document_movements.remarks`
only. `document_comments` is a Phase 2 table; the mirror-into-comments behaviour
starts then. Nothing in Phase 1 writes to it.

### 4. The stage machine

One const map drives both the server guard and the React button set. Controllers
never branch on status.

| from ＼ action | forwarded | received | approved | rejected | returned | completed |
| --- | --- | --- | --- | --- | --- | --- |
| initiated | under_review | under_review | — | — | — | — |
| under_review | under_review | — | approved | rejected | returned | — |
| approved | under_review | — | — | — | — | completed |
| returned | under_review | under_review | — | — | — | — |
| rejected | *(terminal)* | — | — | — | — | — |
| completed | *(terminal)* | — | — | — | — | — |

`forwarded` from `under_review` back to `under_review` is deliberate — **routing
between offices is not a stage change.** `approved → forwarded → under_review` is
the multi-signatory chain (the Mayor's office after the department head).

Illegal transitions throw `IllegalTransitionException extends DomainException`,
rendered once in `bootstrap/app.php` as an Inertia validation error on the `action`
field (or a 409 for JSON). The throw happens *inside* the transaction, so the
movement insert rolls back.

### 5. `TransitionDocument` — the transactional core

The **only** writer of `documents.status` and `document_movements`.

```php
return DB::transaction(function () use (...) {
    $document = Document::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    $leg = DocumentMovement::query()
        ->where('document_id', $document->id)
        ->whereNull('departed_at')
        ->lockForUpdate()
        ->first();

    // Stale-tab / double-click guard: the form posts the leg it was rendered from.
    if ($leg?->id !== $data->expectedMovementId) {
        throw new StaleWorkflowStateException;   // 409
    }

    Gate::forUser($actor)->authorize('act', [$document, $action]);
    $next = DocumentWorkflow::next($document->status, $action);
    // … close the open leg (departed_at = now, is_open = null)
    // … insert the new leg (arrived_at = now, is_open = 1 unless terminal)
    // … update documents.status / completed_at

    DocumentTransitioned::dispatch($document, $new, $action);   // Phase 2 listens
}, 3);   // 3 attempts — retries deadlocks (MySQL 1213 / pg 40P01)
```

**Lock order is fixed:** `documents` row first, then the open movement. Always that
order, so concurrent forwards of the same document cannot deadlock on lock order.

**Double-click defence, three layers:**
1. Inertia's `processing` flag disables the submit button — never rely on it alone
2. `expectedMovementId` token → 409 "This document has already been forwarded"
3. `dm_document_open_unique` makes the duplicate insert fail outright

On shared hosting you *will* get double-submits from slow pages.

The receiving office's stage re-opens purely because the new leg has `is_open = 1`.
Every inbox query is `whereNull('departed_at')->where('to_office_id', $officeId)`.
There is no separate per-office state to keep in sync.

### 6. Control numbers

Format `{OFFICE}-{YYYY}-{NNNNN}` → `MPDO-2026-00042`.

`AllocateControlNumber` (see `00-architecture.md` §6) is the only writer of
`document_number_sequences`, using `lockForUpdate()` inside the registration
transaction. Never `max('id') + 1`, never `count() + 1`. Keep the unique index on
`documents.control_number` as the real backstop.

Wrap the whole registration — sequence, document, genesis movement, first file — in
one outer transaction, so a failed upload never burns a control number.

> ⚠️ `FOR UPDATE` is a **silent no-op on SQLite**, which `phpunit.xml` currently
> pins. Run the allocator concurrency test only in the pgsql/mysql matrix, and add
> a `DB::listen` assertion that the `for update` clause is actually emitted — that
> one catches a dropped `lockForUpdate()` on every driver.

### 7. RBAC and authentication

- `users.role` string(32) + `App\Enums\Role` with `level()` 1/2/3 and `atLeast()`.
  **Verbs** by level, **rows** by `office_id`.
- `DocumentBuilder::visibleTo($user)` — `EXISTS` against `document_movements`
  (`to_office_id` OR `from_office_id`). Never a denormalised office column: an
  office must keep seeing what it already handled.
- Policies for single records. **No `Gate::before` super-admin bypass** — an
  explicit `if ($user->isSuperAdmin()) return true;` in each method, so every grant
  is greppable.
- `EnsureRole::using(Role…)` typed middleware, `abort_unless` → 403.
- `role` and `office_id` stay **out** of `#[Fillable]`; assignment only via a
  Gate-checked `AssignUserRole` action, locked by a mass-assignment test.

**§3 separate login entry points:** three `GET` routes rendering the same
`auth/login.tsx` with a `portal` prop, all POSTing to Fortify's single `/login`,
one `web` guard. See the security trap in `00-architecture.md` §7 — the portal is
presentation only.

**Bind `RoleAwareLoginResponse` to all four contracts** in
`FortifyServiceProvider::register()`: `LoginResponse`, `TwoFactorLoginResponse`,
`PasskeyLoginResponse`, `RegisterResponse`. This also fixes a live bug — passkey
login currently redirects to `/` because no `config/passkeys.php` exists.

Guard `redirect()->intended()`: fall back to the role's own home when the stored
URL is under a prefix the role cannot reach, or a bounced user replays straight
into a 403.

### 8. Navigation (§4)

Use the specified labels **verbatim** — they are acceptance criteria.

| Panel | Items |
| --- | --- |
| Main nav | Home · Track Documents · Reports · Help |
| Admin sidebar | Document Management · Users · Reports · Settings |
| Super Admin sidebar | Manage Users · All Documents · Reports & Analytics · System Settings |

One `AppLayout` with a `NAV_BY_ROLE` record keyed off `auth.user.role`, not three
layout components. **Nav is a hint; middleware and policies are the gate.**

`HandleInertiaRequests::share()` currently returns only `auth.user`. Extend it with
`auth.role`, `auth.office` (id/code/name only) and `auth.can` from
`Role::capabilities()`.

> `resources/js/types/auth.ts` types `Auth.user` as non-null, but `nav-user.tsx:21`
> and `welcome.tsx:50` already guard against null — the type is lying today.
> Widening it to `User | null` will surface `tsc --noEmit` failures. Fix them with
> guards, not non-null assertions.

Ship **Reports** and **Help** now as visible, routed, honestly-labelled stubs, so
the app looks whole in week one instead of growing a mysterious menu in week three.

> ⚠️ **§4's main nav has a hole, and it needs a written answer.**
>
> *Home · Track Documents · Reports · Help* contains no **Submit Document** entry
> — that is §5, features #1 + #2, PHP 1,000 of billable work — and no
> **Dashboard** entry, which is #14, PHP 500. Taken literally, two paid features
> have no way for a User to reach them.
>
> The reading that makes §4 coherent: those four labels are the **public/landing**
> navigation, and the authenticated User panel is a sidebar like the other two
> roles', carrying Dashboard and Submit Document alongside them. Build it that way,
> and get the User-role navigation confirmed in writing — do not silently
> reinterpret a section the client may treat as literal.

### 9. QR code tracking (#17)

**Token:** `documents.qr_token` `string(26)` unique — lowercase Crockford Base32,
130 bits CSPRNG. Lowercase-only because MySQL collation is case-insensitive and
PostgreSQL is not; mixed case would diverge uniqueness across the two required
drivers.

**Payload:** an absolute HTTPS URL and nothing else.

```
https://docs.<lgu>.gov.ph/s/kx7m3q9tb2vfr8hn0cwz5dyj4e
```

Rejected: the raw control number (sequential, enumerable, and already printed in
text on the label); a Laravel signed URL (**never use signed URLs on printed
media** — the signature is taped to the same folder as the thing it protects,
authenticates nothing an attacker lacks, adds ~70 characters of QR density, and an
expiry cannot be honoured by paper); any payload containing content.

> **The security property, plainly:** the QR carries no confidential content and no
> authority. It is a pointer. Confidentiality is enforced at the landing page
> against the *viewer's session*, not against possession of the token.

**Base URL from config, never the request:**

```php
// config/cicto.php
'scan_base_url' => rtrim(env('CICTO_SCAN_BASE_URL', env('APP_URL')), '/'),
```

Behind a shared-host reverse proxy `url()` frequently emits `http://` or an
internal hostname. **That gets printed onto paper and taped to a folder.** Add a
boot-time assertion that it starts with `https://` in production, plus
`URL::forceScheme('https')`. This is the highest-consequence bug in the feature —
it is unfixable without reprinting every label.

**Rendering:** `bacon/bacon-qr-code:^3.0`, SVG backend (needs only
`ext-xml`/`XMLWriter` — no GD, no Imagick, which is exactly why it suits shared
hosting). Error correction **M**, margin 4 modules. Generated on the fly, never
stored.

> ⚠️ **Corrected against the installed library.** An earlier draft of this plan
> claimed v3 turned `ErrorCorrectionLevel` into a native PHP enum, so the call
> should be `ErrorCorrectionLevel::M`. That is **wrong** for the version actually
> installed (`bacon/bacon-qr-code` **3.1.1**), where it is still a
> `DASPRiD\Enum\AbstractEnum` — the correct call is `ErrorCorrectionLevel::M()`,
> **with** parentheses. The encoding constant is `Encoder::DEFAULT_BYTE_MODE_ENCODING`.
> Verify against `vendor/` before trusting any API detail in this document.

**Label print page** is plain Blade (`GET /documents/labels/print?ids[]=`), **not**
Inertia — printing must not depend on SPA hydration. Inline the SVG so the print
dialog cannot fire before an `<img>` resolves. A4 24-up 70 × 37 mm sticker stock
(LGUs already own A4 lasers), with `?offset=N` to reuse half-spent sheets — clerks
will do this regardless.

On the label: control number in ~14 pt bold monospace (the human and wedge
fallback), office code, date received, priority band. **Nothing else** — the folder
travels through mailrooms and the label is the part everyone sees.

Never print below **24 mm square including quiet zone**; enforce the floor in print
CSS. Matte stock only — gloss under packing tape produces a specular hotspot under
office fluorescents that defeats the scan.

**Scanning:** USB keyboard-wedge is the **default** (a focused input, no library).
Camera is progressive enhancement, rendered only when `window.isSecureContext` is
true. `@zxing/browser` + `@zxing/library`, installed only once A1 confirms HTTPS.

> Cleanup is not optional. Without `controls.stop()` on unmount the camera LED
> stays lit after Inertia navigates away. To a municipal employee that reads as
> spyware and will get the feature banned. Also stop on
> `visibilitychange === 'hidden'`.

**Scan landing page:** render a *separate* `documents/scan-public` page for
unauthenticated viewers — not the staff page with fields hidden. Hidden-in-props is
how confidential data leaks through the Inertia payload. The public view reveals
nothing not already printed on the label, plus current status and its date.

Rate-limit the scan route. Dedupe `document_scans` writes within 60 seconds on
`(document_id, user_id|ip)` — a courier waving a phone at a label writes twenty
rows otherwise.

> `document_scans.ip_address` and `user_agent` are personal information under
> **RA 10173 (Data Privacy Act)**. An unbounded log is indefensible — add 180-day
> retention via `MassPrunable` and a scheduled `model:prune`.

### 10. Pages and components

New pages under `resources/js/pages/`: `documents/{create,index,show,scan,scan-public}`,
`admin/dashboard`, `super-admin/dashboard`, plus Reports and Help stubs.

shadcn components to add this phase:

```bash
npx shadcn@latest add table tabs popover pagination
```

**Decline `shadcn form`** — react-hook-form + zod duplicates Laravel FormRequest
validation, and the repo already uses Inertia's `useForm`.

### 11. Storage

- Dedicated `documents` disk, `serve => false`, `throw => true`
- **Flip the existing `local` disk to `serve => false`** — `serve => true`
  registers a live `GET /storage/{path}` route that bypasses `DocumentPolicy` and
  writes no audit row. Assert `Route::has('storage.local') === false` in a test
- Downloads through a policy-gated controller with `->scopeBindings()`
- Upload validation uses **both** `File::types()` and `->extensions()` — `mimes:`
  checks the guessed MIME only, so a `.php` file with a PDF magic header passes
- SVG permanently excluded (stored XSS). On-disk names are generated ULIDs

> Shared hosting `upload_max_filesize`/`post_max_size` often default to **2 MB**, so
> PHP truncates the upload before your 10 MB validation rule ever runs. Set both in
> `.htaccess`/`php.ini` and test with a real large file on the real host.

---

## Tests that must exist

- [ ] Registration allocates a gapless control number under concurrency *(pgsql +
      mysql only — skipped on SQLite)*
- [ ] A `DB::listen` assertion that `for update` is emitted by the allocator
- [ ] Registration writes a genesis movement row
- [ ] Forward A→B closes one leg and opens exactly one other, atomically
- [ ] `dm_document_open_unique` rejects a second open leg
- [ ] Double-submit returns 409, not two movements
- [ ] Every illegal transition in the matrix throws
- [ ] A User cannot reach an Admin route by direct URL (403)
- [ ] An Admin in Office A cannot read a document only Office B has handled
- [ ] A `role` field posted to `/login` changes nothing
- [ ] Mass-assignment cannot set `role` or `office_id`
- [ ] `Route::has('storage.local') === false`
- [ ] QR token is not derivable from the control number
- [ ] Both driver legs green

---

## Exit criteria

- [ ] **A1 answered in writing.** Deployment target and TLS confirmed, or camera
      scanning formally descoped and the wedge-scanner path agreed
- [ ] **A3 answered in writing.** Fixed pipeline, or a re-quote raised
- [ ] All eight migrations run clean on **both** pgsql and mysql
- [ ] `document_movements` carries its final column shape; registration writes a
      genesis row even though nothing reads it yet
- [ ] `document_files` has `version` from migration one
- [ ] Uploads on the private disk. No `public/storage` symlink. Ever
- [ ] `.env.example` and `.github/workflows/tests.yml` exist
- [ ] Three role-distinct sidebars with §4's exact labels; each role lands on its
      own panel after login, via all four Fortify response contracts
- [ ] A document routes Office A → Office B with `departed_at` and `arrived_at`
      persisted atomically
- [ ] A printed label, taped to a real folder, scans on a real phone against the
      real host — or the wedge scanner path demonstrated instead
- [ ] `composer ci:check` green
