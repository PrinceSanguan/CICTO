# Deployment runbook

Everything below is a command you run or a value you check. Work top to bottom.
Nothing here is optional except where it says so.

Contract §6 transfers source and full rights on final payment and **says nothing
about who installs it**. Settle that before the day you plan to deploy.

---

## 0. Before you touch the server

**Where** is settled, and this time concretely. The client's answer to client
question **B2** — "Cloud Server" — was made specific by the developer on
2026-08-18: the application is deployed on **Laravel Cloud**. That closes three
things that had been open since the start, and it opens one new way to lose
every document in the register — which is why §1 exists and why it comes before
anything else you type.

Five things must be true or the deployment stalls half-finished. Two of them the
hosting answer now settles. One is not a question at all any more but a step you
must perform, and it is the step that punishes being skipped.

| Need | Why it stops you | Where it stands |
| --- | --- | --- |
| **HTTPS on the real hostname** *(ANSWERED 2026-08-18 — the platform issues it)* | The camera QR scanner cannot run on plain HTTP — a browser rule, not a setting. Secure cookies and HSTS also depend on it | Settled by the host, not procured by the LGU. Laravel Cloud assigns every environment a free `*.laravel.cloud` domain on first successful deploy, and for a custom domain it guides you through DNS, verifies ownership and issues the SSL certificate automatically. **Still open: which hostname**, because it is printed onto QR labels — §3 |
| **A cron entry** *(ANSWERED 2026-08-18 — Cloud runs the scheduler)* | Deadline warnings, overdue notices, nightly signature verification and backups all hang off `schedule:run` | Settled. Laravel Cloud runs the Laravel scheduler as a per-environment compute setting, so it is off until somebody switches it on. Do not add a crontab — §1.2 |
| **Durable storage for the documents** *(NEW, and the dangerous one)* | Laravel Cloud's filesystem is ephemeral and per-replica: a local documents disk there is emptied by the next deploy while the `document_files` rows still point at the missing bytes | No fallback, and no error either — it fails silently. Attach a private bucket and set `CICTO_DOCUMENTS_DRIVER=s3` **before the first real upload**. §1.1 |
| **`proc_open` enabled** | `pg_dump`/`mysqldump` need it | Still unconfirmed on Cloud, and not blocking: `PhpDumper` takes over automatically and produces a data-only dump, so restore then needs `migrate` first. Probe it on the real environment and write down which dumper you got, because it changes the restore procedure |
| **SMTP credentials** *(ANSWERED 2026-08-20 — CICTO will not supply them; SUPERSEDED 2026-08-23 — the operator stood one up)* | Password resets, verification links and support tickets | **Settled, and mail is live.** CICTO's answer stands: they supply no credentials and recommend an external service (Google SMTP). On 2026-08-23 the operator took that recommendation — a Gmail account with 2-Step Verification on and a 16-character App Password, proved by a real STARTTLS handshake *and* a real delivered message. Production runs `MAIL_MAILER=smtp`; §3 carries the block and the things that break it. Forgot Password now sends a genuine reset link, and Manage Users remains the supported route for anyone who cannot receive mail |

The remaining thing B2 asked — **where the off-site backup goes** — is answered
too: Laravel Cloud Object Storage, which is S3-compatible and offered in
partnership with Cloudflare R2. What stays open there is who performs and who
tests the restore drill, and object versioning on the documents bucket — both
of those are in §10.

Run the probe and keep the output. On Laravel Cloud run it from the
environment's **Commands** tab and not on your laptop: it reports what *that*
host can do, and every row that matters here differs between the two.

```bash
php artisan cicto:host-check
```

---

## 1. Laravel Cloud

This is the host the client chose, so this section is the main road and not an
appendix. Skip it only if you are deploying to a VPS with a real disk of its own.

### 1.1 The documents must live in object storage — do this first

**A local documents disk on Laravel Cloud destroys every uploaded document on
the next deploy.** Cloud's documentation is explicit: "Environment filesystems
are ephemeral, meaning files may not persist across requests or jobs. New
deployments or re-deployments will reset the filesystem. In addition, each
replica of your compute cluster has its own filesystem."

Understand how that failure presents, because it is the reason it has to be
fixed before go-live rather than after. Nothing throws. The register still lists
every document, search still finds them, the audit trail is intact — the
download just 404s, and the 02:30 signature sweep reports the entire signed
record set as failing, because the hash was taken over bytes that are gone.

So, before anybody registers a real document:

1. Attach a **private** Laravel Object Storage bucket to the environment. It is
   S3-compatible and offered in partnership with Cloudflare R2.
2. Attach a **second, separate** bucket for backups. An archive sitting in the
   same bucket as the documents is a second copy, not a backup: one mistaken
   bucket deletion or one bad credential takes the originals and the archive
   together.
3. Set the two drivers in the environment:

```dotenv
CICTO_DOCUMENTS_DRIVER=s3
CICTO_BACKUP_DISK_DRIVER=s3

# Only needed when the two buckets differ, which is the arrangement you want.
# Each defaults to AWS_BUCKET, and leaving both unset is what silently puts the
# backups in the documents bucket.
CICTO_BACKUP_BUCKET=<the backup bucket>
# CICTO_DOCUMENTS_BUCKET=<the documents bucket>
```

Cloud injects `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET` and
`AWS_ENDPOINT` itself once a bucket is attached, so those four stay out of the
env block in §3. Do not paste keys you were not given.

Two things not to do:

- **Do not make either bucket public**, and do not add `'visibility' => 'public'`
  to those disks in `config/filesystems.php`. R2 rejects per-object ACLs with
  `NotImplemented` and governs access at the bucket level, so the setting does
  not do what it looks like it does. Downloads are served by
  `DocumentFileController` after a policy check and an audit row — never
  directly from storage.
- **Do not point the backups disk at the documents bucket** to save attaching a
  second one. See item 2.

`composer install` brings in `league/flysystem-aws-s3-v3`; the `s3` driver
cannot boot without it.

### 1.2 Turn the scheduler on

Laravel Cloud runs the Laravel scheduler, but it is a per-environment compute
setting: until somebody enables it, these three commands never fire and nobody
is told. They are registered in `routes/console.php`, at these times, in
`config('app.timezone')`:

| Command | Time | What is lost while the scheduler is off |
| --- | --- | --- |
| `cicto:backup` | 01:00 daily | The nightly backup. §22's "automated regular backups" is then simply not delivered |
| `cicto:verify-signatures --quiet-ok` | 02:30 daily | Tampering stays *detectable* and stops being *detected*. Nobody re-opens a six-month-old certificate by hand |
| `cicto:notify-deadlines` | 08:00 daily | Deadline warnings and overdue notices, which are **in-app** notifications and not email. The sweep sends no mail even now that SMTP works — §12 notification email is still a change order — so do not go looking in the Gmail account for evidence it ran. The Overdue filter, the row badges and the dashboard counts stay correct regardless, because overdue is a live query and not a stored flag, so the only thing that goes missing is the push, silently |

Do not also add a crontab. Confirm the registration from the **Commands** tab:

```bash
php artisan schedule:list
```

That proves the commands are registered. It does not prove anything is calling
`schedule:run`. Prove that the morning after by checking a `backup_runs` row
exists with a timestamp around 01:00.

### 1.3 Sessions and cache must stay on the database

`.env.example` ships `SESSION_DRIVER=database` and `CACHE_STORE=database`. On
this host those are not defaults to tidy up later — they are load-bearing, and
they must stay exactly as they are.

The filesystem is ephemeral *and* per-replica. File sessions mean the replica
that serves the next request cannot see the session the previous replica wrote,
and a deploy discards all of them at once: users are thrown back to the login
screen at random, mid-task, with no error anywhere to explain it. A file cache
fails the same way, less visibly.

### 1.4 Build command, deploy command, and the one command not to run

- **`php artisan config:cache` belongs in the BUILD command**, not the deploy
  command — Cloud states this explicitly. The warning in §5 still holds: a
  cached config is a frozen snapshot, so any `.env` change needs a fresh build,
  not just a restart.
- **`php artisan migrate --force`** is a deploy-time step. Run it in the deploy
  command or from the Commands tab; §4 is otherwise unchanged.
- **Do not run `php artisan storage:link`.** The symlink is written to the
  ephemeral filesystem, so it disappears on the next deploy and is missing on
  every replica that did not create it. Nothing in CICTO needs it: documents are
  private and served through a policy-checked controller, never from
  `public/storage`.

### 1.5 The logs are ephemeral too

`LOG_STACK=single` writes to `storage/logs/`, which is the same filesystem: the
log is per-replica and gone at the next deploy. Whatever you were going to
diagnose from it after an incident will not be there.

Read logs from Cloud's log stream instead, and use Nightwatch if the LGU wants
retention and search rather than a live tail. This applies to the CSP reports as
well: §3 tells you to watch `storage/logs/csp-*.log` for a week before setting
`CICTO_CSP_ENFORCE=true`, and on this host that week of evidence has to come out
of the log stream.

### 1.6 Run the probe on the real environment and file the result

From the **Commands** tab, not your laptop:

```bash
php artisan cicto:host-check
```

Record the output with the handover. Three rows decide whether you may proceed:

- **Documents durable?** — this row only appears when the documents disk is
  `local`. On Laravel Cloud, seeing it means §1.1 has not been done and the next
  deploy will take every document with it. It reads `CHECK` rather than `FAIL`
  because the command cannot prove it: the storage write probe writes a file and
  reads it back inside one request, which an ephemeral disk passes perfectly.
- **Backup driver** — whether `proc_open` and `pg_dump`/`mysqldump` exist on
  Cloud is not confirmed. It is not blocking, because the backup falls back to
  `PhpDumper` on its own, but the fallback dump is data-only and the restore in
  §8 then has to run `migrate` first. Write down which one this host gave you.
- **SMTP handshake** — new as of 2026-08-23, and the only row that *proves*
  mail rather than describing it. It really opens the connection and runs EHLO,
  STARTTLS and AUTH against the live provider with the live credential, then
  stops. **It sends nothing, so it costs no Gmail quota and lands in nobody's
  inbox.** `OK authenticated` is the only reading that means reset links,
  verification links and support tickets can leave this host — the two mail
  rows above it read `OK` on a host where every send fails, because they only
  read configuration. A failure prints the first line of the provider's
  rejection, truncated on purpose so the credential is never quoted back into a
  handover screenshot. See §3.

---

## 2. Code and dependencies

```bash
git clone <repo> cicto && cd cicto
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
php artisan key:generate
```

> `npm run build` must run **on a machine with node**. If the host has none,
> build locally and upload `public/build/` — it is the compiled output, nothing
> at runtime needs node.

---

## 3. Environment

```dotenv
APP_ENV=production
APP_DEBUG=false                 # non-negotiable: debug leaks env vars on any error
APP_URL=https://cicto.example.gov.ph

DB_CONNECTION=pgsql             # or mysql
DB_HOST=127.0.0.1
DB_DATABASE=cicto
DB_USERNAME=cicto
DB_PASSWORD=<generated>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true      # ONLY once HTTPS is confirmed

# Outgoing mail. CICTO answered B3 on 2026-08-20: they will not supply email
# credentials or configuration, and recommend an external service. On 2026-08-23
# the operator took that recommendation and stood up Google SMTP, so production
# runs on `smtp` and mail is live. `.env.example` still ships `log` -- that is
# the safe default for a fresh checkout, not the production value.
#
# These nine move together, and you EDIT THEM IN PLACE rather than appending a
# second block. dotenv takes the LAST assignment of a duplicated key, so a
# half-pasted block leaves MAIL_MAILER=smtp pointing at the placeholder host:
# a mailer the application believes works, minting reset tokens that go nowhere.
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587                   # 587 = STARTTLS
MAIL_SCHEME=null                # leave null for 587; smtps + 465 is the alternative
MAIL_USERNAME=<the gmail account>
MAIL_PASSWORD=<a 16-character Gmail App Password, NOT the account password>
MAIL_FROM_ADDRESS=<the same gmail account>
MAIL_FROM_NAME="${APP_NAME}"
MAIL_LOG_CHANNEL=mail           # keeps rendered messages out of the shared log
MAIL_TIMEOUT=15                 # seconds. Do not unset it -- see "Outgoing mail" below
# MAIL_FROM_ADDRESS is the line that gets missed: Google rejects a From address
# that is not the account it authenticated, and the connection reports healthy
# while every message bounces. `cicto:host-check` checks it, and §9 asks you to
# prove a reset link actually arrives rather than that the config looks right.

# Printed onto paper. Get it right before any label is issued at volume.
CICTO_SCAN_BASE_URL=https://cicto.example.gov.ph

# Shown on the support page, the help screens and the public privacy notice.
CICTO_SUPPORT_OFFICE="Office of the City Mayor - City Information and Communications Technology Office"
CICTO_SUPPORT_EMAIL=ict@example.gov.ph
CICTO_SUPPORT_PHONE="(044) 000 0000"
CICTO_PRIVACY_CONTACT="Data Protection Officer, ..."

# Office hours as the client gave them on 2026-08-18. The four-day week is real,
# not a typo for Friday; only the times were wrong on the supplied design.
# Both need the quotes, because both values contain spaces.
CICTO_SUPPORT_HOURS="Monday - Thursday"
CICTO_SUPPORT_HOURS_DETAIL="7:00 AM - 6:00 PM"

# The hour a deadline is clamped to, so it lands at close of business instead of
# midnight. 18 = 6:00 PM, matching the line above; move them together.
# These keys publish and clamp the hours -- they do NOT teach the deadline clock
# which DAYS the counter is open. See §10.
CICTO_BUSINESS_END_HOUR=18

# Days allowed for a document whose type carries no turnaround of its own,
# which today means every type. The real per-type figures are still with the City
# Archive and Records Office, so this one number is the SLA for all 43 of them.
CICTO_DEFAULT_TURNAROUND_DAYS=3

# How long a superseded file version is kept before the version pruner would
# touch it. 1095 days is the three-year floor the client stated on 2026-08-18;
# the exact figure is still with ARO. The pruner ships disabled regardless --
# this is the flag that keeps it that way, and the string cicto:prune-versions
# names when it refuses to run. Leave it false until B6 is signed off in
# writing AND an off-site backup exists; then see §6, because turning it on is
# not enough on its own.
CICTO_VERSION_RETENTION_DAYS=1095
CICTO_PRUNE_VERSIONS_ENABLED=false
```

Those last four already match the shipped defaults in `config/cicto.php`. They
are listed so an operator can change them without a deploy, not because the
deploy needs them set. Two of them are provisional: see §4 for why every
document type currently gets the same three days.

### Outgoing mail

Live since 2026-08-23, and almost nothing that keeps it working is visible in
the block above.

**The credential is a Gmail App Password, and 2-Step Verification has to be on
before one can exist.** Google offers App Passwords only on an account that has
2-Step Verification enabled, so the order is: turn 2-Step Verification on,
generate the App Password, paste it into `MAIL_PASSWORD`. Google displays it as
four groups of four characters — **the spaces are presentation; paste the 16
characters without them.** The ordinary account password is not a substitute and
never authenticates over SMTP, and a Workspace account whose domain policy
disables SMTP AUTH will not authenticate either — that is a setting on the
domain, not something this host can fix.

**Prove it on the host, and prove it for nothing.** `php artisan cicto:host-check`
carries an **SMTP handshake** row that opens the real connection and runs EHLO,
STARTTLS and AUTH with the live credential, then stops. **It sends nothing: no
quota is spent, nothing lands in anybody's inbox, and it is safe to run against
production as often as you like.** `OK authenticated` is the only reading that
means mail leaves this host — the `Outgoing mail` and `Mail From address` rows
above it read configuration only and both say `OK` on a host where every send
fails. §1.6.

**Roughly 500 recipients a day, and then nothing for 24 hours.** That is the free
Gmail cap. It is not a delay and not a queue: past the limit Google refuses, and
the refusal takes password resets, verification links and support tickets down
**together**, because all three leave through the one account. Ordinary LGU
traffic is nowhere near it — resets are a handful a week — so the realistic way
to spend 500 messages in a day is somebody walking the staff list at the Forgot
Password form, which is what the throttle below exists to stop. If it happens
anyway there is nothing to configure: wait out the 24 hours, and use Manage
Users → **Set password** meanwhile (§4).

**Mail is sent inline, not queued. No `queue:work` process is required, and do
not add one expecting mail to travel through it.** This is a decision rather than
an oversight. Nothing in this deployment runs a queue worker: the VPS crontab in
§6 runs `schedule:run` and nothing else, and Laravel Cloud's scheduler setting
starts no worker either. Queue the mail on a host like that and every message is
written to the jobs table and sits there — the screen reports the reset link was
sent, the token is real and live, and nothing ever leaves the building. The cost
of sending inline is that the request waits for Google: roughly one to three
seconds on Forgot Password, on registration and on a support ticket, bounded by
`MAIL_TIMEOUT=15` so a provider that stops answering cannot hold a web worker
open indefinitely. A slow page that really sent it beats an instant one that
quietly did not.

**A failed send is now a sentence, not a stack trace.** Any transport failure —
a revoked App Password, the daily cap, a minute of bad DNS — is rendered as an
error under the field the user was filling in; a failed registration goes forward
to the verification notice, which has a Resend button, rather than back to a form
that would then reject their own new address as taken; and a support ticket keeps
the copy it already wrote to the log and reports itself as recorded but not
delivered. None of it is silent: every failure is written to the application log
with the provider's reason for it. (That is the ordinary log stack, not
`MAIL_LOG_CHANNEL` — that key only says where the `log` *mailer* would dump
rendered messages on a host with mail switched off, and it never sees a
delivery failure.) On Laravel Cloud, read it from the log stream — §1.5.

**Email verification is enforced as of 2026-08-23.** `User` now declares
`MustVerifyEmail`, which is what makes the `verified` middleware on the six
protected route groups do anything at all: a self-registered account is held on
the verification notice and reaches no screen in the application until the link
in the mail is clicked. Accounts *you* create are unaffected —
`cicto:create-super-admin`, `cicto:user` and Manage Users all stamp the account
verified at creation, which is why nobody was locked out when this changed. What
it does mean is that on a host where mail is broken, self-registration is not
inconvenient but a dead end, because the Resend button needs the same transport.

**Reset requests are throttled at 10 per IP per hour.** That is
`ThrottlePasswordResetRequests`, registered in `config/fortify.php` beside
`RequireOutgoingMail`. Laravel's own limiter sits on the password broker and is
keyed on the *email address*, so it stops one address being mailed twice a minute
and does nothing about one client posting a hundred different addresses. Somebody
who reaches the limit gets a red error under the email field — "Too many reset
requests from this connection. Try again in N seconds, or ask your administrator
to set a new password for you." — rather than a 429 page, so what they typed
survives. The window is per IP, so an office behind one municipal NAT shares the
ten between them; that is why the figure is 10 and not 3.

### The three settings that are hard to undo

- **`CICTO_SCAN_BASE_URL`** is baked into every printed QR label. Behind a
  reverse proxy `url()` routinely emits `http://` or an internal hostname, which
  is why this is configured rather than derived. A wrong value here cannot be
  fixed without reprinting every label already taped to a folder. The app
  refuses to boot in production if it is not `https://`. Laravel Cloud's free
  `*.laravel.cloud` address satisfies that check on day one, which is exactly why
  it is a trap: put the hostname the LGU intends to **keep** on the labels, not
  the one that happens to work this week. Client question **A1** is open on
  precisely this point and nothing else.
- **`CICTO_HSTS=true`** makes browsers *remember* to refuse plain HTTP for a
  year. Set it on a host without TLS and you have locked users out in a way
  clearing the cache does not fix. Leave it off until HTTPS is confirmed — on
  Laravel Cloud that is the first successful deploy, so the wait is short, but
  wait until the final hostname is the one being served.
- **`CICTO_CSP_ENFORCE=true`** turns the Content-Security-Policy from
  report-only into blocking. Watch `storage/logs/csp-*.log` for a week first.

---

## 4. Database

```bash
php artisan migrate --force
php artisan db:seed --class=OfficeSeeder --force
php artisan db:seed --class=DocumentTypeSeeder --force
```

Those two seeders carry the client's real reference data as supplied on
2026-08-18: **53 offices**, keyed by the aliases the LGU already uses (OCM, SP,
TREA, ARO, CICTO, HRMO, CENRO and the rest), and **43 document types**. The
office code is the control-number prefix, so a document raised by the Office of
the City Mayor reads `OCM-2026-00042`. Nothing in either seeder is a placeholder
any more except one column.

**`turnaround_days` is NULL on every document type.** The client's answer to
"how many days should each type take?" was "ARO" — the City Archive and Records
Office is a different office, it is the one that can answer, and it had not been
asked yet (client question **A4**). Until those numbers arrive
`App\Support\Deadlines` falls back to `CICTO_DEFAULT_TURNAROUND_DAYS`, so all 43
types share the same provisional three-day SLA. There is no admin screen for
document types: the real figures will be a seeder edit and a deploy, not a
setting somebody clicks.

> **Re-seeding an existing database deactivates; it does not delete.** The ten
> retired placeholder office codes (MO, SB, MTO, MACC, MBO, MPDO, MEO, MASSO,
> MCR, MITO) and the retired sample document types are marked inactive and kept,
> because documents and control numbers already issued point at them. Anything
> registered under an old code keeps resolving and keeps its number; the code
> just stops being offered for new work.
>
> **But move the people first.** Deactivating an office does not move the users
> in it, and a user left in a deactivated office **cannot file anything**: their
> own office is no longer offered on the Submit form, and a posted request is
> refused with "The selected department is invalid." — which tells them nothing
> about why. Their existing documents stay visible and searchable; it is only
> new work that stops. Verified on 2026-08-18 against a database seeded with the
> old list and then re-seeded with the new one.
>
> Note that earlier versions of this runbook told you to create staff with
> `--office=MPDO`, which is one of the ten codes above. An installation built by
> following those instructions has **every** clerk stranded on the morning after
> the re-seed.
>
> So before or immediately after re-seeding an installation that was running the
> placeholder offices, list anyone stranded and reassign them:
>
> ```sh
> php artisan tinker --execute='App\Models\User::query()
>     ->whereHas("office", fn ($q) => $q->where("is_active", false))
>     ->get(["email", "office_id"])->each(fn ($u) => print($u->email." -> ".$u->office->code.PHP_EOL));'
>
> # then, per user, with the real office code:
> php artisan cicto:user someone@baliwag.gov.ph --office=OCM
> ```
>
> Moving a plain **user** is harmless — they are scoped by what they created.
> Moving an **Admin** re-cuts what they can see: office admins are scoped by
> their current office, so a department head moved out of MPDO stops seeing
> MPDO's backlog the moment the command runs. That is correct, and it is still
> a surprise if nobody said so.
>
> A fresh installation seeded straight from the client's list has nobody to move.
>
> **One SLA changes silently.** Three placeholder types are re-used rather than
> retired because they name the same thing as a real one — Disbursement Voucher,
> Purchase Order and Travel Order — and re-seeding clears their invented
> turnaround days along with everyone else's. A Disbursement Voucher that gave
> ten days before the upgrade gives the provisional three afterwards; Purchase
> Order drops from seven, Travel Order rises from two. Nothing already
> registered is rewritten (`due_at` is stamped once), but expect the first
> week's overdue count on new work to run higher than the office is used to,
> until ARO supplies the real figures.

Do **not** run `db:seed` with no arguments in production: `DatabaseSeeder` also
creates the four demo accounts.

Then:

```bash
php artisan cicto:create-super-admin      # prompts; creates the real account
```

### Creating the real staff accounts

Two of these have a screen now and the rest do not. **Manage Users**
(`/super-admin/users`, Super Admin only) creates an account and sets a password
for an existing one. **Role, office and active state are still console-only** —
those are the three operations an attacker wants and an LGU auditor asks about,
and putting them behind SSH rather than a session is the cheapest defence
available.

```bash
php artisan cicto:user clerk@baliwag.gov.ph --name="Maria Santos" --role=user --office=OCM
php artisan cicto:user head@baliwag.gov.ph  --name="Jose Cruz"   --role=admin --office=OCM

php artisan cicto:user someone@baliwag.gov.ph --role=admin        # change a role
php artisan cicto:user someone@baliwag.gov.ph --office=HRMO       # move an office
php artisan cicto:user someone@baliwag.gov.ph --deactivate        # close, never delete
```

Run it with no options to see an account's current role, office and state.

#### When somebody forgets their password

Client question **B3**, answered 2026-08-20, meant there was no reset link at
all: the Forgot Password page said so and pointed at you. **Superseded
2026-08-23** — the operator stood up Google SMTP (§3), so Forgot Password takes
an address and sends a genuine reset link, and most forgotten passwords now never
reach you at all.

What follows is for the ones that still do: an address the person can no longer
read, a mailbox that bounces, a day the Gmail quota is spent, a transport that is
down. Two things to recognise before you reach for the screen. Reset requests are
capped at **10 per IP per hour**, so somebody who has been hammering Send is told
to wait rather than mailed again — that is the throttle working, not a fault. And
a reset link proves possession of a mailbox and nothing else, so if the account
may be in somebody else's hands, read the two-factor paragraph below before you
decide the link was enough.

**From the screen, when the link cannot reach them.** Manage Users → the
person's row → **Set password**. You confirm your own password, type theirs, and
hand it to them.
They are signed out on every device they were signed in on, so tell them that
before they ask why their phone logged out.

**Tick "also remove two-factor and passkeys" only when you mean it.** Leave it
off for an ordinary forgotten password. Tick it if the account may be in
somebody else's hands — a passkey signs its holder in without ever asking for
the password, so a reset on its own revokes nothing — or if they have lost the
phone holding their two-factor codes, because otherwise the new password still
will not get them in.

**When nobody can sign in at all**, including every Super Admin, the screen
cannot help and this is the way back:

```bash
php artisan cicto:user super@baliwag.gov.ph --reset-password

# ...and, if two-factor or a passkey is what is actually blocking them:
php artisan cicto:user super@baliwag.gov.ph --reset-password --revoke-second-factors
```

It generates a password, prints it once, rotates the remember-me token, deletes
any outstanding reset token, ends the account's live sessions and writes the
whole thing to the security log. **Then clear it from your shell or hosting
panel history** — the password is in that output. There is deliberately no
`--password` option for the same reason.

Two things it will do that are worth knowing before you need them:

- **It refuses to create an account.** Every other option on `cicto:user`
  creates the account if the address does not exist; `--reset-password` fails
  instead. You are typing an address from memory in the one situation where you
  cannot look it up, and `supe@` rather than `super@` would otherwise mint a
  second account, print you a working password for it, and report success while
  the real account stayed locked.
- **It warns rather than assumes on second factors.** Without
  `--revoke-second-factors` it leaves two-factor and passkeys alone and tells
  you they are still there, because the password it just printed will not get
  anybody past them. Clearing somebody's authenticator uninvited is its own kind
  of damage; being told the rescue is incomplete is not.

> Ending live sessions needs `SESSION_DRIVER=database`, which §3 already
> requires and §1.3 explains. On any other driver the reset still works and the
> screen says plainly that the other devices could not be signed out — which on
> a compromised account is the difference between a fixed problem and one you
> have been told is fixed.

> **Say this to the client.** A new clerk, a transfer or someone leaving still
> needs somebody with server access, because role, office and deactivation have
> no screen. That is fine for go-live and unsustainable as a permanent
> arrangement; it is the enforcement half of feature #11 delivered without all
> of the administration half, and the missing screens are not costed anywhere.

Delete every demo account before go-live:

```bash
# Deactivate rather than delete. If a demo account touched anything during UAT,
# deleting it nulls actor_id across document_movements and rewrites that
# history; and documents.created_by_id is restrictOnDelete, so the delete would
# fail anyway.
#
# All five are listed: DatabaseSeeder makes four, and cicto:demo-data adds
# sb@cicto.test in SP for the office-isolation test.
for e in super@cicto.test admin@cicto.test mto@cicto.test sb@cicto.test \
         clerk@cicto.test; do
  php artisan cicto:user "$e" --deactivate
done
```

---

## 5. Caches and permissions

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan storage:link

chmod -R ug+rw storage bootstrap/cache
```

> Re-run `config:cache` after **any** `.env` change — and after **any deploy
> that changes a default in `config/cicto.php`**, which this release does:
> office hours, the support office name, the close-of-business hour and the
> version retention window all moved in code, not in `.env`. A cached config is
> a frozen snapshot: it ignores the file and the new defaults alike, and this is
> the single most common cause of "I changed the setting and nothing happened".

---

## 6. Cron

**On Laravel Cloud there is no crontab.** Enable the scheduler in the
environment's compute settings instead, and skip the entry below — §1.2.

On a VPS, one entry. Everything else is scheduled inside the app.

```cron
* * * * * cd /path/to/cicto && php artisan schedule:run >> /dev/null 2>&1
```

**That is the only background process there is. Do not add a `queue:work` entry**
— nothing in CICTO is queued, mail included. Mail is sent inline for precisely
the reason this section exists: a host that can forget the scheduler can forget a
worker just as easily, and queued mail with no worker running is written to the
database and never sent while every screen reports that it was. §3, "Outgoing
mail".

Confirm it is actually firing:

```bash
php artisan schedule:list
```

`schedule:list` shows what is *registered*, which is not the same as what is
*running*: it prints the same three commands on a host where nothing has ever
called `schedule:run`. The proof is a `backup_runs` row appearing overnight.

If the host offers neither cron nor a scheduler, say so in writing. Deadline
warnings, overdue notices and backups will not happen on their own.

---

## 7. APP_KEY escrow

`APP_KEY` decrypts the encrypted settings column. **Lose it and every encrypted
setting is unrecoverable** — not "inconvenient", unrecoverable. It also signs the
session cookies and the signed URLs the verification links are built from, so
rotating it signs everybody out and voids any verification or reset link still in
flight.

**It does not protect the mail credential, and earlier drafts of this section
said it did.** There is no stored SMTP password: the App Password lives in `.env`
— or in the hosting panel's environment settings, which is the same thing on
Laravel Cloud — and nowhere else. The application never writes it to the
database, so `APP_KEY` neither guards it nor can lose it. Escrow it separately,
wherever the LGU keeps the database password. It is the same class of secret and
the same problem on the day the person holding it leaves.

1. Copy the value out of `.env`.
2. Seal it somewhere that survives the developer, the laptop and the hosting
   account. A sealed envelope with the municipal treasurer is a legitimate
   answer.
3. Have the recipient sign for it.
4. Record the date here: `________________`

Rotating it later requires re-entering every encrypted setting by hand.

---

## 8. Prove the backup works

§22 says Backup **and Recovery**. A backup nobody has restored is a hypothesis.

```bash
php artisan cicto:backup                  # writes to the configured disk
```

> **When `CICTO_BACKUP_DISK_DRIVER=s3`, the artifact is not on the server.**
> The dump and the zip are still built locally, in `storage/app/backup-staging`,
> but they are streamed to the bucket, checksummed, verified present and the
> staging copy deleted, so there is nothing left on disk to point `gunzip` at.
> Download the object from the bucket first — Super Admin → System Settings
> lists every run with the disk it used and the path it wrote, and that path is
> the object key. Run the rest anywhere you have `psql` and a scratch database.
>
> **And know what the drill proves on this host.** When the documents live in
> object storage the nightly run is `kind = database`, so a successful restore
> proves the register, the ledger and the signatures come back. It proves
> nothing about the document bytes, which are not in the archive at all. What
> protects those is the bucket, under the conditions in §10.

Then restore it into a scratch database — never production:

```bash
createdb cicto_restore_test
# PostgreSQL, PhpDumper (data-only) — migrate FIRST, then load
DB_DATABASE=cicto_restore_test php artisan migrate --force
gunzip -c storage/app/backups/cicto-<stamp>.sql.gz \
  | psql -h 127.0.0.1 -U cicto -d cicto_restore_test -v ON_ERROR_STOP=1
```

Then check the restore is actually usable, which is the step people skip:

```bash
DB_DATABASE=cicto_restore_test php artisan tinker --execute="
  echo App\Models\Document::count(), ' documents', PHP_EOL;
"
```

Record the drill in the app so the UI stops warning that backups are untested:
**Super Admin → System Settings → mark restore verified**.

Finally, get the backup **off this server**. A backup on the same disk as the
database survives nothing that matters. On Laravel Cloud that is
`CICTO_BACKUP_DISK_DRIVER=s3` pointed at a bucket that is **not** the documents
bucket — §1.1. Getting this wrong is not obvious from the outside: the run
succeeds, the row says `Completed`, and the archive is sitting in the one place
whose loss it was supposed to insure against.

---

## 9. Go-live checks

- [ ] `https://` in the address bar, valid certificate
- [ ] `CICTO_SCAN_BASE_URL` emits `https://` — print **one** label and scan it
- [ ] Register → forward → receive → approve → complete, end to end
- [ ] A QR label scans to the right document from a phone
- [ ] `cicto:host-check` on the real environment reports **SMTP handshake: OK
      authenticated**. The two mail rows above it read `OK` on a host where every
      send fails, so they are not the check
- [ ] Forgot Password sends a link that actually arrives, and the link sets a
      password that then signs in
- [ ] A test self-registration receives its verification email, and is refused
      every protected screen until the link in it is clicked — then deactivate
      that account with `cicto:user <address> --deactivate`
- [ ] A Super Admin can set a password from Manage Users, and the person it was
      set for signs in with it
- [ ] Each of the three roles sees only its own panel
- [ ] `APP_DEBUG=false` — visit a bad URL and confirm no stack trace
- [ ] Backup ran, restore drilled, off-site copy exists
- [ ] `cicto:host-check` was run **on the real environment** and its output filed
- [ ] On Laravel Cloud: the documents disk reports `s3`, and the
      **Documents durable?** row is absent. If that row is showing, stop — the
      next deploy erases every document and nothing will say so
- [ ] The latest `backup_runs` row reads `kind = full` — **or** reads `database`
      *because the documents are in object storage*, which is correct and
      expected on Laravel Cloud. `database` on a host with a local documents
      disk is a fault: the uploaded documents are then in NO backup
- [ ] Object versioning is enabled on the documents bucket, or its absence is
      acknowledged in writing (§10)
- [ ] Demo accounts deleted
- [ ] `storage/logs` is writable and rotating — or, on Laravel Cloud, everyone
      who will need logs after an incident knows they are in the log stream and
      not on the server (§1.5)

---

## 10. Known limits to state in writing

Do not let these surface as surprises three months in.

- **Deadlines count calendar days, and the counter works four of them.** The
  client confirmed Monday to Thursday, 7:00 AM to 6:00 PM.
  `CICTO_BUSINESS_END_HOUR` sets the *hour* a deadline lands on; nothing in the
  system knows which *days* the counter is open. So a three-day turnaround filed
  on a Tuesday falls due Friday, on a Wednesday falls due Saturday, and on a
  Thursday falls due Sunday — and the 08:00 sweep raises an overdue notice for it
  on a morning nobody was ever at the desk. (In the app, not by email: the sweep
  sends no mail even now that SMTP works — §1.2.) Roughly three filing days in
  seven land this way. Teaching the clock about working days needs a holidays table
  reseeded every year by proclamation, which is a separate quote; see decision
  D18. Say this to the LGU before they see their first Monday backlog.
- **Every document currently shares one deadline.** All 43 document types seed
  `turnaround_days` NULL until the City Archive and Records Office supplies the
  real figures, so `CICTO_DEFAULT_TURNAROUND_DAYS` (3) is the SLA for all of
  them. Two documents filed the same day therefore fall due at the same instant
  whatever they are: the register shows nothing flagged, then everything amber
  on one morning, then everything red two mornings later. It is a placeholder
  SLA behaving correctly, and it resolves itself when the per-type numbers land.
- **Search is a sequential scan.** `lower(col) like '%term%'` cannot use a
  B-tree index. Fine at LGU volume (single-digit thousands a year); past roughly
  200k documents it is not, and the fix is driver-specific (`pg_trgm` GIN vs
  MySQL `FULLTEXT`) which `docs/DATABASE.md` deliberately forbids. The *filters*
  do use indexes.
- **Signatures are electronic, not PKI.** Identity, a captured mark, a timestamp
  and a hash binding them to one exact file version. No certificate authority,
  no revocation, nothing embedded in the PDF. Court-grade signing is PNPKI and
  separate work.
- **Exports are synchronous** and capped — 1,000 rows for PDF, 25,000 for Excel.
  Past that, narrow the range or use CSV.
- **The backup archive is not encrypted.** `cicto:backup` writes a plain SQL
  dump, and on a host where file coverage is on, a plain ZIP holding that dump
  *and every uploaded document*. Either way it is the most confidential single
  file the LGU will produce. Restrict the destination accordingly: filesystem
  permissions on a VPS, and on Laravel Cloud a bucket that is genuinely
  **private**, because a readable bucket makes it a public records dump one URL
  away.
- **File coverage is conditional, and on Laravel Cloud it is off.** Documents
  are folded into the archive only when the host has `ZipArchive` **and** the
  documents disk is local; `cicto:host-check` and the Super Admin settings page
  both report which. `BackupService::canArchiveFiles()` returns false for a
  remote documents disk on purpose — a bucket cannot be walked cheaply, and a
  container's scratch space, sized at roughly 512MB per 1GB of RAM, could not
  hold the result anyway — so the run is honestly recorded as `kind = database`
  rather than claiming a coverage it does not have. On a host with a *local*
  documents disk that same value is a fault: the files are in no backup, and a
  disk loss takes every document with it while every signature reports as
  failing.
- **With documents in object storage, the nightly backup covers the DATABASE
  ONLY, and the bytes are protected by the bucket instead.** This is the trade
  the Laravel Cloud move makes, and it must be said in writing before sign-off
  rather than discovered after an incident. The bucket's own durability guards
  against hardware failure — that part is genuinely better than a disk. It does
  **not** guard against a document being deleted or overwritten, by a bug, by a
  mistaken credential, or by anyone holding the key: object storage overwrites
  in place and the previous bytes are gone. The only thing that protects against
  that is **object versioning on the documents bucket**, which is a setting on
  the bucket, outside this application, and it is off unless somebody turns it
  on deliberately. So either enable versioning and agree a lifecycle policy for
  how long old versions are kept, or state plainly that a deleted document is
  unrecoverable and that the backup will not bring it back.
- **Every pruner ships disabled.** Nothing deletes municipal records until
  somebody enables it, in writing (client question **B6**). A figure now exists
  and the sign-off does not: on 2026-08-18 the client said "3 to 5 years minimum"
  and that past records also sit on their cloud server, so
  `CICTO_VERSION_RETENTION_DAYS` moved from 180 days to the 1095-day floor of
  that range. A number given in chat is not written sign-off and does not name a
  confirmed off-site copy, so the pruners stay off. The 180-day scan-log
  retention was deliberately **not** raised to match — it holds IP addresses and
  user agents, which are personal data under RA 10173, and 180 days is published
  as a promise on the public privacy notice.
- **Support tickets email; there is no ticket queue.** Since 2026-08-23 they are
  genuinely delivered, to `CICTO_SUPPORT_EMAIL`, as ordinary mail. What does not
  exist is everything after that: no ticket table, no status, no reply thread, no
  admin inbox in the application. Whoever reads that mailbox *is* the ticket
  system, and if nobody is assigned to read it the ticket is as lost as it ever
  was. Every ticket is written to the log as well, so one that fails to send is
  recorded rather than dropped, and the sender is told it was recorded and not
  delivered rather than thanked.
- **All of the outgoing mail leaves through one free Gmail account.** Password
  resets, verification links and support tickets share a single App Password and
  its cap of roughly 500 recipients a day, and when that is spent the provider
  refuses everything for 24 hours — all three at once. There is no second
  transport to fall back to and nothing to fail over. This belongs in writing
  next to the bullet below, because it is the reason the administrator route is
  not being decommissioned now that mail works.
- **A forgotten password now has an inbox, and still needs an administrator
  behind it.** Forgot Password sends a real reset link as of 2026-08-23, capped
  at 10 requests per IP per hour. It reaches nobody whose recorded address is
  wrong or unreadable, nobody whose mailbox bounces, and nobody at all on a day
  the quota is spent — so Manage Users → **Set password** remains the supported
  fallback, and somebody in the LGU has to stay reachable to work it. If the only
  Super Admin leaves without handing over, the way back in is still SSH.
- **New self-registrations are gated on email.** `MustVerifyEmail` is enforced as
  of 2026-08-23, so an account that registers itself sees the verification notice
  and nothing else until the link arrives and is clicked. Accounts created by an
  administrator are stamped verified and never meet it. The consequence to state:
  on a day outgoing mail is down, self-registration is closed in practice, and
  the way in is for an administrator to create the account instead.
- **Nothing is queued, mail included.** There is no worker to run and no failed
  jobs to watch, which removes a whole class of silent failure and buys it back
  as one to three seconds on the handful of requests that send mail. §3,
  "Outgoing mail".

---

## 11. Still owed by the client

Deployment can proceed without these, but the gaps stay open.

| Question | Blocks |
| --- | --- |
| **A1** — *the blocker is gone, the decision is not made.* Laravel Cloud issues the certificate, so HTTPS no longer waits on the LGU. Still needed: **which custom hostname they intend to keep**, since it is baked into `CICTO_SCAN_BASE_URL` and printed onto every label, plus whether USB scanners are being bought | Printing QR labels at volume |
| **A4** — *part delivered 2026-08-18.* The 53 offices, their codes and the 43 document types are seeded. **Turnaround days per type** are not: that question went to ARO and has not come back | Per-type deadlines. Every type shares the three-day `CICTO_DEFAULT_TURNAROUND_DAYS` until they do |
| **B1** — the signature paragraph, acknowledged | §15 sign-off |
| **B2** — *mostly answered 2026-08-18.* The host is **Laravel Cloud**: HTTPS is issued by the platform, the scheduler runs the three commands in `routes/console.php`, and the off-site destination is Laravel Cloud Object Storage. Still open: **who performs and who tests the restore drill**, object versioning on the documents bucket (§10), and whether `proc_open` and `pg_dump`/`mysqldump` exist there — `cicto:host-check` answers that last one on the real environment | §22 sign-off |
| ~~**B3** — SMTP credentials~~ *(ANSWERED 2026-08-20, closed against us; SUPERSEDED 2026-08-23.)* CICTO will not supply them and recommends an external service; in their place they asked for, and got, a Super Admin password-reset module. That answer stands and nothing further is owed by the client — but the recommendation was taken: on 2026-08-23 the operator stood up Google SMTP on a Gmail App Password, so reset links, verification links and support tickets are delivered, and §3's "Outgoing mail" records what keeps them working and what takes them down. The password-reset module remains the supported fallback | Nothing. §12 notification email stays a change order now that SMTP is configured, exactly as it did before |
| **B4** — real `.xlsx` or CSV acceptable | §19 sign-off |
| **B6** — *part answered 2026-08-18.* A floor was given — "3 to 5 years minimum" — and the code holds 1095 days. Still missing: the exact figure (with ARO), written sign-off, and a confirmed off-site copy | Enabling any pruner |

Also undefined by the contract and worth settling now: **who installs it**,
**training** (not in the cost breakdown), and **the warranty window**.

## Practice accounts for client testing

`db:seed` deliberately creates no accounts in production. The five logins named
in the client testing checklist come from a separate command, run on purpose:

```sh
php artisan db:seed --force          # offices and document types
php artisan cicto:demo-data --force  # the five practice logins + sample documents
```

It prints the credentials table and creates nine sample documents, including
the Sangguniang Panlungsod one the checklist's office-isolation test depends on
— the test being that an Office of the City Mayor account cannot see it.
Running it twice changes nothing.

The five accounts now sit in the client's real offices rather than the retired
placeholder codes: `admin@cicto.test` and `clerk@cicto.test` in **OCM** (Office
of the City Mayor), `mto@cicto.test` in **TREA** (Office of the City Treasurer),
`sb@cicto.test` in **SP** (Office of the Sangguniang Panlungsod), and
`super@cicto.test` in no office at all, as before. **The addresses and the
password are unchanged** — only the office each account belongs to and its
display name moved. Their sample documents are therefore numbered under the real
prefixes, `OCM-2026-00001` and so on, which is also the form the help screen's
search example uses.

**All five share the password `password`, which is printed in the client's
documentation.** Anyone who finds the site can sign in as Super Admin. That is
tolerable while the register holds nothing but practice data and intolerable the
moment it does not. Before go-live:

```sh
php artisan cicto:demo-data --remove
```

That deletes the accounts and every document they raised, including
soft-deleted ones, and leaves offices and document types alone. The audit record
that practice accounts once existed survives on purpose.

Real accounts are made with `cicto:create-super-admin` and `cicto:user`, or from
Manage Users once a Super Admin exists. Both commands work without a terminal:
on a host with no TTY they generate a password and print it once.
