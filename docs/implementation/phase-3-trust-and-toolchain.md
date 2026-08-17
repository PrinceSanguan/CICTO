# Phase 3 — Trust & Toolchain

> **Versioning, signatures, reports, security, backup.**
> PHP 3,000 · features #10, #12, #15, #19, #20

Prerequisite reading: [`00-architecture.md`](00-architecture.md).

## Goal

This phase groups the features whose risk is **toolchain and expectation**, not
logic. Nothing here is algorithmically hard. What is hard is that PDF generation,
Excel export and database dumping all shell out to things a shared LGU host may not
have — and that "digital signature", "encrypted" and "automated backup" all mean
more to a client than PHP 700, 500 and 400 can buy.

Run the host capability probe **before** writing any of it:

```bash
php -r 'var_dump(function_exists("proc_open"), ini_get("disable_functions"), class_exists("ZipArchive"));'
which pg_dump mysqldump && pg_dump --version && crontab -l
df -h ~ && du -sh ~/storage
```

## Demo at the end of this phase

Emarie re-uploads a corrected file and sees version 2 become current while version 1
stays downloadable. She signs an approved document by drawing her signature, prints
the one-page Signature Certificate, and scans its QR to see a public verification
page confirming it. She opens Reports, sees monthly volume and status distribution
charts against ten months of seeded data, and exports both to PDF and Excel. She
clicks **Run Backup Now**, sees the run recorded with its size and checksum — and
then watches a restore actually happen.

---

## 1. Version Control (#10, PHP 500)

The schema shipped in Phase 1. This is behaviour and UI only.

- `document_files` rows are **immutable and append-only** — no `updated_at`, no soft
  deletes, `unique(document_id, version)`, generated ULID filenames
- Version allocation reuses the document `lockForUpdate()` from `TransitionDocument`
- A SHA-256 dedupe short-circuits identical re-uploads before writing a blob
- Current file is `latestOfMany('version')`
- Every version downloads through the **one** policy-gated route with
  `->scopeBindings()` — no per-version restriction

> Omitting `->scopeBindings()` lets a file id from another office's document
> resolve, so the policy authorizes against the wrong parent document. This is the
> whole reason the route takes both `{document}` and `{file}`.

**Storage growth is the real issue.** Every version kept forever, nothing prunes.
At 150 documents/day this reaches roughly 105 GB in year one and forces a hosting
migration inside 24 months.

> Shared cPanel **inode** caps (200k–500k files, counting `vendor/` and
> `node_modules/`) break the app *before* the disk quota does, and the symptom is
> baffling — sessions failing to write.

Recommended retention, **pending client question B6**: keep v1 and the current
version forever; purge intermediate versions 180 days after the document reaches
Completed or Rejected, keeping the metadata row with `purged_at` set and returning
410 on download. Ship the pruner `--dry-run` by default and **disabled** until B6
is answered and an off-site backup exists (D-note in `00-architecture.md` §10).

Store the `disk` name per file row now, so moving to S3 later is a backfill
command rather than a refactor.

---

## 2. Digital Signatures (#12, PHP 700)

### Send this before building

> **Hi Emarie — before I build the digital signature feature, I want to make sure
> we are picturing the same thing. What the system will do is let an authorised
> person sign a document inside CICTO by drawing or typing their signature, and it
> will record who they are, their office and position, the exact date and time, and
> the exact file they signed. It also takes a fingerprint of that file, so if anyone
> changes or replaces the file afterwards, CICTO will show that the signature no
> longer matches — that is the part that protects your office. What it will not do
> is print the signature onto the pages of the PDF or scanned document you
> uploaded, because those files come from outside the system and cannot be reliably
> edited; instead, every signed document gets a one-page Signature Certificate you
> can print and attach, with a QR code that anyone can scan to check the signature
> online. It also does not use the government's own digital certificate service
> (PNPKI), so if a document ever needs a signature certified for court or for a
> national agency, that would be separate work with its own cost. If you would
> prefer the signature printed on the document itself, please tell me now and I will
> price it, because it changes the design.**

Send it, get a reply, keep the reply. This is the largest expectation gap in the
contract.

### What is built

An **electronic signature**, not PKI. No certificate authority, no certificate
chain, no PAdES embedding, no RFC 3161 timestamp authority. PNPKI is the named
out-of-scope upgrade path.

| Element | Implementation |
| --- | --- |
| Signer identity | FK to `users`, plus a denormalised name/position/office snapshot — a signature must survive the signer's later rename or transfer |
| Capture | **Drawn HTML canvas with pointer events, ~70 lines, zero npm packages.** Typed name kept as a second `method` value for mouse-only desktops; canvas is the default tab |
| Binding | FK to `document_file_id` — the *exact* version — plus a snapshot of its `checksum_sha256` |
| Integrity | `signature_hash` is a plain SHA-256 over a versioned canonical payload string, **not** an HMAC keyed on `APP_KEY` — that would widen the `APP_KEY` blast radius already flagged in B2 |
| Anti-double-sign | `unique(document_file_id, signer_id, purpose)`. "Superseded" is derived from version, never stored |
| Identity assurance | Signing routes gated by `password.confirm` middleware — the cheapest thing that makes "identify the party" credible under **RA 8792 §8** |
| Image storage | PNG on the private `documents` disk with magic-byte validation, served only through a policy-gated controller with `nosniff` |

**Tampering is detectable, not preventable.** A nightly `signatures:verify` sweep is
what turns "detectable" into "detected" — without it, nobody finds out until
someone looks.

### The signature is not stamped onto the PDF

Stamping would break the checksum binding it exists to protect, and free FPDI
cannot parse PDF 1.5+ object streams — which is most modern PDFs and most scanner
output.

Instead: a one-page **Signature Certificate** PDF via dompdf (already budgeted for
#15), plus a public `/verify/{serial}` page reached by a QR code from
`bacon/bacon-qr-code` (already installed in Phase 1). The verify page is
`throttle:30,1`, resolves on an unguessable ULID serial, and shows control number,
signer, purpose, timestamp and a verdict badge — **no document contents, no
download link.**

Verify dompdf's SVG rendering on the real host early. If the QR renders wrong, fall
back to printing the URL and serial as text; the certificate still works.

---

## 3. Reports and Analytics (#15, PHP 900)

§19 names **four** report artifacts. Three are obvious and one is routinely
forgotten:

1. Monthly documents processed
2. Status distribution
3. Document processing trend
4. **User activity** — derivable from `document_movements.actor_id`, but it belongs
   to no other feature, so it gets discovered missing during acceptance of a
   PHP 900 line item. Build it: movements grouped by actor, filtered by date range

Portable aggregate rules are in [`phase-4-close-out.md`](phase-4-close-out.md) §2
and `00-architecture.md` §5 — `EXTRACT` not `DATE_FORMAT`, group by full
expressions, cast every aggregate, `visibleTo($user)` on every query.

> `avg()` returns `numeric` on PostgreSQL and `decimal` on MySQL, and PDO hands both
> back as **strings**. Sorting or comparing them untyped produces wrong report
> ordering.

Status distribution renders as a **horizontal bar chart, not a pie** — four
categories with wildly different magnitudes are unreadable as a pie.

### Charts

`npx shadcn@latest add chart`, which wraps **recharts**. Pin `recharts@2.15.4`:
recharts 3.x breaks shadcn's `chart.tsx`, and an unpinned reinstall months later
silently breaks tooltips. Reuse the existing `--chart-1..5` tokens. Lazy-load the
chart bundle — recharts is ~95 KB gzipped and does not belong in the main bundle on
an LGU connection.

### PDF export — `barryvdh/laravel-dompdf:^3.1`

`spatie/laravel-pdf` is disqualified: it needs Node **and** headless Chromium
**and** `proc_open` on the server.

> ⚠️ dompdf cannot parse `oklch()` colours, flexbox or grid. Tailwind 4's theme is
> built on all three, so **the PDF Blade views cannot share the app's stylesheet.**
> A hand-written hex/table stylesheet is mandatory, and it will drift from the UI.
> Budget for that; it surprises people.

Set `isRemoteEnabled = false`.

### Excel export — `openspout/openspout:^4.28`

Streaming XLSX with `chunkById(500)`. `maatwebsite/excel` is rejected — it needs
`php-zip` and `php-gd` and is memory-hungry on shared hosting. Keep a hidden
`?format=csv` for when someone just needs the rows.

### Exports are synchronous, with hard caps

No queue worker exists, and a queued export that never runs gives the user no file
*and* no error. So: synchronous streamed downloads, with a **422 before generation**
above 1,000 rows for PDF and 25,000 for XLSX.

> Synchronous PDF export is memory-bound and will 500 somewhere around 1,500–2,000
> table rows on a 256 MB shared plan. The cap is an estimate until measured on the
> real host — **measure it.** And `set_time_limit()` is ignored by some shared
> hosts, so the XLSX ceiling may be lower in production than in staging.

---

## 4. Security and Encryption (#19, PHP 500)

§21 is one sentence, so make it a checklist the client can sign against.

| # | Item | Status |
| --- | --- | --- |
| 1 | Documents on a **private** disk, never `public/` — no `storage` symlink | Phase 1 |
| 2 | **Flip `config/filesystems.php` local disk to `serve => false`** — it is still `true`, registering a live `GET /storage/{path}` route that bypasses `DocumentPolicy` | ⚠️ outstanding |
| 3 | Every download policy-gated, `->scopeBindings()`, and audited | Phase 1/3 |
| 4 | `app_settings.setting_value` **unconditionally** encrypted via Laravel's `encrypted` cast on a TEXT column — no per-row `is_encrypted` flag to forget. This is where SMTP credentials will live | Phase 3 |
| 5 | Never encrypt anything searchable (D10) | — |
| 6 | `SESSION_ENCRYPT=true` (database session driver), `SESSION_SAME_SITE=lax` | Phase 3 |
| 7 | `SESSION_SECURE_COOKIE=true` — **deferred to the hour TLS is confirmed.** Setting it optimistically breaks login over plain HTTP; leaving it off means session cookies travel in the clear over LGU wifi | blocked on A1 |
| 8 | Security headers, CSP **Report-Only for one week**, then enforced. No `unsafe-inline` in production | Phase 3 |
| 9 | `Password::defaults()` = `min(12)->mixedCase()->numbers()`. **Drop `uncompromised()`** — it silently fails open with no outbound HTTPS | Phase 3 |
| 10 | Rate limiting beyond Fortify defaults: the scan route, the verify route, exports | Phase 1/3 |
| 11 | Upload validation via **both** `File::types()` and `->extensions()`; SVG excluded | Phase 1 |
| 12 | "Transmitted securely" = **HTTPS, which is a hosting fact somebody must own.** Not a code deliverable | ⚠️ client |

### What "encrypted storage" honestly means

There is no encrypting Flysystem adapter in Laravel. `Crypt` on file bytes doubles
peak memory, breaks streaming and breaks inline PDF preview. §21 ships as **private
disk + policy-gated + audited access** — say so in writing rather than letting
"encrypted storage" be heard as at-rest file encryption.

> **Correction, 11 Aug 2026.** An earlier draft of this section promised
> "encrypted backups", and `config/cicto.php` carried a `backup.passphrase` key
> to match. **Nothing encrypts the backup archive.** `cicto:backup` produces a
> plain ZIP holding the SQL dump and the uploaded documents; the passphrase key
> was read by no code and has been removed. The archive contains every document
> the LGU holds, so it must be treated as confidential in transit and at rest —
> that is an operational control (restricted destination, filesystem
> permissions), not something the application provides. If real encryption is
> required, it is a separate piece of work and needs its own key-management
> answer alongside the APP_KEY escrow.

### RA 10173 (Data Privacy Act)

Logging `ip_address` and `user_agent` in `document_scans` creates real obligations.
Minimum viable compliance:

- A short **privacy notice** page stating what is collected, why, and how long
- **180-day retention** on `document_scans`, enforced by a scheduled prune and
  stated in the notice
- A named contact for data subjects — the LGU's Data Protection Officer, not the
  developer

`security_events` (the D1 amendment) lands here: one narrow table, closed enum
vocabulary, pre-rendered summary column, no JSON. Most rows come free from one
event subscriber; role changes, user CRUD and settings are a few explicit calls.

---

## 5. Backup and Recovery (#20, PHP 400)

§22 is titled Backup **and Recovery**. A backup nobody has restored is not a
recovery plan.

### Do not install `spatie/laravel-backup`

> This **supersedes** the provisional package choice in `00-architecture.md`.

Its core value is the shell-out dumper we may not be able to use, plus destinations
we cannot afford and a notification stack we do not need. One first-party
`backup:run` command with a `Dumper` interface and two implementations is roughly
150 lines, works in both branches of the probe, and is restorable by someone
reading a runbook rather than package documentation.

### Decision tree — from the host probe

| Question | Yes | No → fallback |
| --- | --- | --- |
| **Cron?** | One line: `*/5 * * * * cd /path && php artisan schedule:run` — which also solves the missing queue worker via `Schedule::command('queue:work --stop-when-empty --max-time=240')->everyFiveMinutes()` | **"Automated regular backups" is not met.** Backups become a human clicking *Run Backup Now*. Say this plainly, in writing: the spec word *automated* is not delivered and no code change can deliver it. Mitigation is a calendar reminder plus a red staleness banner — not automation |
| **`proc_open` enabled?** | `ShellDumper` | `PhpDumper` |
| **`pg_dump`/`mysqldump` present, version ≥ server's?** | `ShellDumper` | `PhpDumper`. A client older than the server is a hard refusal from `pg_dump`, not a warning — check versions, not just presence |
| **Off-site budget?** | S3-compatible bucket (Backblaze B2 / Wasabi) as a second Flysystem disk | Backups land on the **same disk as the documents — which is not a backup.** One disk failure loses both. Fallback: weekly manual download to a machine the LGU controls, recorded in `backup_runs` |

`PhpDumper` emits **data, not DDL** — `DB::table($t)->cursor()` per table (set
`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false` on MySQL or you will OOM), quoted
through `DB::getPdo()->quote()` into `gzopen($path, 'wb9')`. Restore is therefore
`migrate` **then** load, which is why `backup_runs.last_migration` exists and why a
dump is only valid against its own migration set.

Archive with `ZipArchive::EM_AES_256` and a passphrase from `app_settings`. If
libzip is too old for AES, fall back to plain gzip, set `is_encrypted = false`, and
**show that honestly in the UI** rather than implying encryption that is not there.

Schedule: nightly database-only, weekly full. Stop bundling document files once
storage passes 5 GB.

`backup_runs.restored_at` drives a persistent red banner until one restore drill
has actually been recorded.

### Restore runbook

Print it. Tape it inside the server cabinet. Someone who is not the developer will
use it at 21:00.

1. **Stop writes** — `php artisan down --secret=<token>`
2. **Confirm `APP_KEY`** — must equal the escrowed value. If it does not, **stop**;
   restoring encrypted settings under a different key produces silent garbage
3. **Fetch the archive** and verify `sha256sum` against `checksum_sha256`. A
   mismatch means a corrupt copy — use the previous run
4. **Extract** — `unzip cicto-<stamp>-<suffix>.zip -d /tmp/restore`. The archive
   holds `database/` (one `.sql` or `.sql.gz`) and `documents/` (every uploaded
   file). It is **not encrypted** — see the note below. A run whose `kind` is
   `database` rather than `full` has **no** `documents/` directory; skip step 6
   and read the warning there.
5. **Restore schema and data** — shell dump: pipe straight in. PHP dump:
   `php artisan migrate --force` **first**, then load. If `last_migration` is older
   than the current code, check out the matching commit before migrating
6. **Restore files** — `rsync -a /tmp/restore/documents/ storage/app/documents/`.
   If the run was `kind = database`, there is nothing to restore here and the
   uploaded files are gone unless they were copied off-host separately. Every
   download will 410 and the nightly sweep will report every signature as
   failing, because it re-hashes bytes that no longer exist.
7. **Reconcile schema** — `php artisan migrate --force`
8. **Clear caches** — `php artisan optimize:clear`
9. **Verify, do not assume** — `php artisan cicto:verify-signatures`; row counts for
   `documents` and `document_movements` against the pre-incident figure; open one
   document and download its file; confirm exactly one `is_open = 1` row per live
   document
10. **Record it** — mark the run restored, write what happened in `restore_notes`
11. **Resume** — `php artisan up`
12. **New host?** Do steps 1–4, restore `.env` from escrow *before* step 5, and
    re-point `APP_URL` — passkeys derive their relying-party ID from it and will
    silently stop working otherwise

### `APP_KEY` escrow — a procedure, not a warning

Losing `APP_KEY` permanently destroys every encrypted `app_settings` value and every
encrypted backup. There is no recovery.

1. At go-live, copy `APP_KEY` from the host's `.env`
2. Store it in **two** places: the LGU's password manager, and a printed sheet in a
   sealed envelope held by the Municipal Records Officer or Treasurer
3. Record the escrow in the signed handover document, with date and holder's name
4. Confirm no backup archive contains `.env` in cleartext alongside the encrypted
   payload it unlocks
5. **Deploys use `composer install --no-scripts`. Never `composer setup` on the
   host** — `setup` runs `artisan key:generate` and destroys everything above in one
   command. Put that sentence in the deploy README, in bold
6. Rotation is out of scope. If the key must change, every encrypted setting is
   re-entered by hand and every prior backup becomes unreadable

### What PHP 400 does not buy

Off-site storage has a recurring cost the contract does not cover. Neither does
monitoring, nor a tested disaster-recovery SLA. Raise both — see **B2**.

---

## Tests that must exist

- [ ] Re-upload creates version 2 and version 1 stays downloadable
- [ ] An identical re-upload does not write a second blob
- [ ] A file id from another document 404s on the download route (`scopeBindings`)
- [ ] Signing binds to the current `document_file_id` and its checksum
- [ ] Replacing the file makes the signature verify as **mismatched**
- [ ] The same signer cannot sign the same file version twice for the same purpose
- [ ] `/verify/{serial}` exposes no document content and is throttled
- [ ] `Route::has('storage.local') === false`
- [ ] `app_settings` values round-trip through the `encrypted` cast
- [ ] Every report query is scoped by `visibleTo`
- [ ] Report aggregates return identical numbers on **both** drivers
- [ ] Export above the row cap returns 422, not a 500
- [ ] `PhpDumper` output restores into an empty database after `migrate`
- [ ] Both driver legs green

---

## Exit criteria

- [ ] **B1 answered** — the signature paragraph sent and a reply kept
- [ ] **B2 answered** — host probe run, decision tree resolved, off-site settled
- [ ] **B4 answered** — real `.xlsx` or CSV
- [ ] **B6 answered** — retention agreed, before any pruner is enabled
- [ ] `local` disk flipped to `serve => false`
- [ ] Signature Certificate prints correctly **on the real host**
- [ ] All four §19 report artifacts exist, including User Activity
- [ ] PDF and Excel export measured against real data on the real host
- [ ] A backup has been **restored** at least once, with `restored_at` recorded
- [ ] `APP_KEY` escrowed and the escrow signed
- [ ] The §21 checklist above signed off by the client
- [ ] `composer ci:check` green on both driver legs

---

## QA record — adversarial review, 10 Aug 2026

Five independent reviewers raised 28 findings; each was then handed to
verifiers instructed to refute it. **11 survived.** All 11 are fixed, and every
one carries a regression test that fails without its fix.

The five worth remembering, because each was invisible to the green suite that
preceded them:

**A signature could be obtained before the content existed.** `file` is
nullable at registration, and nothing stopped an admin signing a document with
no attachment. The row stored `document_hash_sha256 = null`, `fileHashMatches()
short-circuited to `true`, and `isSuperseded()` returned false because there was
no file to compare versions against. Attach any PDF afterwards and the
certificate printed "fingerprint: —" directly above a green *"the signed file
still matches the fingerprint recorded above"* — permanently, to anyone scanning
the QR. Signing a fileless document is now refused in `DocumentPolicy::sign`,
and a legacy null-file signature reports superseded the moment any file exists.

**The signature hash did not cover the signer's identity.** `signer_name`,
`signer_position` and `signer_office` are what the certificate prints, but they
sat beside the hash rather than inside it — one `UPDATE` produced a certificate
naming a different person that still verified as valid. They are in the
canonical payload now, which is why `PAYLOAD_VERSION` is `v2`.

**Verification never read the file.** `fileHashMatches()` compared two database
columns, so replacing the bytes on disk and leaving the row alone was
undetectable — the exact attack the feature exists to catch. The nightly sweep
now calls `isValid(rehashBytes: true)` and re-hashes from disk; page renders
still use the cheap comparison.

**Every export over 500 rows was silently truncated.** `lazyById(500,
'documents.id')` left the alias qualified, so the second chunk threw *after* the
200 and the headers had been flushed. Any office with more than 500 documents
got a short CSV, a corrupt XLSX, and no error. Single-document export tests
passed throughout.

**A restored PostgreSQL database was broken on first write.** PostgreSQL does
not advance a sequence when a row is inserted with an explicit id, so every
sequence sat at 1 after a data-only restore and the first new document collided
with id 1. Proven by drill: restore the same dump with the `setval` lines
stripped and registering a document fails with `duplicate key value violates
unique constraint "documents_pkey"`; with them, it gets id 6.

Also fixed: re-uploading an older file (a **revert**) deduped against the wrong
version and silently kept the file the user had just replaced; re-signing a
version was a raw 500 that orphaned a PNG; `gzwrite`/`gzclose` failures recorded
a truncated dump as Completed; `mysqldump`'s password sat in argv where `ps`
reads it; a post-completion throw deleted a good backup; two backups in the same
second shared a filename; the document page ran N+1 over signatures and
comments; and an enforced CSP would have killed both the theme script and the
label Print button (now nonced, with reports going to the `csp` log channel).

**Found during the smoke test, not by the reviewers:** `/documents/labels` —
any non-numeric id — returned **500 on PostgreSQL only**. SQLite and MySQL
coerce `where id = 'labels'` to 0 and 404; PostgreSQL raises 22P02. A URL typo
or a crawler was a server error in production and nowhere else. Numeric route
keys are now constrained to `[0-9]+`.

### Verified

- **179 tests green on all three drivers** — SQLite, PostgreSQL 17, MySQL 8
- PHPStan level 7 (0 errors), Pint, ESLint, Prettier, `tsc --noEmit` all clean
- A real **restore drill** on PostgreSQL: dump → `migrate:fresh` → load → all
  rows back → a new document registers as id 6
- Authenticated HTTP smoke test: dashboard, document list, document detail,
  reports, label print, settings all 200; nonce present and per-request

---

## Client UI designs applied — 10 Aug 2026

Two supplied mockups were implemented: the **Scan QR Code** console and the
**Admin Panel**.

### Scan QR Code

Blue gradient sky, the existing `Skyline` component reused from the landing
page, floating paper/pin/cloud motifs, the dashed green viewfinder with its
sweeping laser line, a flashlight toggle, and the illustrative phone.

The camera now genuinely scans. Two decoders, chosen at runtime:

- **`BarcodeDetector`** when the browser has it — native, zero bytes, and the
  path Android Chrome takes, which is what a field user actually holds.
- **`jsQR`**, imported lazily, only when the native API is missing. It lands in
  its own 128 KB chunk that Safari and Firefox users fetch and nobody else does.
  Verified absent from both the entry bundle and the scan page's own chunk.

Three behaviours are deliberately unchanged, because they were load-bearing:

1. **The USB keyboard-wedge scanner is still the default.** The cursor starts in
   that box and returns to it, and it needs no permission, no HTTPS and no
   decoder. Focus is *not* stolen back while the camera is the active path,
   where it would pop the on-screen keyboard over the viewfinder.
2. **Camera access still requires HTTPS.** `getUserMedia` refuses to run on
   insecure origins; on a LAN deployment at `http://192.168.x.x` there is no
   workaround. The page says so instead of offering a button that cannot work.
3. **Nothing in the illustration is a scannable code.** Both decorative QR
   graphics are patterned, not encoded — a real code inside a "point your camera
   here" illustration is a trap.

The camera is released before the scan callback runs, so navigating away never
leaves the recording indicator lit.

### Admin Panel

Blue gradient sidebar (applied through the `--sidebar-*` tokens, so the header,
menu, footer and mobile sheet all move together), Logout in the sidebar, the
"Admin Panel" title rule, four coloured stat tiles, and the searchable register
with row actions and pagination — plus the Reports chart and Pending Document
table from the second mockup, as sections of the same scrolling page.

**The four tiles are an interpretation and are documented as one.** The client
names Total / Pending / Approved / Rejected over a six-state workflow:

| Tile | Workflow states |
| --- | --- |
| Pending | `initiated`, `under_review`, `returned` |
| Approved | `approved`, `completed` |
| Rejected | `rejected` |

`approved` and `completed` are one bucket deliberately — a completed document
was approved first, and splitting them would make the Approved tile read lower
than the number of approvals the office actually made. A test asserts the three
buckets sum to the total, so no state can fall through the gaps unnoticed.

Tiles are clickable filters, and clicking the active one clears it.

**One deviation from the mockup, flagged for the client.** The design shows the
sort control defaulting to "Date Updated". The default here is **Priority
(urgent first)**, because this table is also §4's office work queue and
`AdminQueueOrderTest` pins that ordering — `priority` is a string column, so a
plain `ORDER BY` sorts `high` below `low` and buries urgent work. Date Updated,
Date Submitted and Title are all available in the dropdown. Say the word and the
default flips.

### Two portability bugs caught while building this

Both were invisible on SQLite and would have reached production:

- **`EXTRACT` does not exist on SQLite.** The trend query now carries whole
  literal strings per driver, matching `DocumentStats`.
- **`pluck(DB::raw('count(*)'))` names its column differently per driver.**
  MySQL and SQLite name it after the expression; PostgreSQL names it `count`,
  and the mismatch was a 500 on the one driver production runs. Aliased
  explicitly now.

### Verified

- **187 tests green on SQLite, PostgreSQL 17 and MySQL 8** (7 new for the panel)
- PHPStan level 7, Pint, ESLint, Prettier, `tsc --noEmit` all clean
- Live against the running app: tiles, tile filters, case-insensitive search,
  all four sorts, pagination, and the 12-month trend all move correctly with
  real workflow transitions; the buckets sum to the total
- recharts and jsQR both confirmed out of the entry bundle
- One CSP nonce per request, shared across all 23 script tags on the page

**Not verified: appearance.** Chrome could not reach the local dev server in
this environment on any URL that `curl` serves fine, so every check above is
HTTP- and data-level. The pages need a visual pass in a real browser.

---

## Auth screens rebuilt to the client's design — 10 Aug 2026

The Fortify screens were still the untouched starter kit: a dark centred card
with the Laravel logo. All six now share one CICTO shell —
`layouts/auth/auth-simple-layout.tsx` — covering login (all three portals),
register, forgot password, reset password, verify email, the two-factor
challenge and password confirmation.

Blue gradient, floating stationery, the pale ground band, the cloud bank, the
white card with the horizontal CICTO lockup, and the "Welcome to CICTO Document
Tracking System" panel with the woman-and-laptop illustration.

**Both illustrations were already in the repo.** `hero-woman-laptop.png` is the
exact figure from the mockups and `cicto-baliwag-logo.png` is the client's real
artwork — nothing was redrawn or sourced elsewhere.

### Decisions worth knowing about

**The Super Admin portal is red.** That is not decoration. §3 gives that role
system-wide access across every office, and someone who reaches that URL by
accident should be able to tell before they type a password.

**The logo is cropped, not redrawn.** The supplied asset is a square, vertically
stacked lockup (mark above "CICTO" over "BALIWAG"); the mockups show a
horizontal one. `CictoLockup` crops the mark out of the real artwork and sets
the text beside it, driven by a single `MARK_FRACTION` constant. Re-drawing the
client's mark by hand would put a not-quite-right logo on the first screen
anyone sees.

**Three things in the mockups were deliberately not followed:**

1. **The passkey button stays.** The mockups omit it, but passkeys are built,
   tested, and the only sign-in path here that cannot be phished. Hiding the
   button would not remove the feature, only bury it.
2. **Register keeps the Name field.** The mockup shows email and password only.
   `name` is NOT NULL on `users`, and it is what the audit trail and every
   Signature Certificate print — a register full of `user@example.com` instead
   of a person's name is not a municipal record.
3. **Reset Password keeps its email field read-only.** The reset token is issued
   against that exact address, so an editable field could only produce a token
   mismatch and an error the user cannot act on.

**Unchanged, because it is the security model:** the three portals are three
URLs rendering one page against one guard and one POST target. `portal` is
presentation only — never posted, never reaching `Auth::attempt`, never
influencing authorization. Signing in at `/login/admin` with a clerk account
lands on the clerk dashboard rather than being rejected, because refusing the
mismatch would tell an attacker which addresses belong to admins.
`AccessControlTest` already pins this.

> **Superseded 2026-08-17.** The client asked for the three chips to go, and
> because the portal decided nothing there was nothing to unpick: the chips,
> the prop and the red Super Admin theme were deleted, and the two role URLs now
> redirect to `/login`. The security model above is intact and still pinned —
> `AccessControlTest` now asserts the login page ships **no** role hint, which
> is the same claim stated as an absence. See `00-architecture.md` §3.

### Verified

- **187 tests green on SQLite, PostgreSQL 17 and MySQL 8**; PHPStan level 7,
  Pint, ESLint, Prettier and `tsc --noEmit` all clean
- All five public auth routes return 200 and render the right component with
  the right `portal` prop (`user`, `admin`, `super-admin`)
- Both illustration assets confirmed bundled and referenced

**Not verified: appearance.** The browser automation could not reach the dev
server on any port, while `curl` served the same URLs fine — an extension
network limitation, not an application fault. These six screens need a visual
pass. Note for whoever does it: port 8000 is occupied by an unrelated project on
this machine, which is what made an earlier check appear to 404; use another
port.

---

## Auth screens: the shell vanished, and the audit that followed

The rebuilt login page shipped broken — a bare form stacked on a black
background, no card, no gradient, no logo. The cause was one character-level
mistake with an outsized blast radius, and it is worth writing down.

### `Page.layout = {}` silently deletes the layout

Inertia reads `Page.layout` three ways: a component, a **named-layout map**, or
a **props object** for the default layout. Classification happens in
`isPropsObject()`, which excludes anything `isNamedLayouts()` accepts — and
`isNamedLayouts()` ends in `Object.values(value).every(...)`.

`[].every()` is **vacuously true**. So `{}` is classified as a named-layout map
containing no layouts, `normalizeLayouts()` returns an empty list, and the page
renders with no shell at all. No error, no warning.

It was set to `{}` because the login heading varies by portal and a static
layout object cannot. The fix is to **omit the property entirely** —
`layoutValue` is then `undefined` and correctly falls through to the default
layout. Proven by running Inertia's own predicate:

| `Page.layout` | shell renders |
| --- | --- |
| omitted | YES — falls back to the default |
| `{}` | **NO — layout list resolves empty** |
| `{ title: 'Register' }` | YES — treated as layout props |

`InertiaLayoutContractTest` now greps every page for `.layout = {}`, because
that grep costs milliseconds and the symptom costs an afternoon in the wrong
stylesheet.

### The audit

Five reviewers over independent lenses, every finding handed to a refute-by-
default verifier: **42 raised, 32 survived** (heavy overlap — roughly 16
distinct defects). All fixed.

**The theme was the real one.** `initializeTheme()` runs unconditionally at
module scope in `app.tsx`, so it re-applied dark on the auth screens after the
Blade guard had deliberately opted them out — and `applyTheme()` writes an
inline `color-scheme: dark` on `<html>` that no class change can undo. Since
`color-scheme` is inherited and drives browser-painted surfaces, a dark-OS
visitor got **black autofilled email and password fields inside the white
card**. The permanent `prefers-color-scheme` listener then re-darkened the page
live if the OS theme changed.

Patching the React effect would have lost the same race again. Instead there is
now one flag: Blade stamps `data-force-light` on `<html>` before first paint,
`applyTheme()` honours it, and the auth layout only toggles the flag on
client-side arrival. Every path — first paint, `initializeTheme`, the media
listener, client-side navigation — now reads the same source of truth.

**Also fixed:** the password reveal toggle was unreachable by keyboard (WCAG
2.1.1 Level A); positive `tabIndex` values pulled the login controls ahead of
everything else in the tab order; validation errors were never associated with
their fields (`aria-describedby` / `aria-invalid`) and status banners were not
live regions; the Super Admin red failed contrast at 4.09:1 (now 5.1:1) and the
link blue at 4.37:1; Register's name field used the shared `Input`, so its text
sat 32px to the left of the others in a smaller font (there is a matching
`TextField` now); the "Super Admin" chip wrapped to two lines; the card had no
max-width between `md` and `lg`, stretching to 991px then snapping to 480px; the
illustration floated above the ground band at a size that changed per screen;
motifs were positioned against the viewport rather than the content column; the
brand lockup was announced twice to screen readers; and overscroll exposed white
above the gradient.

`overflow-hidden` on the shell became `overflow-x-hidden` — the Y clip would
have made the Register form unreachable below its own height.

### Verified

- **191 tests green on SQLite, PostgreSQL 17 and MySQL 8**; PHPStan level 7,
  Pint, ESLint, Prettier, `tsc --noEmit`, and a production build all clean
- All five public auth routes 200; `data-force-light` and `color-scheme: light`
  present on auth and the landing page, absent on the signed-in app
- New tests pin the layout contract and the colour-scheme opt-out, and the
  layout-contract test was confirmed to fail when the bug is reintroduced

**Still not verified: appearance.** The browser automation cannot reach the dev
server on any port while `curl` serves the same URLs — an extension limitation,
not an application fault. Note for whoever looks: **port 8000 is occupied by an
unrelated project on this machine**, which is what made one earlier check appear
to 404. Use another port.

---

## Track / View / Submit rebuilt to the client's designs — 10 Aug 2026

Three more mockups implemented: **Track Documents**, **View Documents** and
**Submit Document**.

### The navigation question is now settled

Every user-facing mockup supplied so far — Scan QR, Track, View, Submit — puts
§4's main navigation (Home, Track Documents, Reports, Help + a red Logout)
across the **top**. Only the Admin Panel design uses a sidebar.

So there are now two shells, and the split matches the designs exactly:

- `AppTopLayout` — documents, reports, help, notifications. White nav bar, blue
  gradient body, city skyline.
- `AppLayout` (sidebar) — the Admin and Super Admin panels, which have their own
  menus and their own design.

Both read their items from `navFor()`, the same source, so the two can never
drift apart. Navigation stays a hint and never a gate: a link hidden in either
shell is still refused with a 403 if the URL is typed.

### A real inconsistency the design surfaced

`publicLabel()` collapses six workflow states into the four labels the client
names — Pending, In Process, Rejected, Completed — but `tone()` was still
per-state. Two documents both reading **"Pending"** rendered in different
colours (`initiated` slate, `returned` orange), and two both reading "In
Process" in two more.

A colour legend that shows one label in two colours is worse than no colour: it
reads as a distinction that does not exist. There is now a `publicTone()` paired
with `publicLabel()`, and `StatusPresentationTest` asserts each label has
exactly one colour AND that the four do not collapse onto one.

### Other decisions

**`longestStage` is new server-side.** The View design asks for it; it is
derived from the movement ledger rather than stored, and the OPEN leg counts —
a document sitting in one office for three days is exactly what a records
officer is looking for, and excluding it would hide the live problem while
reporting a finished one.

**The four-stage stepper covers six states.** `returned` reports against Under
Review and `rejected` against the point where it was decided, rather than
inventing a fifth box. The status pill carries the real answer.

**The character counters show the server's real limits** (5000 description,
2000 remarks), not the mockup's `0/500`. A counter that says 500 while the rule
allows 5000 either wastes the field or reads as a hard limit the user fights.

**The dropzone wraps a real `<input type="file">`** rather than replacing it.
The input keeps its name and is what the form posts, so keyboard users, screen
readers and browsers without drag support all still get a working picker.

**Nothing was deleted from the View page.** The design covers status tracking
only; the Actions, Version Control, Signatures and Comments sections are
billable §9/§14/§15 work and remain below the tracking card, restyled as white
cards. §13's per-office dwell table is kept in a disclosure — the design has no
place for it, but it answers "which office is slow", the question §1 says the
client actually has.

### Verified

- **193 tests green on SQLite, PostgreSQL 17 and MySQL 8**; PHPStan level 7,
  Pint, ESLint, Prettier, `tsc --noEmit` and a production build all clean
- Live against the running app: Track (12 rows, real control numbers, offices,
  labels and tones), View (stepper, `longestStage` computing `3h 1m` at the
  Mayor's Office, timeline, tracking metrics), Submit (11 offices, 9 document
  types, 4 priorities), plus scan, reports, help and the admin panel all 200
- Each public label confirmed to render in exactly one colour at runtime

**Not verified: appearance.** Browser automation still cannot reach the dev
server while `curl` serves the same URLs. These three screens need a visual
pass. Port 8000 on this machine belongs to an unrelated project — use another.

---

## Mobile QA — 10 Aug 2026

I could finally **see** the pages. The browser-automation tooling still cannot
reach the dev server, so this pass drives the cached headless Chrome shell over
the DevTools protocol instead: every page at 375, 390, 768 and 1280, with
horizontal-overflow measurement and a PNG per combination. Harness and output
are in `docs/qa/`.

It immediately found things that source review had not.

**Track Documents and the Admin Panel were unusable on a phone.** Their tables
sit inside `overflow-x-auto`, so no measurement flagged them and no test failed
— but at 375px only the first two columns were visible, with nothing indicating
the rest existed. Status and the **View button**, the entire point of a row,
were off-screen. Below `md` a row is now a card: labelled fields and a
full-width action. Fixed for Track, both Admin tables, and the shared
`DocumentTable` (which fixes the user and super-admin dashboards at once).

**The View screen clipped its own content.** A fixed 160px label column with the
value inline pushed the control number, title and timestamp off the right edge.
The list stacks below `sm` now.

**The scan console rendered a double skyline and a double gradient.** Moving it
into the top-nav shell left its own copies of both in place. Removed.

**Reports and Help were unreadable on the new shell.** Both were built for the
grey sidebar; on the blue gradient their transparent cards and muted-grey labels
vanished into the background. Both now use white cards.

**The Admin Panel overflowed by 11px at 375px.** Gone.

**Primary buttons were near-black.** The starter kit's `--primary` put a black
"Comment" button inside an otherwise blue card. It is the brand blue now, which
is what every mockup shows.

### Verified

- **Zero horizontal overflow on every page at every viewport** — Track, Submit,
  View, Admin, Scan, Reports, Help, Dashboard, Login (all three portals),
  Register
- **193 tests green on SQLite, PostgreSQL 17 and MySQL 8**; PHPStan level 7,
  Pint, ESLint, Prettier, `tsc --noEmit` and a production build all clean
- 40 screenshots kept in `docs/qa/screenshots/` as a reference for the next pass

### Still worth a human eye

Screenshots are not the same as holding a phone. Touch-target sizes, real
scrolling behaviour, the on-screen keyboard covering a focused field, and iOS
Safari's dynamic viewport are all things this harness cannot judge.
