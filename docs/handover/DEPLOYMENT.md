# Deployment runbook

Everything below is a command you run or a value you check. Work top to bottom.
Nothing here is optional except where it says so.

Contract §6 transfers source and full rights on final payment and **says nothing
about who installs it**. Settle that before the day you plan to deploy.

---

## 0. Before you touch the server

Four things must be true or the deployment stalls half-finished.

| Need | Why it stops you | Fallback if absent |
| --- | --- | --- |
| **HTTPS on the real hostname** | The camera QR scanner cannot run on plain HTTP — a browser rule, not a setting. Secure cookies and HSTS also depend on it | Ship with the USB scanner only; the app says so on the scan page |
| **A cron entry** | Deadline warnings, overdue notices, nightly signature verification and backups all hang off `schedule:run` | Nothing runs by itself. Someone must trigger the commands by hand |
| **`proc_open` enabled** | `pg_dump`/`mysqldump` need it | `PhpDumper` takes over automatically and produces a data-only dump. Restore then needs `migrate` first |
| **SMTP credentials** | Password resets, notifications and support tickets | Password reset is unusable. Say this in writing before go-live |

Run the probe and keep the output:

```bash
php artisan cicto:host-check
```

---

## 1. Code and dependencies

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

## 2. Environment

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

# Printed onto paper. Get it right before any label is issued at volume.
CICTO_SCAN_BASE_URL=https://cicto.example.gov.ph

CICTO_SUPPORT_OFFICE="Municipal Information Technology Office"
CICTO_SUPPORT_EMAIL=ict@example.gov.ph
CICTO_SUPPORT_PHONE="(044) 000 0000"
CICTO_PRIVACY_CONTACT="Data Protection Officer, ..."
```

### The three settings that are hard to undo

- **`CICTO_SCAN_BASE_URL`** is baked into every printed QR label. Behind a
  reverse proxy `url()` routinely emits `http://` or an internal hostname, which
  is why this is configured rather than derived. A wrong value here cannot be
  fixed without reprinting every label already taped to a folder. The app
  refuses to boot in production if it is not `https://`.
- **`CICTO_HSTS=true`** makes browsers *remember* to refuse plain HTTP for a
  year. Set it on a host without TLS and you have locked users out in a way
  clearing the cache does not fix. Leave it off until HTTPS is confirmed.
- **`CICTO_CSP_ENFORCE=true`** turns the Content-Security-Policy from
  report-only into blocking. Watch `storage/logs/csp-*.log` for a week first.

---

## 3. Database

```bash
php artisan migrate --force
php artisan db:seed --class=OfficeSeeder --force
php artisan db:seed --class=DocumentTypeSeeder --force
```

Those two seeders carry the sample offices and document types. **Edit them to
the client's real values before running** — office names and codes, document
types and their turnaround days (client question **A4**). Do not ship the
samples.

Do **not** run `db:seed` with no arguments in production: `DatabaseSeeder` also
creates the four demo accounts.

Then:

```bash
php artisan cicto:create-super-admin      # prompts; creates the real account
```

### Creating the real staff accounts

There is **no user-management screen yet** — Manage Users is a routed
placeholder. Accounts are created and assigned from the console:

```bash
php artisan cicto:user clerk@baliwag.gov.ph --name="Maria Santos" --role=user --office=MPDO
php artisan cicto:user head@baliwag.gov.ph  --name="Jose Cruz"   --role=admin --office=MPDO

php artisan cicto:user someone@baliwag.gov.ph --role=admin        # change a role
php artisan cicto:user someone@baliwag.gov.ph --office=HRMO       # move an office
php artisan cicto:user someone@baliwag.gov.ph --deactivate        # close, never delete
```

Run it with no options to see an account's current role, office and state.

> **Say this to the client.** Until the Manage Users screen is built, every
> staffing change — a new clerk, a transfer, someone leaving — needs somebody
> with server access. That is fine for go-live and unsustainable as a permanent
> arrangement. It is the enforcement half of feature #11 delivered without the
> administration half, and the screen is not costed anywhere.

Delete every demo account before go-live:

```bash
# Deactivate rather than delete. If a demo account touched anything during UAT,
# deleting it nulls actor_id across document_movements and rewrites that
# history; and documents.created_by_id is restrictOnDelete, so the delete would
# fail anyway.
for e in super@cicto.test admin@cicto.test mto@cicto.test clerk@cicto.test; do
  php artisan cicto:user "$e" --deactivate
done
```

---

## 4. Caches and permissions

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan storage:link

chmod -R ug+rw storage bootstrap/cache
```

> Re-run `config:cache` after **any** `.env` change. A cached config ignores the
> file entirely, and this is the single most common cause of "I changed the
> setting and nothing happened".

---

## 5. Cron

One entry. Everything else is scheduled inside the app.

```cron
* * * * * cd /path/to/cicto && php artisan schedule:run >> /dev/null 2>&1
```

Confirm it is actually firing:

```bash
php artisan schedule:list
```

If the host offers no cron, say so in writing. Deadline warnings, overdue
notices and backups will not happen on their own.

---

## 6. APP_KEY escrow

`APP_KEY` decrypts the encrypted settings column. **Lose it and the stored SMTP
password and any other encrypted setting are unrecoverable** — not
"inconvenient", unrecoverable.

1. Copy the value out of `.env`.
2. Seal it somewhere that survives the developer, the laptop and the hosting
   account. A sealed envelope with the municipal treasurer is a legitimate
   answer.
3. Have the recipient sign for it.
4. Record the date here: `________________`

Rotating it later requires re-entering every encrypted setting by hand.

---

## 7. Prove the backup works

§22 says Backup **and Recovery**. A backup nobody has restored is a hypothesis.

```bash
php artisan cicto:backup                  # writes to the configured disk
```

Then restore it into a scratch database — not production:

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
database survives nothing that matters.

---

## 8. Go-live checks

- [ ] `https://` in the address bar, valid certificate
- [ ] `CICTO_SCAN_BASE_URL` emits `https://` — print **one** label and scan it
- [ ] Register → forward → receive → approve → complete, end to end
- [ ] A QR label scans to the right document from a phone
- [ ] Password reset email arrives (or SMTP absence is acknowledged in writing)
- [ ] Each of the three roles sees only its own panel
- [ ] `APP_DEBUG=false` — visit a bad URL and confirm no stack trace
- [ ] Backup ran, restore drilled, off-site copy exists
- [ ] The latest `backup_runs` row reads `kind = full` — if it reads `database`,
      the uploaded documents are in NO backup
- [ ] Demo accounts deleted
- [ ] `storage/logs` is writable and rotating

---

## 9. Known limits to state in writing

Do not let these surface as surprises three months in.

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
- **The backup archive is not encrypted.** `cicto:backup` writes a plain ZIP
  containing the SQL dump *and every uploaded document*. It is the most
  confidential single file the LGU will produce. Restrict the destination and
  its filesystem permissions accordingly.
- **File coverage is conditional.** Documents are only included when the host
  has `ZipArchive` and the documents disk is local — `cicto:host-check` and the
  Super Admin settings page both report which. A run recorded as
  `kind = database` covers the database ONLY; the uploaded files then need their
  own off-host copy, or a disk loss takes every document with it and every
  signature will report as failing.
- **Every pruner ships disabled.** Nothing deletes municipal records until
  somebody enables it, in writing (client question **B6**).
- **Support tickets email; there is no ticket queue.** With no SMTP configured
  they are recorded in the log and the UI says so.

---

## 10. Still owed by the client

Deployment can proceed without these, but the gaps stay open.

| Question | Blocks |
| --- | --- |
| **A4** — real offices, codes, document types, turnaround days | Reference data seeding |
| **B1** — the signature paragraph, acknowledged | §15 sign-off |
| **B2** — host capabilities and off-site backup destination | §22 sign-off |
| **B3** — SMTP credentials | Password reset, notifications, tickets |
| **B4** — real `.xlsx` or CSV acceptable | §19 sign-off |
| **B6** — retention periods agreed | Enabling any pruner |

Also undefined by the contract and worth settling now: **who installs it**,
**training** (not in the cost breakdown), and **the warranty window**.
