# Phase 2 — Workflow & Trail

> **Everything that reads or writes a movement.**
> PHP 3,100 · features #6, #7, #8, #9, #13, #18

Prerequisite reading: [`00-architecture.md`](00-architecture.md) and
[`phase-1-foundation-and-routing.md`](phase-1-foundation-and-routing.md).

## Goal

With the ledger proven and routing demonstrated, build the six features that are
individually straightforward and collectively worthless without it.

Approval (#7), Status Tracking (#6), Audit Trail (#9), Due Dates (#18) and
Notifications (#8) are all writes to, reads of, or sweeps over the movement rows
Phase 1 created. Sequencing any of them earlier would have meant guessing at a
table that did not exist.

Comments (#13) rides with Approval because §9's "return with remarks" and §16's
comments are literally the same panel — building them apart yields two competing
remark models.

**The audit trail must be written as transitions happen, not retrofitted.** §13's
"how long it stayed at each stage/office" is only recoverable from timestamps
captured at write time.

The concentrated risk in this phase is infrastructure, not code: `MAIL_MAILER=log`
means nothing can be delivered, and `QUEUE_CONNECTION=database` has no worker.

## Demo at the end of this phase

A three-account walkthrough of the two problems §1 says the client actually has.

Emarie submits a document as a clerk. She logs in as the receiving Admin, finds it
in that office's queue, leaves a remark, rejects it once, has it returned and
resubmitted, then approves it — and only now does **Send to Another Office**
appear. She forwards it, and the next office's staff see a notification bell with
an unread count.

She opens the document as Super Admin and reads its history: who touched it, what
they did, and how many hours it sat at each of three offices. A seeded document
past its window is flagged overdue; another is flagged as approaching.

---

## 1. Migrations

Two, and they are the only schema this phase adds.

### `create_document_comments_table`

Per `00-architecture.md`, comments and approval remarks are **one table**.

Approving with a remark writes both rows in one transaction: the comment
(`context='approval'`, `document_movement_id` set) and the movement's `remarks`
holding the same text. The comment is what the panel renders; the movement's
`remarks` is the immutable ledger copy. Approval comments have no edit route
(policy denies `update` when `context !== 'comment'`), so they cannot diverge — and
a test asserts `$movement->remarks === $movement->comment->body`.

### `create_notifications_table` — hand-written

> ⚠️ This is **not** Laravel's standard notifications table, and that is
> deliberate. The stock migration uses `$table->morphs()` (which emits
> `varchar(255)` and blows the MySQL 5.7 utf8mb4 767-byte key limit **on the
> client's host, not in CI**) and a `json` data column (whose querying compiles
> differently per driver — a `docs/DATABASE.md` caution).

```php
Schema::create('notifications', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('document_id')->constrained()->cascadeOnDelete();
    $table->foreignId('document_movement_id')->nullable()->constrained()->nullOnDelete();

    $table->string('type', 32);            // App\Enums\NotificationType
    $table->string('dedupe_key', 64);      // 'forwarded:1841' | 'overdue:1841:2026-08-09'
    $table->string('title', 191);          // rendered at write time
    $table->string('body', 255)->nullable();
    $table->string('control_number', 32);  // denormalised — the dropdown needs zero joins
    $table->timestamp('read_at')->nullable();
    $table->timestamps();

    $table->unique(['user_id', 'dedupe_key'], 'notifications_user_dedupe_unique');
    $table->index(['user_id', 'read_at', 'id'], 'notifications_user_unread_idx');
});
```

Real foreign keys instead of a morph. Title and body rendered at write time instead
of a JSON payload. `dedupe_key` at 64 chars is 264 bytes on MySQL 5.7 utf8mb4 —
safely inside the limit — and its unique index is what makes the sweep idempotent.

> **`User` needs a change.** It currently uses `Illuminate\Notifications\Notifiable`,
> whose `HasDatabaseNotifications` defines a `morphMany` against a table of the same
> name with an incompatible shape. Use `RoutesNotifications` only, and declare a
> plain `hasMany(Notification::class)`.

---

## 2. Approval Management (#7, PHP 700)

Approve, reject and return are the same `TransitionDocument` call from Phase 1 with
a different `MovementAction`. No new engine.

- **Approve** → `under_review` → `approved`. Only now does **Send to Another
  Office** become available, per §9
- **Reject** → terminal
- **Return with remarks** → `returned`, back to the previous office for correction

Remarks are mandatory on reject and return, optional on approve. Enforce in the
FormRequest, not the controller.

The React button set is generated from `DocumentWorkflow::allowed($status)` — the
same const map that guards the server. A button that is not in the map does not
render, and a request for an action not in the map throws. One source of truth.

> **Client question A6** *(answered 2026-08-18 — it became a setting, not a rule)*.
> Can an Admin approve a document they submitted themselves? The natural
> separation-of-duties rule blocks it; in a two-person municipal office that blocks
> real work. The client's note reads "they can allow or block it naman daw" — they
> expect to make this call themselves, and to change it later. So it did not ship as
> one line in `DocumentPolicy::approve()`. It shipped as a **Super Admin toggle** on
> `/super-admin/settings`, stored in `app_settings` under the key
> `workflow.allow_self_approval` and resolved by
> `App\Support\SystemSettings::allowSelfApproval()`, which falls back to
> `config('cicto.workflow.allow_self_approval')` when no row exists.
> `DocumentPolicy` consults that one method. Blocking self-approval remains the
> shipped default, and flipping the toggle writes a `SecurityEvent`.
>
> **Still open: which way the LGU wants it set.** That is the whole remainder of A6,
> and it is now a click during handover rather than a deployment.

---

## 3. Comments and Collaboration (#13, PHP 400)

One panel on the document view, rendering `document_comments` in chronological
order alongside the movement timeline.

- `context` discriminates `comment` / `approval` / `rejection` / `return`
- `is_internal` hides staff-only notes from the submitting user
- Only `context='comment'` is editable, and only by its author, within a window
- `parent_id` supports one level of reply — do not build threading beyond that

---

## 4. Status Tracking (#6, PHP 500)

§10 requires four things on the document view: current stage, how long it has been
at the current office, which office holds it, and the expected completion window.

All four come from the open leg plus `documents`:

| §10 requirement | Source |
| --- | --- |
| Current stage | `documents.status` → client-facing label (see `00-architecture.md` §2) |
| Which office holds it | The open leg's `to_office_id` |
| How long it has been there | `now() - openLeg.arrived_at`, computed in **PHP** with Carbon |
| Expected completion window | `documents.due_at` + the `dueState` accessor |

The list-view query resolves the holding office with a single `leftJoinSub` on the
open leg, with explicit NULL-last ordering — `ORDER BY` null placement differs
between the drivers.

> Compute the single open leg's elapsed time in **PHP**, not SQL. There is at most
> one open leg per document, so there is nothing to aggregate, and PHP keeps
> `Carbon::setTestNow()` working in tests. SQL duration arithmetic is only for
> *cross-document averages* — see the `Duration` helper.

---

## 5. Document History / Audit Trail (#9, PHP 500)

The ledger already holds it. This feature is the read model and the UI.

Per-document timeline: every leg, in `sequence` order, showing actor, action,
from-office → to-office, remarks, arrival, departure, and dwell. Under ~20 legs per
document, so hydrate and format in PHP — no SQL date arithmetic on this path.

Per-office rollup:

```php
$byOffice = $document->movements()
    ->closed()
    ->with('toOffice')
    ->get()
    ->groupBy('to_office_id')
    ->map(fn ($legs) => [
        'office'  => $legs->first()->toOffice->name,
        'legs'    => $legs->count(),
        'minutes' => $legs->sum(fn (DocumentMovement $m) => $m->dwellMinutes()),
    ])
    ->values();
```

Create `App\Support\Database\Duration` in this phase (see `00-architecture.md` §5).
Phase 3 reports and the Phase 4 dashboard consume it. Two tests must exist:

1. Identical dwell totals **on both drivers** — seed a three-office document with
   fixed timestamps via `Carbon::setTestNow()` and assert exact minutes
2. Null dwell for the open leg — the folder in your hand has not finished sitting
   anywhere

### What the ledger does *not* cover

Logins, failed logins, lockouts, role changes, user CRUD, settings changes and file
downloads have no document. They go to `security_events` in Phase 3 — see the D1
amendment. **`file.downloaded` never goes in `document_movements`**; reads must not
pollute the custody timeline.

---

## 6. Due Date and Deadline Monitoring (#18, PHP 500)

### Where the date comes from

`documents.due_at` = registration time + `document_types.turnaround_days`, in
**calendar days** (D18), clamped to end-of-business — which is now **18:00**, raised
from 17:00 on 2026-08-18 because the client confirmed the working day runs 7:00 AM to
6:00 PM. The hour lives in `cicto.deadlines.business_end_hour`, with the same value
repeated as the inline fallback in `App\Support\Deadlines`; change both or neither.
Written **once**, inside the registration transaction, and immutable thereafter
except by a Super Admin override — so the window quoted to a citizen never silently
shifts.

`document_movements.due_at` is the per-leg office SLA, written by
`TransitionDocument` on every open-leg insert and clamped to never exceed the
document's overall `due_at`. NULL on terminal legs.

**Priority does not shorten the due date.** It is a sort key and a badge. Making
priority alter the SLA means two sources of truth for one number; if the client
wants it, it is a config delta map defaulting to zero, agreed in writing.

> **Working days are not in scope at PHP 500.** Philippine national and local
> holidays change annually by proclamation, so a working-day engine needs a
> holidays table seeded every year — and an unseeded table *silently* falls back to
> calendar days and reports wrong SLAs, which is worse than not offering it. Tell
> the client plainly: calendar days now, working days as a separate quote.
>
> **That conversation got harder on 2026-08-18.** The client confirmed the week is
> **Monday to Thursday**, 7:00 AM to 6:00 PM — the four-day week on the supplied
> design was real, not a typo for Friday. Calendar days therefore run the clock
> straight through a three-day weekend: a 3-day turnaround filed on a Thursday falls
> due on **Sunday**, with the counter shut since Thursday evening and nobody back
> until Monday. Raising `business_end_hour` to 18 does not touch this. It sets the
> hour a deadline lands on; it cannot say which days exist. Say it out loud before
> sign-off, rather than letting it arrive as an overdue badge nobody could have
> acted on.

### Overdue is a predicate, not a flag

```php
#[Scope]
protected function stillOpen(Builder $q): void
{
    $q->whereNull('completed_at')->whereNotIn('status', DocumentStatus::terminalValues());
}

#[Scope]
protected function overdue(Builder $q): void
{
    $q->stillOpen()->whereNotNull('due_at')->where('due_at', '<', now());
}

#[Scope]
protected function approachingDeadline(Builder $q): void
{
    $q->stillOpen()->whereNotNull('due_at')
      ->where('due_at', '>=', now())
      ->where('due_at', '<=', Deadlines::warnBoundary());
}
```

`now()` is **PHP-bound** (D14) — never SQL `NOW()`, which would break both
portability and `Carbon::setTestNow()`.

The **approaching** threshold is a fixed **2 calendar days**, from
`config/cicto.php`. A fixed number keeps the predicate one bound datetime; a
percentage of turnaround would need a per-row computed comparison.

> ⚠️ **Known interaction, found during implementation.** Any document type whose
> `turnaround_days` is *less than or equal to* this threshold is flagged "due
> soon" from the moment it is registered, which makes the badge meaningless for
> that type. The first build used 3 days against a 3-day MEMO turnaround and
> every memo was born amber.
>
> Keep the threshold below the shortest real turnaround. This is a live dependency
> on client question **A4**, and the 2026-08-18 answer made it *more* live, not
> less. The client supplied the 43 real document types but not their turnaround
> days — that half of A4 went to the City Archive and Records Office and has not
> come back — so `DocumentTypeSeeder` seeds `turnaround_days` **NULL** on every row
> and `App\Support\Deadlines` falls back to
> `cicto.deadlines.default_turnaround_days`, **3 calendar days**
> (`CICTO_DEFAULT_TURNAROUND_DAYS`), for all 43 of them.
>
> So state it plainly: right now every type carries a uniform 3-day turnaround
> against a 2-day warning window, which means **every document in the system goes
> amber roughly one day after registration** and the badge separates nothing from
> nothing. That is not a bug to fix in code — it is the provisional SLA showing
> through, and it clears the day ARO returns real per-type numbers. Until then, do
> not let anyone read the amber count as a workload signal.

One `Deadlines` class emits both the SQL boundary and the PHP comparison, and a
`dueState` accessor gives the badge its value with no extra query — so the list
badge, the sweep and the dashboard widget cannot disagree.

---

## 7. Notifications and Alerts (#8, PHP 500)

§12's four triggers: newly assigned, pending, overdue, and forwarded to your
office.

### Fan out per user, not per office

One row per **active member of the receiving office**, with the actor excluded.
Mark-as-read is inherently personal — an office-level row cannot express "Maria
read it, Jose did not" without a second pivot table, which costs more than the rows
it saves. At LGU office sizes this is tens of rows per transition, not thousands.

### Synchronous dispatch

`QUEUE_CONNECTION=database` with no worker means anything queued **silently never
runs** — the worst failure mode, because it looks like success.

Dispatch from a listener implementing `ShouldHandleEventsAfterCommit`: one indexed
recipient `SELECT` plus one bulk insert. Two round trips, single-digit
milliseconds. Not a `foreach` of individual inserts.

> After-commit placement is **mandatory**, not stylistic: on PostgreSQL a listener
> firing inside the transaction can read rows that are about to be rolled back.

Wrap the whole subscriber in `try`/`catch` with `Log::error`. **A notification
failure must never roll back or 500 a forward action.** The document movement is
the business record; the notification is a convenience.

### Pending and overdue need a sweep

Those two are *states*, not events, so `cicto:notify-deadlines` sweeps them —
idempotent, chunked with `chunkById`, guarded by `documents.deadline_warned_at` and
`overdue_notified_at` timestamps plus the `dedupe_key` unique index.

**Without cron:** the Overdue filter, the badge and the dashboard counts all stay
correct, because overdue is a live query predicate (D17). What is lost is only the
*push* — nobody gets told without opening the app. Say that plainly to the client
rather than discovering it at handover.

### The bell UI

- Unread **count** in shared Inertia props — one indexed `COUNT`, on every page
- The 8-row **dropdown list** comes from a separate on-demand JSON endpoint, so it
  stays off every page payload
- `GET /notifications/{notification}/go` marks read, then redirects — one click, no
  race, and no role branching, because the document URL is role-identical by design
- A full index page with mark-one and mark-all read

### Email

It cannot send today. §12 is delivered **in-app only** in this phase. Do not build
an untestable `Mailable`. When SMTP arrives it goes on a cron-driven queue, never
synchronously — and it is a change order, not an absorbed revision. See client
question **B3**.

> **2026-08-20 — B3 came back, and this paragraph survives it intact.** CICTO will
> not supply SMTP credentials; an LGU may stand up Google SMTP on its own account.
> If one does, `MAIL_MAILER=smtp` makes notification email *possible* and does not
> make it *included*. Every word above still applies to the day somebody asks for
> it: queued, not synchronous, and quoted. Say that when the mailer is configured
> rather than when the first "why didn't I get an email about my document" arrives
> — a working mailer is exactly the moment §12 email starts looking free.

---

## Tests that must exist

- [ ] Approve → `Send to Another Office` becomes available; before approval it does not
- [ ] Reject and return **require** remarks
- [ ] A returned document can be resubmitted and re-approved
- [ ] Approving writes both a movement and a comment, with identical text
- [ ] An approval comment cannot be edited
- [ ] Identical dwell totals on **both** drivers with fixed timestamps
- [ ] Open leg reports null dwell
- [ ] Forwarding notifies every active member of the receiving office except the actor
- [ ] Running the deadline sweep twice creates no duplicate notifications
- [ ] A notification failure does not roll back the movement
- [ ] `overdue` and `approachingDeadline` agree with the `dueState` accessor
- [ ] `Carbon::setTestNow()` moves a document from on-track to overdue with no data change
- [ ] Both driver legs green

---

## Exit criteria

- [ ] **A6 set in writing** — the mechanism shipped 2026-08-18 as the Super Admin
      toggle `workflow.allow_self_approval`, defaulting to blocked. What is still
      needed is the LGU stating which way they want it, so the toggle is set
      deliberately rather than left at our default by accident
- [ ] **B3 answered** — email in scope, or §12 acknowledged as in-app only
- [ ] The three-account walkthrough runs end to end without touching the database
      by hand
- [ ] The document history shows who, what, and how long at each office, for a
      document that has passed through three offices
- [ ] Overdue and approaching badges are correct with cron **disabled**
- [ ] No second log table exists — `document_movements` is still the only document
      audit trail
- [ ] `composer ci:check` green on both driver legs
