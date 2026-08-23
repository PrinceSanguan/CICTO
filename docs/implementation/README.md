# CICTO — Phased Implementation Plan

Working plan for **CICTO — Web-Based Document Tracking System with QR Code**, derived
from the signed *Software Development Agreement* and its *Project Documentation*
(functional specification), both dated in
`CICTO-Service-Contract-and-Documentation-Emarie.pdf` at the repository root.

- **Client:** Emarie
- **Developer:** Prince Sanguan, Student Web Solutions
- **Contract value:** PHP 12,000.00 (50% down / 50% on delivery)
- **Contract timeline:** 2 weeks from receipt of down payment
- **Scope rule (§24):** the functional specification is a *strict* reference.
  Anything not listed in it is out of scope and requires a separate quotation.

## How this folder works

One document per phase. Each phase document is self-contained: the features it
delivers, the schema it adds, the files it creates, the tests that must pass, and
the exit criteria that let you call it done. Work them in order — the ordering is
load-bearing, not cosmetic.

| File | Purpose |
| --- | --- |
| [`00-architecture.md`](00-architecture.md) | Binding technical decisions, full data model, portability rules. Read before writing any code. |
| [`phase-1-foundation-and-routing.md`](phase-1-foundation-and-routing.md) | Document → QR → scan → routed between offices |
| [`phase-2-workflow-and-trail.md`](phase-2-workflow-and-trail.md) | Approval, status, notifications, audit trail, deadlines |
| [`phase-3-trust-and-toolchain.md`](phase-3-trust-and-toolchain.md) | Versioning, signatures, reports, security, backup |
| [`phase-4-close-out.md`](phase-4-close-out.md) | Search, dashboard, archive, help, handover |
| [`client-questions.md`](client-questions.md) | Questions to answer **in writing** before/while building |

## Why this order

The contract is fixed-price over two weeks. That makes the scheduling question
*not* "what is worth the most?" but **"if this assumption is wrong, how much built
work dies, and how late do we find out?"**

So both PHP 1,200 line items — Workflow Management (#5) and QR Code Tracking (#17)
— land in **Phase 1**, alongside the document core they depend on. QR risk in
particular is environmental rather than technical: browser camera scanning requires
an HTTPS secure context, and no deployment host is confirmed yet. That is a
discovery you want on day 2, when a phone call still fixes it, not on day 9.

Everything in Phase 2 is a read of, a write to, or a trigger on the movement ledger
Phase 1 creates. Phase 3 holds the features whose risk is *toolchain* rather than
logic (PDF/Excel binaries, backup shelling out to `pg_dump`). Phase 4 is
deliberately the smallest and adds **zero database schema** — which makes it the
only safe thing to compress if the calendar slips.

## Feature → phase map

All 20 billable line items, each appearing exactly once.

### Phase 1 — Foundation & Routing · PHP 4,500

| # | Feature | PHP |
| --- | --- | ---: |
| 1 | Document Registration | 500 |
| 2 | Document Upload | 500 |
| 3 | Document Classification | 400 |
| 5 | Workflow Management (incl. routing between offices) | 1,200 |
| 11 | Role-Based Access Control | 700 |
| 17 | QR Code Tracking | 1,200 |
| | **Subtotal** | **4,500** |

Also absorbs unpriced §3 (Authentication entry points) and §4 (Navigation).

### Phase 2 — Workflow & Trail · PHP 3,100

| # | Feature | PHP |
| --- | --- | ---: |
| 6 | Status Tracking | 500 |
| 7 | Approval Management | 700 |
| 8 | Notifications and Alerts | 500 |
| 9 | Document History (Audit Trail) | 500 |
| 13 | Comments and Collaboration | 400 |
| 18 | Due Date and Deadline Monitoring | 500 |
| | **Subtotal** | **3,100** |

### Phase 3 — Trust & Toolchain · PHP 3,000

| # | Feature | PHP |
| --- | --- | ---: |
| 10 | Version Control | 500 |
| 12 | Digital Signatures | 700 |
| 15 | Reports and Analytics | 900 |
| 19 | Security and Encryption | 500 |
| 20 | Backup and Recovery | 400 |
| | **Subtotal** | **3,000** |

### Phase 4 — Close-Out · PHP 1,400

| # | Feature | PHP |
| --- | --- | ---: |
| 4 | Search and Filter | 500 |
| 14 | Dashboard | 500 |
| 16 | Archive Management | 400 |
| | **Subtotal** | **1,400** |

Also absorbs unpriced §23 (Help & Support).

**Total: PHP 12,000.00** ✓

## Spec section coverage

The cost breakdown has 20 rows; the functional specification has 24 sections. They
do not map one-to-one, so this is where the unpriced sections land.

| Spec § | Title | Phase | Note |
| --- | --- | --- | --- |
| 1 | Overview | — | Context, not a deliverable |
| 2 | User Roles | 1 | Realised inside #11 as `users.role` + `users.office_id` + policies |
| 3 | Authentication & Registration | 1 | **Unpriced.** Fortify already ships login, register, forgot/reset, email verification, plus 2FA and passkeys the spec never asked for. The only real gap is "separate login entry points", which is the RBAC post-login redirect. **Read the forgot/reset half against client question B3:** CICTO will not supply SMTP, and asked for an administrator-set-password module on Manage Users in its place — which is built and is still the route for anyone who cannot receive mail. Since **2026-08-23** the operator has supplied Google SMTP themselves, so the emailed reset genuinely sends, and email verification is genuinely enforced — `User` implements `MustVerifyEmail` now, which it did not before. |
| 4 | Navigation | 1 | **Unpriced.** Three role-specific sidebars with the exact labels §4 names |
| 5 | Document Registration & Upload | 1 | #1 + #2 |
| 6 | Document Classification | 1 | #3 |
| 7 | QR Code Tracking | 1 | #17 |
| 8 | Search and Filter | 4 | #4 |
| 9 | Workflow & Approval Management | 1 (routing) / 2 (approval) | #5 then #7 |
| 10 | Status Tracking | 2 | #6 |
| 11 | Due Date and Deadline Monitoring | 2 | #18 |
| 12 | Notifications and Alerts | 2 | #8 — **in-app only.** A mail service exists as of 2026-08-23, and it changes nothing here: email notification was a change order when there was no mailer and stays one now. B3 got a mailer, not a scope increase |
| 13 | Document History (Audit Trail) | 2 | #9 |
| 14 | Version Control | 3 | #10 |
| 15 | Digital Signatures | 3 | #12 — see the expectation-setting note in Phase 3 |
| 16 | Comments and Collaboration | 2 | #13 |
| 17 | Role-Based Access Control | 1 | #11 |
| 18 | Dashboard | 4 | #14 |
| 19 | Reports and Analytics | 3 | #15 |
| 20 | Archive Management | 4 | #16 |
| 21 | Security and Encryption | 3 | #19 |
| 22 | Backup and Recovery | 3 | #20 |
| 23 | Help & Support | 4 | **Unpriced and specified.** See `client-questions.md`. |
| 24 | Notes & Exclusions | — | The scope rule itself |

## Starting point

**This section records where the build began, not where it stands.** The repository
started as a **Laravel Chisel starter kit with zero domain code**; the offices,
document types, ledger, panels, seeders and help content described in the phase
documents are in the tree now. What the starter kit gave us:

- Laravel 13 · PHP 8.3 · Inertia 3 · React 19 · Tailwind 4 · TypeScript · Vite ·
  Wayfinder (typed routes) · shadcn/ui (new-york, neutral)
- Fortify authentication: login, register, forgot/reset password, email
  verification, two-factor, passkeys
- Settings pages: profile, security, appearance
- Five migrations: `users`, `cache`, `jobs`, `passkeys`, two-factor columns
- `routes/web.php` contains exactly two routes: the landing page and an empty
  `dashboard`

Everything in the 20-item cost breakdown was greenfield.

## Repository hygiene to fix in Phase 1

Three pre-existing defects, all cheap, all of which cause confusing failures later:

1. **`.env.example` has been deleted from the working tree** — it is still tracked
   in git (`git status` shows ` D .env.example`), so `git checkout -- .env.example`
   restores it. Until then `composer.json`'s `setup` script runs
   `file_exists('.env') || copy('.env.example', '.env')` against a file that is not
   there, and a fresh clone silently gets no environment. Note the tracked version
   says `DB_DATABASE=cicto` (lowercase) while the live local database is `CICTO`.
2. **`.github/workflows/tests.yml` does not exist**, but `docs/DATABASE.md:97`
   states "CI runs the same matrix on every push". It does not. There is no
   `.github` directory.
3. **`phpunit.xml:28` pins `DB_CONNECTION=sqlite`** with `:memory:`. That is a
   *third* driver beyond the two `docs/DATABASE.md` requires — and it silently
   no-ops `SELECT … FOR UPDATE`, which the control-number allocator depends on.

## Definition of done, every phase

```bash
DB_CONNECTION=pgsql php artisan migrate:fresh && DB_CONNECTION=pgsql php artisan test
DB_CONNECTION=mysql php artisan migrate:fresh && DB_CONNECTION=mysql php artisan test
composer ci:check      # pint --test · phpstan · eslint · prettier · tsc --noEmit · artisan test
```

Both database legs are load-bearing. The lowercase-search scope, the `Duration`
SQL expressions and the `notifications` index lengths each pass on one driver and
fail on the other.

## Schedule reality

**Read this before quoting a delivery date.**

The contract allows 2 weeks — 10 working days. This plan, costed honestly against
the design in the phase documents, is **19–23 developer-days for one person.** That
is 2× to 2.3× over, before any client feedback latency.

| Stage | Realistic days | What the money implies |
| --- | ---: | --- |
| Phase 0 — repo hygiene, `.env.example`, CI matrix, local pgsql **and** mysql, seed reference data | 1 | unbudgeted |
| Phase 1 | 5–6 | ~3.5 |
| Phase 2 | 4–5 | ~2.5 |
| Phase 3 | 5–6 | ~2.5 |
| Phase 4 | 2–3 | ~1 |
| Deployment, UAT, revisions, training | 2 | unbudgeted |
| **Total** | **19–23** | **10** |

Phase 1 alone exceeds what PHP 4,500 implies: the QR label and scan surfaces are a
full day, and the two-driver concurrency test is another half. Phase 3 carries
dompdf's hand-written stylesheet, streaming XLSX, and a backup path that branches on
host capability.

The plan's own compression lever — Phase 4, zero schema — buys back 2–3 days
against a 9–13 day overrun. **It is not sufficient on its own.**

### Two honest paths

**(a) Re-phase the delivery.** Commit to Phases 1 and 2 inside the two weeks as a
demonstrable milestone — that is a document that registers, prints a QR, routes
between offices, gets approved, notifies staff and shows a full audit trail, which
is a genuinely impressive demo — and schedule Phases 3 and 4 into a stated week 3–4.
Agreed in writing, now, not in week two.

**(b) Hold the two weeks and formally de-scope.** The honest candidates are #12
Digital Signatures, #19 Security & Encryption and #20 Backup & Recovery — the three
with the largest gap between what the words promise and what PHP 1,600 combined can
deliver.

What should **not** happen is attempting all twenty features in ten days. The
failure mode is not "late" — it is a movement ledger designed three different ways
because there was no time to reconcile it.

> Deployment is where the HTTPS, cron and `proc_open` discoveries detonate. If those
> land in the last two days there is no budget left to absorb them. That is the
> single strongest argument for answering **A1** and **B2** in
> [`client-questions.md`](client-questions.md) on day one.

## Commercial note

The plan is built to protect a fixed-price engagement: risky assumptions surface
first, each phase ends in something the client can click through and sign off, and
the last phase can be compressed without breaking a foreign key.

The items most likely to be misunderstood — digital signatures, "encryption",
backup on shared hosting, QR scanning hardware, and the unpriced Help & Support
section — are all in [`client-questions.md`](client-questions.md). Get them
answered in writing before they become disputes.
