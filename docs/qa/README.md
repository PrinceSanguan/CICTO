# Visual QA

## Why this exists

The browser-automation tooling in this environment cannot reach the local dev
server, so for several sessions the UI work was verified only at the data and
HTTP level — "the props are right" rather than "the page looks right". That is
not verification of a design.

`screenshot-viewports.mjs` closes the gap. It drives the headless Chrome shell
over the DevTools protocol, loads every page at four viewports, measures
horizontal overflow, and writes a PNG per page per width.

It found things no amount of reading class names would have:

- **Track Documents and the Admin Panel were unusable on a phone.** The tables
  fit inside `overflow-x-auto`, so nothing reported an error — but at 375px only
  the first two columns were visible, with no affordance saying the rest
  existed. The Status badge and the **View button**, which is the entire point
  of a row, were simply gone.
- **The View screen clipped its own content.** A fixed 160px label column with
  the value inline pushed control numbers, titles and timestamps off the right
  edge.
- **The scan console rendered a double skyline and a double gradient** after it
  moved into the top-nav shell, which already provides both.
- **Reports and Help were unreadable.** Both were built for the grey sidebar
  shell; on the blue gradient their transparent cards and muted-grey labels
  disappeared into the background.
- **The Admin Panel overflowed by 11px at 375px.**

## Running it

```bash
php artisan serve --port=8099          # port 8000 belongs to another project
# grab an authenticated session cookie however you like, then:
QA_PAGES='[{"name":"track","path":"/documents"}]' \
  node docs/qa/screenshot-viewports.mjs docs/qa/screenshots "<cicto-session cookie>"
```

Pass `none` as the cookie to shoot the signed-out pages (login, register).

Viewports: 375 (iPhone SE), 390 (iPhone 14), 768 (tablet), 1280 (laptop).

The `overflow` column is the one to watch. Anything other than `none` is a
horizontal scrollbar on a phone. The `offenders` column lists elements wider
than the viewport — a table inside a deliberate `overflow-x-auto` container will
appear there and is fine, so read it alongside the overflow figure.

## The mobile rule this established

**Below `md`, a data table becomes a list of cards.** Every row's fields are
labelled and its primary action is a full-width button. The table still renders
from `md` up, where it reads better.

This is implemented in `components/documents/document-cards.tsx` (Track),
`AdminCards` in `pages/admin/dashboard.tsx`, and the shared
`components/documents/document-table.tsx` — the last of which fixes the user and
super-admin dashboards at once.

Screenshots in `screenshots/` are the state at the end of the 10 Aug 2026 pass.
They are a reference, not a fixture: nothing asserts against them.

---

## Whole-system QA — 11 Aug 2026

Eight independent review lenses (authorization, ledger, portability,
signatures/backup, frontend, operations, consistency, destructive), every
finding handed to a refute-by-default verifier. **82 raised, 68 survived.** The
register is `FINDINGS.md`; 15 are fixed and ticked, 53 remain.

Alongside it, three things were exercised for real rather than reasoned about:

- **`journeys.sh`** — 60 end-to-end assertions through real HTTP against a clean
  PostgreSQL database: sign-in for all three roles, role separation, every page, public
  routes, bad input, the full register → receive → approve → complete →
  archive → restore lifecycle, all three export formats, and cross-office
  isolation. All 60 pass.
- **Concurrency** — eight simultaneous transitions on one open leg, and six
  simultaneous archives. Exactly one winner each time, one open leg, unique
  sequences.
- **Backup and restore, both dumpers** — shell and PHP, each restored into a
  scratch database, ending by registering a new document to prove the
  PostgreSQL sequences were advanced.

### The two criticals

**Backups did not contain the document files.** `cicto:backup` wrote a SQL dump
and nothing else, while the restore runbook the application itself points at
told the operator to `unzip` an archive and `rsync` a `documents/` tree that no
code ever produced. On any disk loss the database would restore perfectly and
describe files that no longer exist — every download 410, and the nightly sweep
declaring the entire signed record set tampered, permanently. Backups now
produce one archive containing the dump *and* every uploaded file, recorded as
`kind = full`; a host without ZipArchive or with a remote disk falls back to
`kind = database` and says so on the row, in `cicto:host-check`, and in the
Super Admin panel.

**Self-service account deletion rewrote the audit trail.**
`document_movements.actor_id` is `nullOnDelete`, so deleting a user turned every
"Forwarded by Maria Santos" into "Forwarded by nobody" — retroactively, with no
record it had ever said otherwise. For anyone who had registered or signed a
document `documents.created_by_id` is `restrictOnDelete`, so instead the delete
threw, *after* `Auth::logout()` had already run. Closing an account now
deactivates it whenever it has touched a record, and the ordering is fixed.

While fixing it: the logout event writes a `security_events` row referencing the
user, so on the genuinely-empty path that insert fired *after* the delete,
violated the foreign key, and the failed statement rolled back the enclosing
savepoint — silently resurrecting the account the user had asked to remove.

### Also fixed

The **toast channel was dead end to end** — `HandleInertiaRequests` never shared
the session key and the hook listened for a router event Inertia does not emit,
so every "Document registered", "Signed", "Archived" and "Backup complete"
confirmation in the application was written and discarded unread.

The **public verify page reported a swapped file as valid**: it compared two
database columns, which a byte swap leaves untouched. It re-hashes now — and
that immediately exposed a second bug, an eager-load column list too narrow to
hold the path the re-hash needs.

Plus: Inertia DevTools explicitly disabled (its unauthenticated endpoint replays
recorded page payloads); backup failures before the dump now leave a record
instead of vanishing; `ShellDumper` no longer fatals when `shell_exec` alone is
disabled; public registration is throttled; the false "encrypted backups" claim
removed from config and docs; and `cicto:user` added, because there was no HTTP
surface to assign a role or an office — following the runbook produced a system
with exactly one usable account.

### What is still open

53 verified findings, listed in `FINDINGS.md`: 2 high, roughly 30 medium and 21
low. The two remaining highs are `PhpDumper` taking no consistent snapshot, and
the backup writing through a raw filesystem path (which puts the dump in the web
root if the backup disk is not local). Neither is fixed and both are real.

The largest theme in the mediums is **operational honesty on the target host** —
retention pruners that are never scheduled while the privacy notice states the
periods as fact, password reset claiming success under `MAIL_MAILER=log` while
writing the working token into the log, and long operations running
synchronously in web requests.
