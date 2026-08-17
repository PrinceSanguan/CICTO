# Questions to answer in writing

Contract §5 says minor revisions within the agreed scope are free, and anything
outside it is quoted separately. That clause only protects you if the boundary is
written down. Everything below is a place where the functional specification is
genuinely ambiguous, or where the client is likely to expect more than §-text
strictly promises.

**Get answers by email or chat and keep them.** Group A blocks Phase 1 and cannot
wait. Group B can be answered during Phase 1 but must be settled before the phase
that needs it.

---

## Group A — blocks Phase 1, ask on day 1

### A1. What does "via camera or scanner" mean? *(§7)*

Three different builds hide behind that phrase:

| Answer | What it costs | Hard requirement |
| --- | --- | --- |
| **Phone camera** | `@zxing/browser` + a React scanner page | **HTTPS on the deployment host.** `getUserMedia` refuses to run on plain HTTP outside `localhost` — this is a browser rule, not a setting |
| **USB keyboard-wedge scanner** | Effectively free — a focused text input | None. The scanner types the token like a keyboard |
| **Both** | Both of the above | HTTPS |

> This is the single most important question in the project. `APP_URL` is
> currently `http://localhost:8000` and no deployment host is confirmed. If
> camera scanning is expected and the host has no TLS, the headline feature of
> the contract — the one in its title — does not work, and you find out at
> deployment.

**Also confirm:** who is buying the scanner hardware, if any? It is not in the cost
breakdown.

### A2. Can an unauthenticated person resolve a scan?

A QR label taped to a folder can be photographed by anyone who handles it — a
courier, a walk-in citizen, someone in a corridor.

- **Yes, anyone can scan** → the scan page must show a *reduced* view (status,
  holding office, date) and never the title, file, remarks or history. §21 has to
  sanction this in writing.
- **No, staff only** → the scan redirects to login. A courier cannot confirm
  delivery.

There is no safe default here. Ask.

### A3. Is the workflow pipeline fixed, or configurable? *(§9 vs §2)*

§9 says stages are "e.g., Initiated → Under Review → Approved → Completed". §2 says
Super Admin "configures system settings".

- **Fixed pipeline** → what is planned and priced.
- **Super Admin can define stages per document type** → a configurable workflow
  engine, which is several times the PHP 1,200 line item and is a §5 re-quote, not
  an absorbed revision.

Answer this before the movement table ships.

### A4. The real office list and document types

Reference data seeded in Phase 1 and used in every dropdown, every route target and
every control number prefix (`MPDO-2026-00042`).

Needed: every office/department name plus a **short code** (≤ 32 chars), and the
document types the LGU actually uses — with the expected turnaround days for each,
which is what funds §11 deadline monitoring with no extra work.

### A5. Who creates accounts?

§3 offers public registration. On a municipal records system that is a policy
decision, not a technical one.

- **Public self-registration** → a stranger can create an account. Mitigated by
  quarantining new users with no office and no documents until an Admin assigns
  them, but the exposure is real.
- **Admin-created accounts only** → safer, and closer to how LGUs actually work.

### A6. Can an Admin approve a document they submitted themselves?

The natural separation-of-duties rule blocks it. In a two-person municipal office
that rule blocks real work. Confirm which behaviour is wanted — it is a one-line
policy change now and a support complaint later.

### A7. Multi-office sending — a route, not three custodies *(confirm in writing)*

On 2026-08-17 the client asked for "an option to select multiple offices at the
same time instead of sending the document one office at a time." That shipped as
a **routing list**: one submit, N offices, in the order you pick them. The folder
goes to the first office now and moves to the next automatically each time it is
approved, and the document page shows the whole route for the life of the
document.

**What it is not** is three offices holding the document simultaneously, and the
reason is not effort. §7 tracks a *physical folder* carrying one printed QR
label; one folder cannot be on three desks at once, and the public scan page a
courier reads has exactly one "Currently at" line to answer with. The ledger
enforces this in the database — `unique(document_id, is_open)`, decision D13 —
and every figure in §10, §13 and §19 is derived from it. A five-office route
therefore leaves a trail *indistinguishable* from five forwards typed by hand;
`RoutingTest::test_a_routed_document_leaves_the_same_trail_as_forwarding_by_hand`
pins exactly that.

Confirm the reading in writing. Two consequences the client should hear now
rather than discover in UAT:

1. **Queued offices cannot see the document until it reaches them.** Visibility
   is derived from the ledger, and no ledger row names them yet. That is
   deliberate, not a bug — but it will look like one to a tester who ticked five
   boxes and then asked office 4 to check.
2. **Deadlines are per document, not per stop.** `documents.due_at` is stamped
   once at registration and every leg is clamped to it, so a long route hands
   its later stops a deadline that may already have passed. That is pre-existing
   behaviour for any long chain; a route just makes long chains easy to create.
   If the client wants per-stop turnaround, that is a §11 change and a re-quote.

If the answer turns out to be "each office needs its own copy", that is **N
documents with N control numbers**, not one document in N places — a different
feature, adjacent to A3's configurable-workflow pricing.

---

## Group B — settle before the phase that needs it

### B1. Digital signatures — what exactly? *(§15, Phase 3, PHP 700)*

The gap between what "digital signature" means legally and what PHP 700 buys is
the largest expectation gap in this contract. See the ready-to-send paragraph in
[`phase-3-trust-and-toolchain.md`](phase-3-trust-and-toolchain.md) — send it before
building, not after.

Short version: what is being built is signer identity + typed or drawn signature +
timestamp + a SHA-256 hash binding the signature to the exact file version, so
tampering is detectable. What is **not** being built is PKI — no certificate
authority, no certificate chain, no revocation, no PAdES-embedded signature in the
PDF itself.

### B2. Backup — what host, and what counts as "automated"? *(§22, Phase 3, PHP 400)*

The fast way to dump a database is to shell out to `pg_dump`/`mysqldump`. LGU
shared hosting frequently disables `proc_open`/`exec` and ships no database client
binaries — so the backup command branches on what the host actually allows, and the
answers below decide which branch ships. (This is why no backup *package* is used;
see [`phase-3-trust-and-toolchain.md`](phase-3-trust-and-toolchain.md) §5.)

Confirm before Phase 3:

- What is the hosting? (shared cPanel / VPS / managed?)
- Is there **cron**? Without it "automated regular backups" cannot be automated.
- Is `proc_open` enabled? Are `pg_dump`/`mysqldump` installed?
- **Where do backups go?** Backups on the same disk as the documents are not
  backups. Off-site (S3, Google Drive, Dropbox) has a cost the breakdown does not
  include.
- Who restores, and who tests the restore?

> Also: `APP_KEY` becomes a first-class backup artifact. If someone re-runs
> `composer setup` on the host, `artisan key:generate` fires and **permanently
> destroys every encrypted column and every encrypted backup.**

### B3. Is email delivery in scope? *(§3, §12)*

`MAIL_MAILER=log` today — nothing sends. This affects two specified features:
email verification on sign-up (§3) and notifications (§12).

- Who provides SMTP credentials, and for what address?
- If there is no mail service, §3 email verification cannot function, and
  notifications must be in-app only. That is a scope reduction and should be
  acknowledged in writing.

### B4. What does "exportable to PDF and Excel" mean? *(§19, Phase 3)*

Most clients who say "Excel" mean "a file that opens in Excel". A CSV does that,
costs nothing, and never runs out of memory. A true `.xlsx` needs
`maatwebsite/excel`, which needs `php-zip` and `php-gd` and is memory-hungry on
shared hosting.

Confirm which, and confirm the expected report volume — the export approach has a
row-count ceiling.

### B5. Help & Support is specified but unpriced *(§23, Phase 4)*

§23 names three pages — Knowledge Base, Submit a Support Ticket, Contact Support —
and there is **no line item for any of them** in the 20-row cost breakdown.

§24 says the documentation reflects the final agreed scope, so it is arguably
included; the cost table says it was never priced. Raise it early and settle it in
writing.

The cheapest honest reading: static Knowledge Base content, a Contact Support page,
and a support form that emails rather than storing tickets in a database with an
admin queue. A stored-ticket model with an admin inbox is a separate feature.

### B6. Retention — how long do we keep things?

Nothing in the spec prunes anything, and two tables grow without bound:

- `document_files` keeps **every version forever.** At 200 documents/month
  averaging 2 MB with one re-upload each, that is roughly 10 GB in two years.
- `document_scans` grows fastest of all if couriers rescan repeatedly.

Agree a retention policy and a storage quota, or agree explicitly that there is
none and the client accepts the disk cost.

### B7. Portal mismatch behaviour *(RESOLVED 2026-08-17 — the portals were removed)*

The question was: a clerk who opens `/login/admin` and signs in successfully
lands on the **clerk** dashboard rather than being rejected. Deliberate —
rejecting would leak which accounts are admins.

The client answered it by removing the premise. On 2026-08-17 they asked for the
three "Login as" chips to go and for one login page that works the role out
afterwards, which is what it always did. There is one entry point now, so there
is no mismatch to behave one way or the other about. `/login/admin` and
`/login/super-admin` redirect to `/login`.

**This changes what §3 of the signed contract literally says** — "Separate login
entry points for User, Admin, and Super Admin". It is satisfied by the post-login
RBAC redirect, which is how `README.md`'s spec map already read it, but the
instruction should be confirmed in writing before sign-off so the wording is not
raised at acceptance.

---

## Standing scope note

Six things sit just outside the specification and will feel "obviously included"
to a client. Each is a §5 re-quote:

1. A configurable workflow engine (see A3)
2. Per-user or per-office granular permissions beyond the three fixed roles
3. Stored support tickets with an admin queue (see B5)
4. True cryptographic/PKI signatures (see B1)
5. Encryption of document bytes at rest — there is no encrypting filesystem
   adapter in Laravel; §21 ships as private disk + policy-gated + audited access +
   encrypted backups
6. Off-site backup storage and its recurring cost (see B2)
