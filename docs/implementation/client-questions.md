# Questions to answer in writing

Contract §5 says minor revisions within the agreed scope are free, and anything
outside it is quoted separately. That clause only protects you if the boundary is
written down. Everything below is a place where the functional specification is
genuinely ambiguous, or where the client is likely to expect more than §-text
strictly promises.

**Get answers by email or chat and keep them.** Group A blocks Phase 1 and cannot
wait. Group B can be answered during Phase 1 but must be settled before the phase
that needs it.

> **2026-08-18 — `DTS-Questions.docx` came back.** It closed the reference-data half
> of **A4**: 53 offices with codes and 43 document types, with the per-type
> turnaround days left with the City Archive and Records Office. It turned **A6**
> from a build decision into a Super Admin toggle, so only the setting is still
> open. It answered one of **B2**'s five bullets — the hosting is a "Cloud Server" —
> which also softens **A1** without closing it. It left **B3** at "?".
>
> **Mind the provenance on B6.** The document itself answers "how long should old
> files be kept?" with the single word "ARO" — byte for byte the same non-answer it
> gives for the turnaround days. The **3-to-5-year floor came separately, in a chat
> message the same evening**: *"3 to 5 years po minimum archive ng files. Pero alam
> ko sina sir nakasave padin yung past records sa cloud server."* That is the whole
> reason one "ARO" was treated as unanswered — `turnaround_days` is `NULL` on all 43
> types — while the other produced a concrete 1095 days. A chat message is enough to
> set a configuration default; it is not written sign-off to delete a municipal
> record, which is why every pruner is still disabled.
>
> Every question this moved is marked in its own heading below.

> **2026-08-18, later the same day — the "Cloud Server" has a name: Laravel Cloud.**
> The developer confirmed the platform, and it settles three of **B2**'s five bullets at
> once. The scheduler runs there, so `cicto:notify-deadlines`, `cicto:verify-signatures`
> and `cicto:backup` actually fire. Every environment is issued its own certificate, so
> HTTPS is not something the LGU has to procure — that removes the one hard blocker under
> **A1**, leaving only the question of which hostname gets printed on the QR labels. And
> backups finally have a real off-site destination, Laravel Cloud Object Storage.
>
> **It also uncovered the most dangerous thing on this page.** Laravel Cloud filesystems
> are ephemeral — a redeploy resets them, and each replica of the cluster has its own —
> and until today both the documents disk and the backups disk were hard-coded to local
> storage. Every uploaded document would have been destroyed by the next deploy, silently,
> with the `document_files` rows still pointing at bytes that were gone. Both disks are now
> switchable by environment variable, and **B2** records what that costs: with documents in
> object storage the nightly backup covers the **database only**, and object versioning on
> the bucket — a setting outside this application — is the only thing standing between a
> deleted document and a permanently deleted one.
>
> **B3** is untouched by all of this and still reads "?".

> **2026-08-20 — `DTS-Questions (3).docx` closed B3, and closed it by refusing the question.**
> The file is byte-identical to the 18th's except in one cell: where the answer to "your email
> settings, so password resets and messages work" used to read "?", it now reads, in full, that
> **CICTO cannot provide email credentials or configuration for this system, school project or
> not**, and recommends an external service — Google SMTP by name — for anything the application
> needs to send.
>
> That is a *no*, and a no is an answer. It does not leave B3 open; it closes it against us, and
> the rest of the cell says what to build instead: *"you may develop a module that allows the
> system administrator to reset the password of any user registered in your application."* That
> module is now on `/super-admin/users` and is the whole recovery path for a forgotten password
> on a deployment with no mail — which, until an LGU sets one up, is every deployment.
>
> **Two smaller things came with it.** An emailed reset is explicitly permitted *if* we want to
> stand up an external service ourselves, and **in-app messaging** was answered before it was
> asked: it is at our discretion, and *"the existing Document Tracking System (DTS) does not
> currently have an in-app messaging capability"* — so it is not a gap against the incumbent and
> nothing in this build treats it as one.
>
> **What this changed in the code, beyond the module.** The forgot-password page had been
> claiming, on every deployment to date, that it had emailed a link. Under `MAIL_MAILER=log`
> Fortify happily minted a real, single-use, one-hour token, wrote the whole message into the
> shared application log, and returned a green success. Now the page says plainly that this
> server cannot send email, drops the form and names the administrator instead, and the route
> refuses to mint the token at all. That is the answer to B3 landing in the one screen that was
> lying about it.
>
> B3's heading below is marked answered; nothing else on this page moved.

---

## Group A — blocks Phase 1, ask on day 1

### A1. What does "via camera or scanner" mean? *(§7)* *(PARTLY ANSWERED 2026-08-18 — HTTPS is settled; the hostname and the scanner hardware are not)*

Three different builds hide behind that phrase:

| Answer | What it costs | Hard requirement |
| --- | --- | --- |
| **Phone camera** | `@zxing/browser` + a React scanner page | **HTTPS on the deployment host — now met.** `getUserMedia` refuses to run on plain HTTP outside `localhost`; that is a browser rule and not a setting, but Laravel Cloud issues the certificate itself |
| **USB keyboard-wedge scanner** | Effectively free — a focused text input | None. The scanner types the token like a keyboard |
| **Both** | Both of the above | HTTPS, met the same way |

> This was the single most important question in the project, because `APP_URL` was
> `http://localhost:8000` and no deployment host was confirmed. If camera scanning
> were expected and the host had no TLS, the headline feature of the contract — the
> one in its title — would not work, and you would find out at deployment.
>
> **That blocker is gone.** On 2026-08-18 the "Cloud Server" was confirmed to be
> **Laravel Cloud**, which assigns every environment a free `*.laravel.cloud` domain on
> its first successful deploy, and for a custom domain guides you through DNS
> configuration, verifies domain ownership and issues the SSL certificate
> automatically. TLS is not something the LGU has to buy, install or renew, so camera
> scanning can be built without a procurement step in front of it.
>
> **What is left is smaller, and it is a decision rather than a purchase: which
> hostname goes into `CICTO_SCAN_BASE_URL`.** That value is printed onto the QR labels,
> so it is the address every future scan resolves against for the life of the folder —
> and once labels have been issued at volume it cannot be changed without reprinting
> them. The free `*.laravel.cloud` name works today and is a fine thing to test on; it
> is a poor thing to have printed on municipal folders for the next five years. Ask the
> LGU which name it intends to keep, and have the DNS for it verified before the first
> batch of labels is printed rather than after. `AppServiceProvider` refuses to boot in
> production unless that value is `https`, so a wrong answer fails loudly at deploy
> instead of quietly at the scanner.

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

### A4. The real office list and document types *(ANSWERED 2026-08-18 — office list and document types supplied; turnaround days still with ARO)*

Reference data seeded in Phase 1 and used in every dropdown, every route target and
every control number prefix (`OCM-2026-00042`).

`DTS-Questions.docx` supplied both lists: **53 offices, each with an alias**, and
**43 document types**. The aliases are the office codes, and therefore the
control-number prefixes — `OCM` (Office of the City Mayor), `SP` (Office of the
Sangguniang Panlungsod), `TREA` (Office of the City Treasurer), `ARO`, `CICTO`,
`HRMO`, `CENRO`, `GSO-MS`, `OCM-LYDO`, `EEAO-PM`. They are seeded by
`database/seeders/OfficeSeeder.php` and `database/seeders/DocumentTypeSeeder.php`.
Every code has to stay at 29 characters or fewer, because `control_number` is
`varchar(40)` and still has to hold a year and a five-digit sequence.
`ReferenceDataTest::test_every_office_code_fits_a_control_number` is what enforces
that — there is no office admin screen and no form request, so nothing checks it at
runtime.

The invented placeholders they replace are **deactivated, never deleted.** The ten
old office codes — `MO`, `SB`, `MTO`, `MACC`, `MBO`, `MPDO`, `MEO`, `MASSO`, `MCR`,
`MITO` — are switched off, as are the six placeholder document types that had no
real counterpart; the other three (`DV`, `PO`, `TO`) name real types and were
updated in place. Nothing is removed because documents and control numbers already
issued point at these rows, and a deleted office orphans a control number that may
already be printed on paper.

**What is still missing is the turnaround days.** Asked how many days each type
should take, the client answered "ARO": the City Archive and Records Office is a
separate office, it is the one that can say, and the client undertook to ask them.
Until that comes back, `DocumentTypeSeeder` seeds `turnaround_days` as `NULL` on all
43 rows and `App\Support\Deadlines` falls back to
`cicto.deadlines.default_turnaround_days` — **3 calendar days**, env
`CICTO_DEFAULT_TURNAROUND_DAYS`. So every document in the system currently carries
the same provisional 3-day SLA. §11 deadline monitoring works; it is simply not yet
monitoring the client's own numbers.

**Three things to confirm back, all from the supplied file:**

1. The City Social Welfare and Development Officer is listed **twice**, as `CSWDO`
   and as `CSWD`. Both rows exist so neither alias can be handed to another office,
   but `CSWD` ships inactive. Is one of them a duplicate, or are both genuinely in
   use?
2. The alias "CEEAO / BIPU" became the code **`CEEAO`**, with the full name "City
   Economic Enterprise Affairs Office / Baliwag Investment Promotion Unit". A slash
   and spaces inside a control number would end up on printed paper and in URLs.
3. Office names are capitalised as **prose** ("Office of the City Treasurer") rather
   than in the machine title case of the source file. They appear on the public scan
   page and on every report.

One observation, not a commitment: there are 43 document types and **no admin screen
for them**. Every turnaround figure ARO sends back — and the first pass will bring
corrections — is a seeder edit and a redeployment by a developer. A small
document-type edit screen would move that to a Super Admin. It is outside the priced
scope, so it belongs in a §5 quote; it is worth pricing before ARO answers rather
than after.

#### Two things to send ARO with the question, not after it

Both were measured on a seeded instance on 2026-08-18; neither is a defect, and both
get worse the longer they go unsaid.

**1. Do not specify a turnaround below 3 days without telling us.** The "Due soon"
warning window is a fixed 2 calendar days (`cicto.deadlines.approaching_days`). A type
with a **1-day** turnaround is therefore born amber — it reads "Due soon" the instant
it is registered, at every filing hour tested. A **2-day** type does the same for
anything filed at or after 18:00, because the warning boundary and the deadline
coincide exactly. Either makes the badge meaningless for that type. If ARO genuinely
needs a same-day or next-day class, the warning window has to become per-type, and
`config/cicto.php` already records why that is not a one-line change: it needs date
arithmetic across two columns that is not portable between PostgreSQL and MySQL.

**2. Until the real numbers arrive, the whole backlog changes colour at once.** Every
type falls back to the same 3-day default, so two documents filed on the same day get a
byte-identical deadline whatever they are. On seeded data all nine sample documents sat
at one timestamp. The dashboard therefore shows nothing flagged for two days, then
**every** open document "Due soon" on the same morning, then **every** one "Overdue" two
mornings later — and the 08:00 sweep emails that whole set, then repeats the overdue
reminder every morning until each document is closed. That is the deadline feature
working exactly as specified on a placeholder SLA, but it looks like a fault, and an
inbox that behaves that way in week one is an inbox nobody reads in week two. It
resolves itself the moment per-type numbers are seeded.

### A5. Who creates accounts?

§3 offers public registration. On a municipal records system that is a policy
decision, not a technical one.

- **Public self-registration** → a stranger can create an account. Mitigated by
  quarantining new users with no office and no documents until an Admin assigns
  them, but the exposure is real.
- **Admin-created accounts only** → safer, and closer to how LGUs actually work.

### A6. Can an Admin approve a document they submitted themselves? *(ANSWERED 2026-08-18 — it is a Super Admin toggle now; only the setting is open)*

The natural separation-of-duties rule blocks it. In a two-person municipal office
that rule blocks real work.

The answer cell came back "?", but the note beside it read "they can allow or block
it naman daw" — the LGU expects to make this call itself, and to be able to change
it later. So it shipped as something they can change without us: a **Super Admin
toggle on `/super-admin/settings`**, stored in `app_settings` under the key
`workflow.allow_self_approval` and read through
`App\Support\SystemSettings::allowSelfApproval()`, which falls back to
`config('cicto.workflow.allow_self_approval')` when no row exists. Blocking
self-approval is still the shipped default, so an installation nobody touches
behaves the safe way. Flipping it writes a `SecurityEvent`, so who changed it and
when stays on the record.

Two things to say before anyone tests it:

1. **The toggle governs signing as well as approving.** `DocumentPolicy::sign` reads
   the same helper as `DocumentPolicy::act`, because a signature is an attestation
   of assent and someone who may not decide on a document has nothing to attest to.
   Turning self-approval on unblocks both at once.
2. **It applies to Super Admins too.** `DocumentPolicy` has no `Gate::before`
   super-admin bypass; every method states its own rule. A Super Admin who submitted
   a document cannot approve or sign it either while the toggle is off.

What is still open is only which way the LGU wants it set. That is now a click, not
a deployment.

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

### B2. Backup — what host, and what counts as "automated"? *(§22, Phase 3, PHP 400)* *(MOSTLY ANSWERED 2026-08-18 — the host is Laravel Cloud, which settles hosting, cron and the off-site destination; the restore drill and the `proc_open` probe stay open)*

The fast way to dump a database is to shell out to `pg_dump`/`mysqldump`, and
whether that works is a property of the host rather than of the code. Managed and
shared PHP hosting routinely disables `proc_open`/`exec` and ships no database
client binaries; a server we administer ourselves usually has both. The backup
command therefore branches on what the host actually allows, and the answers below
decide which branch ships. (This is why no backup *package* is used; see
[`phase-3-trust-and-toolchain.md`](phase-3-trust-and-toolchain.md) §5.)

Confirm before Phase 3:

- **What is the hosting? — answered 2026-08-18: Laravel Cloud.** Not shared cPanel and
  not a VPS we administer, but a managed platform. That is why the three bullets below
  could be answered from its documentation instead of by trying things on the box — and
  why the one that cannot be answered that way is still marked unconfirmed.
- **Is there cron? — answered: yes.** Laravel Cloud runs the Laravel scheduler; it is a
  per-environment compute setting rather than a crontab we install. So the three
  commands registered in `routes/console.php` genuinely fire on the host:
  `cicto:notify-deadlines` at 08:00, `cicto:verify-signatures` at 02:30 and
  `cicto:backup` at 01:00. §22's "automated regular backups" is automated in the sense
  the client will read it. This bullet has been open since the first draft of this file.
- **Is `proc_open` enabled? Are `pg_dump`/`mysqldump` installed? — not confirmed, and
  not blocking.** Nothing in the platform documentation promises either. `cicto:backup`
  falls back to the pure-PHP dumper on its own when the shell route is unavailable, so a
  "no" here costs speed on a large database and nothing else — no branch has to be
  chosen by hand and nothing fails. `cicto:host-check` probes for both: run it once on
  the real environment after the first deploy and write down what it says, so this is on
  the record rather than assumed.
- **Where do backups go? — answered: Laravel Cloud Object Storage**, which is
  S3-compatible and offered in partnership with Cloudflare R2. That is the off-site
  destination this bullet has been asking about, and it means backups no longer sit on
  the same disk as the documents — which on this host would not have been a durable disk
  at all (see below). It needs `league/flysystem-aws-s3-v3`, because the `s3` driver
  cannot boot without it, and that is now in `composer.json`. The **recurring cost is
  still real and still absent from the cost breakdown**: knowing the destination does
  not settle who pays the monthly bill for it.
- **Who restores, and who tests the restore? — still open.** Nothing about the host
  answers this, and it is the bullet that decides whether any of the above is worth
  anything. A backup nobody has restored is a hypothesis. Name the person, and put the
  first restore drill on a date before sign-off rather than after the first incident.

The same answer reaches past backup: Laravel Cloud issues TLS certificates
automatically, which retires the one hard requirement **A1**'s camera scanning was
blocked on. What A1 still needs is the *name* — the hostname baked into
`CICTO_SCAN_BASE_URL` and printed onto every QR label.

> Also: `APP_KEY` becomes a first-class backup artifact. If someone re-runs
> `composer setup` on the host, `artisan key:generate` fires and **permanently
> destroys every encrypted column and every encrypted backup.**

#### The filesystem is ephemeral — this is the dangerous part of the answer

Laravel Cloud's documentation is explicit about it: environment filesystems are
ephemeral, files may not persist across requests or jobs, a new deployment or
re-deployment resets the filesystem, and each replica of the compute cluster has its own
filesystem. Disk size is not something you buy separately either — every 1 GB of cluster
RAM is worth 512 MB of disk.

Until 2026-08-18 **both** the documents disk and the backups disk were hard-coded to the
`local` driver under `storage_path()`. On Laravel Cloud that means every uploaded
document is destroyed by the next deploy. The reason this is the most dangerous thing in
the deployment is that it fails *quietly*: nothing throws, the `document_files` rows
still point at bytes that are no longer there, the register still lists the documents and
the movement history still reads correctly — only the download 404s, one file at a time,
whenever someone happens to ask for one. A second replica reproduces the same symptom
with no deploy at all, because the upload lands on one replica's disk and the download is
served from another's.

What has changed in code:

- **Both disks now take their driver from the environment.** `CICTO_DOCUMENTS_DRIVER` and
  `CICTO_BACKUP_DISK_DRIVER` in `config/filesystems.php`, each defaulting to `local` so
  nothing changes for a self-hosted install. Both disks carry the S3/R2 keys alongside
  the local root, and whichever driver is active ignores the keys it does not use.
  `CICTO_DOCUMENTS_BUCKET` and `CICTO_BACKUP_BUCKET` are optional and fall back to
  `AWS_BUCKET`. One caveat is written into the config: do **not** set
  `visibility => 'public'` on these disks. R2 rejects per-object ACLs with
  `NotImplemented` and governs access at the bucket level instead.
- **Backups can now be written to a remote disk.** The dump and the zip are still built
  locally, because a dumper needs `fopen`/`proc_open` and `ZipArchive` needs a real path;
  when the backups disk is not local, `BackupService` builds the artifact in
  `storage/app/backup-staging`, checksums it, streams it up with `writeStream`, verifies
  it is present on the destination and then deletes the staging copy. A failure part-way
  through deletes the staging file and marks the run Failed, so a half-uploaded artifact
  is never recorded as a backup.
- **`cicto:host-check` now prints which driver each disk is using**, and adds a
  "Documents durable?" row that warns when the documents disk is `local`. That row exists
  because the existing write-probe is useless here — it writes a file and reads it back
  inside the same request, which an ephemeral disk passes perfectly.
- `.env.example` carries the new keys with the ephemeral-filesystem warning spelled out
  next to them.
- Two tests in `tests/Feature/Documents/BackupAndSecurityTest.php` pin the behaviour: a
  backup reaches a non-local disk leaving no staging copy behind and with a checksum
  matching the uploaded bytes, and a remote *documents* disk makes the run kind
  `database` rather than falsely claiming `full`.

**The honest limit, and it has to be in writing before sign-off.**
`BackupService::canArchiveFiles()` returns false when the documents disk is not local. A
remote disk cannot be walked cheaply, and on a container host the scratch disk could not
hold the result anyway. So on Laravel Cloud **the nightly backup covers the database
only.** The document bytes are protected instead by the durability of the bucket they
live in — which guards against hardware failure, and against nothing else. It does not
guard against a file being deleted or overwritten, whether by a bug, by a mistake, or by
anyone holding the keys. What guards against that is **object versioning on the documents
bucket**, together with whatever lifecycle policy decides how long old versions are kept.
Both are settings outside this application; neither is on by default; and turning them on
is a deliberate act by whoever administers the bucket. Say this before sign-off. Nobody
should be left believing the nightly backup would bring a deleted document back, because
it would not.

### B3. Is email delivery in scope? *(§3, §12)* *(ANSWERED 2026-08-20 — no credentials, and a password-reset module instead)*

The question was: who provides SMTP credentials, and for what address? The
answer, in writing, on 2026-08-20:

> For email-related settings, CICTO cannot provide email credentials or
> configuration details, even when the system is being developed for school or
> academic purposes. We highly recommend using alternative email services, such
> as Google SMTP, for sending emails from your application.
>
> For password reset functionality, you may develop a module that allows the
> system administrator to reset the password of any user registered in your
> application.
>
> If you prefer to implement an email-based password reset feature, please use
> an external email service, such as Google SMTP, as recommended above.
>
> If your group would like to implement an in-app messaging feature in your
> application, you may do so at your discretion. However, please note that the
> existing Document Tracking System (DTS) does not currently have an in-app
> messaging capability.

**That is a closed question, not a deferred one.** Nobody is coming with a
`mail.baliwag.gov.ph` and a service account. Read it as three decisions:

#### 1. The recovery path is an administrator, not an inbox

Built, and it is the load-bearing consequence of the whole answer. A Super Admin
sets a password for any account from **Manage Users**
(`POST /super-admin/users/{user}/password`), and the same operation is available
over SSH as `php artisan cicto:user <email> --reset-password` for the one case
the screen cannot serve — every Super Admin locked out, so there is nobody left
to sign in and press the button.

Setting somebody's password is a complete account takeover with a receipt, so
the module does five things rather than one:

| It does | Because |
| --- | --- |
| Asks for the **administrator's own** password in the form | An unattended signed-in browser must not be able to take over every account in the city. The house pattern is the `password.confirm` middleware, but that redirects an Inertia POST to another page and throws the typed password away; asking in the form buys the same property and keeps it |
| Rotates `remember_token` | A stolen "remember me" cookie authenticates against that column, not the password. Leaving it is a reset that revoked nothing |
| Deletes any outstanding emailed reset token | A link issued in the last hour would let whoever holds it set the password straight back |
| Destroys the account's live sessions | `Auth::logoutOtherDevices()` cannot do this: it acts on the *administrator*, and it needs `AuthenticateSession`, which is not in this application's middleware stack. A direct delete on `sessions.user_id` is the only mechanic that works, and it needs `SESSION_DRIVER=database` — the screen says so when it is not |
| Optionally removes two-factor and passkeys | A passkey signs its holder in without the password ever being consulted. Off by default, because a forgotten password and a stolen account want opposite answers; offered only on accounts that actually have one |

It writes `SecurityEventType::PasswordResetByAdmin` — deliberately **not** the
existing `auth.password_reset`, which `RecordSecurityEvents` renders as
"*<email>* reset their password" with the account holder as the actor. Filing an
administrator-set password under that case would put the wrong person's name
against the one operation that hands over somebody else's account.

#### 2. Email is possible, at the LGU's own expense, and is not configured here

`config/mail.php` was always ready; what was missing was anyone saying which
service. `.env.example` now carries the Google SMTP recipe the client named,
including the two things that actually bite — an ordinary Google password is
refused, it has to be a 16-character **App Password**, and `MAIL_FROM_ADDRESS`
must be the authenticated Gmail account or every message is rejected at send
time with the connection reporting healthy. `cicto:host-check` now checks the
second one.

**What does not change with SMTP configured:** §12 notification email is still
out of scope and still a change order — see
[`phase-2-workflow-and-trail.md`](phase-2-workflow-and-trail.md) §Email. A
working mailer makes it *possible*, not *included*. And no credential is ever
emailed to anybody, mail or no mail: a password in an inbox outlives every use
of it, so both the create form and the reset panel say plainly that the
administrator hands it over themselves.

#### 3. In-app messaging is out, and the client said so first

*"The existing DTS does not currently have an in-app messaging capability."* It
is not a gap against the incumbent system and nothing here treats it as one. §12
remains in-app **notifications** — deadline and movement notices raised by the
system — which is a different feature and already built.

#### What the answer forced us to fix

`MAIL_MAILER=log` was never neutral. Fortify's forgot-password flow does not
check whether mail works: it minted a real, single-use, one-hour token for any
address posted to it, wrote the entire message — reset link included — into the
shared unrotated application log at debug level, and returned the green *"We
have emailed your password reset link"*. Every deployment of this system has
been doing that.

The page now says it cannot send email, drops the form and names the
administrator instead; `RequireOutgoingMail` refuses the POST for anyone who
arrives another way, with the same message for a known and an unknown address so
it cannot be used to enumerate accounts; and the `log` mailer writes to its own
7-day channel rather than into `stack`. That last one reduces the exposure and
does not remove it — only a real transport does.

#### Still owed on this question

Nothing from the client. One thing from whoever deploys: if an LGU does stand up
Google SMTP, `MAIL_FROM_ADDRESS` and the App Password have to be set together,
and `php artisan cicto:host-check` is what says whether they were.

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

### B6. Retention — how long do we keep things? *(PARTLY ANSWERED 2026-08-18 — a 3-to-5-year floor; the exact figure is with ARO)*

Nothing in the spec prunes anything, and two tables grow without bound:

- `document_files` keeps **every version forever.** At 200 documents/month
  averaging 2 MB with one re-upload each, that is roughly 10 GB in two years.
- `document_scans` grows fastest of all if couriers rescan repeatedly.

The client answered with a floor rather than a figure: "3 to 5 years po minimum
archive ng files", adding that past records are also kept on their own cloud server.
The exact number is with the City Archive and Records Office — the same office that
owes A4 its turnaround days.

Read at the floor, `cicto.retention.versions.after_days` moved from 180 days to
**1095** (3 years). Deliberately *not* moved to match:
`cicto.scans.retention_days`, which stays at **180 days**. It governs IP addresses
and user agents — personal data under RA 10173 — and 180 days is published as a
promise on the public privacy notice. Stretching it to fit a file-retention policy
would be a step backwards, and would quietly rewrite something citizens have already
been told.

**Every pruner still ships disabled**, and stays disabled until two things exist: a
figure signed off in writing rather than quoted from a chat message, and a confirmed
off-site backup to delete against (B2). Deletion is the one operation the system
cannot undo. The storage quota is still unagreed, so for now the disk cost is the
client's either way.

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
RBAC redirect, which is how the *Spec section coverage* table in
[`docs/implementation/README.md`](README.md) already reads it, but the instruction
should be confirmed in writing before sign-off so the wording is not raised at
acceptance.

---

## Standing scope note

Six things sit just outside the specification and will feel "obviously included"
to a client. The first five are each a §5 re-quote; the sixth is now a recurring bill
rather than a build:

1. A configurable workflow engine (see A3)
2. Per-user or per-office granular permissions beyond the three fixed roles
3. Stored support tickets with an admin queue (see B5)
4. True cryptographic/PKI signatures (see B1)
5. Encryption of document bytes at rest — there is no encrypting filesystem
   adapter in Laravel; §21 ships as private disk + policy-gated + audited access +
   encrypted backups
6. The recurring cost of off-site storage (see B2). The destination itself is settled —
   Laravel Cloud Object Storage — and the application can now write both documents and
   backups to it, so this is no longer a question of building anything. What is left is the bill:
   the bucket is charged monthly, and neither it nor the object versioning the documents
   depend on for protection against deletion appears anywhere in the cost breakdown
