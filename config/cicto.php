<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scan base URL
    |--------------------------------------------------------------------------
    |
    | The absolute base URL baked into every printed QR label. This comes from
    | config, never from the request: behind a shared-host reverse proxy url()
    | frequently emits http:// or an internal hostname, and that wrong URL gets
    | printed onto paper and taped to a folder. It is unfixable without
    | reprinting every label.
    |
    | AppServiceProvider asserts this is https:// in production.
    |
    */

    'scan_base_url' => rtrim((string) env('CICTO_SCAN_BASE_URL', (string) env('APP_URL')), '/'),

    /*
    |--------------------------------------------------------------------------
    | Deadlines
    |--------------------------------------------------------------------------
    |
    | approaching_days is a fixed number of calendar days, not a percentage of
    | turnaround: it keeps the "approaching deadline" predicate a single bound
    | datetime instead of a per-row computed comparison.
    |
    | KNOWN INTERACTION, and it still needs a decision from the client
    | (question A4): any document type whose turnaround_days is <= this value is
    | flagged "due soon" from the moment it is registered, which makes the badge
    | meaningless for that type. Keep this number BELOW the shortest real
    | turnaround. A per-type warning window would need date arithmetic across
    | two columns, which is not portable between PostgreSQL and MySQL without
    | branching -- out of scope for a PHP 500 line item.
    |
    | AS OF 2026-08-18 default_turnaround_days is the ONLY turnaround in the
    | system. The client supplied the real document types but answered "how many
    | days should each take?" with "ARO" -- the records office, who had not been
    | asked yet -- so DocumentTypeSeeder seeds turnaround_days NULL on every row
    | rather than inventing numbers, and Deadlines::dueAt falls back to the
    | value below for all of them. Every document therefore carries the same
    | provisional SLA. Raise CICTO_DEFAULT_TURNAROUND_DAYS if 3 days makes the
    | overdue counts noise; the per-type numbers land in DocumentTypeSeeder when
    | ARO answers.
    |
    | MEASURED CONSEQUENCE of one shared turnaround, so nobody reads it as a
    | fault: two documents filed on the same day get a byte-identical due_at
    | whatever their type, so the backlog does not degrade gradually -- it goes
    | from nothing flagged, to every open document amber on one morning, to
    | every one red two mornings later, and the 08:00 sweep mails that whole set
    | and then repeats the overdue reminder daily until each is closed. Nothing
    | here is wrong; it is what a placeholder SLA looks like at scale. Raising
    | this value staggers nothing -- due_at is stamped at registration and never
    | recomputed, so it only affects documents registered after the change.
    |
    | Calendar days, not working days. A working-day engine needs a holidays
    | table reseeded every year (Philippine holidays move by proclamation), and
    | an unseeded table silently falls back to calendar days and reports wrong
    | SLAs -- worse than not offering the feature. See docs/implementation D18.
    |
    | business_end_hour is 18 because the client confirmed on 2026-08-18 that
    | the counter works Monday to Thursday, 7:00 AM to 6:00 PM. It exists so a
    | deadline lands at the close of business rather than at 03:00, where it
    | would read as overdue to someone who had the whole working day. There is
    | no business_start_hour because nothing needs one -- deadlines are always
    | measured to a close, never from an open.
    |
    | Note what this key CANNOT express: a four-day working week. Deadlines are
    | calendar days (D18), so a 3-day turnaround filed on Thursday still falls
    | due on Sunday with the counter shut Friday to Sunday. Making the clock
    | skip non-working days is the holidays-table feature above, and it is out
    | of scope for §11 as priced.
    |
    */

    'deadlines' => [
        'approaching_days' => (int) env('CICTO_APPROACHING_DAYS', 2),
        'default_turnaround_days' => (int) env('CICTO_DEFAULT_TURNAROUND_DAYS', 3),
        'business_end_hour' => (int) env('CICTO_BUSINESS_END_HOUR', 18),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow
    |--------------------------------------------------------------------------
    |
    | allow_self_approval answers client question A6: may an Admin approve a
    | document they submitted themselves? Separation of duties says no; a
    | two-person municipal office says that blocks real work. Default to the
    | safe reading and let the client flip it.
    |
    | THIS IS THE BOOT DEFAULT ONLY. On 2026-08-18 the client's note against A6
    | read "they can allow or block it" -- i.e. the decision is theirs to make
    | and change, not ours to bake into a deployment. A Super Admin can now
    | override this from the settings screen; the override is stored in
    | app_settings under `workflow.allow_self_approval` and is what
    | DocumentPolicy actually reads. This value decides the answer only until
    | somebody sets it there. See App\Support\SystemSettings.
    |
    */

    'workflow' => [
        'allow_self_approval' => (bool) env('CICTO_ALLOW_SELF_APPROVAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email notifications
    |--------------------------------------------------------------------------
    |
    | Asked for on 2026-09-24: when a document is sent to an office, its staff
    | are told by email as well as by the bell. See App\Services\DocumentMailer.
    |
    | Nothing is sent while MAIL_MAILER is `log` (see App\Support\OutgoingMail),
    | whatever this says. The switch exists for the other direction: every email
    | leaves through the one Gmail account, whose ~500 recipients a day are
    | shared with password resets and support tickets. If the notifications
    | ever start eating that quota, set CICTO_EMAIL_NOTIFICATIONS=false and the
    | bell carries on alone -- no deploy needed.
    |
    */

    'notifications' => [
        'email' => (bool) env('CICTO_EMAIL_NOTIFICATIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security PIN
    |--------------------------------------------------------------------------
    |
    | Client request, 2026-09-25: a 4-digit PIN, chosen by each person, asked
    | for before a document is shown. The adviser's scenario was a computer
    | left signed in with a document open -- so the PIN is asked for again
    | after `idle_minutes` without activity, not only once per sign-in.
    |
    | It gates VIEWING a document (the View Documents page, its files and the
    | actions on it). The list of documents, filing a new one and every other
    | page are untouched -- "the pin will pop up only when viewing documents".
    |
    | `max_attempts` wrong PINs in a row sign the session out. Four digits is
    | ten thousand combinations; without a ceiling, somebody at an unattended
    | desk could simply try them. Signing out puts the account password -- the
    | stronger secret -- back in front of them.
    |
    | `enabled` is the off switch for the whole feature, e.g. for a training
    | host. Turning it off never deletes anybody's PIN.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sign-in code (login OTP)
    |--------------------------------------------------------------------------
    |
    | Client request, 2026-09-25: signing in went "rekta dashboard" on a
    | password alone. Now a correct password emails a 6-digit code, and the
    | dashboard opens only after it is typed. Every account, every sign-in --
    | except one that already uses an authenticator app, which answers that
    | challenge instead.
    |
    | IT DEPENDS ON EMAIL. An account whose address is not a real inbox cannot
    | sign in while this is on -- which includes the {code}.admin@ / .clerk@
    | accounts OfficeAccountSeeder creates. Give each person a real address
    | first. Every sign-in is one email from the same ~500-a-day Gmail allowance
    | as notifications; if mail stops, nobody can sign in, and `enabled=false`
    | (CICTO_LOGIN_OTP=false, then config:cache) is the way back in.
    |
    */

    'login_otp' => [
        'enabled' => (bool) env('CICTO_LOGIN_OTP', true),
        'ttl_minutes' => (int) env('CICTO_LOGIN_OTP_TTL_MINUTES', 10),
        'max_attempts' => 5,
        'resend_seconds' => 60,
        // Codes one account may be sent in 15 minutes: protects the daily
        // Gmail allowance from somebody who knows a password.
        'max_sends' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidential documents
    |--------------------------------------------------------------------------
    |
    | The client's routing paths (2026-09-25): Confidential is "City Mayor /
    | HRMO only". The offices, by code, that may receive and read one. Nobody
    | else sees it -- not the rest of the office that filed it, and not a Super
    | Admin -- except the person who filed it. See App\Support\Confidential.
    |
    */

    'confidential' => [
        'offices' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CICTO_CONFIDENTIAL_OFFICES', 'OCM,HRMO')),
        ))),
    ],

    'security_pin' => [
        'enabled' => (bool) env('CICTO_SECURITY_PIN', true),
        'idle_minutes' => (int) env('CICTO_SECURITY_PIN_IDLE_MINUTES', 5),
        'max_attempts' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | SVG is permanently excluded (stored XSS). Validation uses both extension
    | and MIME checks -- mimes: alone tests the guessed MIME, so a .php file
    | carrying a PDF magic header passes.
    |
    | NOTE: shared hosting often defaults upload_max_filesize/post_max_size to
    | 2MB, in which case PHP truncates the upload before max_size_kb is ever
    | evaluated. Verify on the real host.
    |
    */

    'uploads' => [
        'max_size_kb' => (int) env('CICTO_UPLOAD_MAX_KB', 10240),
        'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'],
        'mimes' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/png',
            'image/jpeg',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scans
    |--------------------------------------------------------------------------
    |
    | dedupe_seconds stops a courier waving a phone at a label from writing
    | twenty rows. retention_days exists because document_scans stores
    | ip_address and user_agent, which are personal information under RA 10173 --
    | an unbounded log is indefensible. Pruning stays disabled until the client
    | agrees a retention policy in writing (question B6).
    |
    */

    'scans' => [
        'dedupe_seconds' => (int) env('CICTO_SCAN_DEDUPE_SECONDS', 60),
        'retention_days' => (int) env('CICTO_SCAN_RETENTION_DAYS', 180),
        'pruning_enabled' => (bool) env('CICTO_SCAN_PRUNING_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (feature #10, and RA 10173)
    |--------------------------------------------------------------------------
    |
    | Every pruner ships DISABLED. Deleting a municipal record on a developer's
    | assumption is not a decision to make quietly -- client question B6 has to
    | be answered in writing first, and an off-site backup has to exist.
    |
    | versions.keep_first / keep_current are non-negotiable even once pruning is
    | on: the original submission and the current file are the two a records
    | office will actually be asked for. Only the intermediate drafts go, and
    | only after the document is finished.
    |
    | 1095 days is three years, which is the FLOOR the client gave in a chat
    | message on 2026-08-18 -- "3 to 5 years po minimum archive ng files", with
    | past records also held on their cloud server. Note the source: the answer
    | sheet they sent the same day says only "ARO" against this question, so the
    | exact figure is still that office's to give.
    | The floor is used rather than the ceiling because this number decides what
    | gets destroyed, and the previous default -- 180 days -- was six times more
    | aggressive than anything the client has ever agreed to. Raise it to 1825
    | if ARO says five years. Pruning is still off either way.
    |
    | Deliberately NOT changed to match: scans.retention_days above. That one
    | covers IP addresses and user agents in the scan log, which are personal
    | information under RA 10173 and are published as a 180-day promise on the
    | privacy notice. Keeping personal data for three years to match a file
    | retention policy would be a step backwards, and would silently rewrite a
    | statement the public has already been shown.
    |
    */

    'retention' => [
        'versions' => [
            'enabled' => (bool) env('CICTO_PRUNE_VERSIONS_ENABLED', false),
            'after_days' => (int) env('CICTO_VERSION_RETENTION_DAYS', 1095),
            'keep_first' => true,
            'keep_current' => true,
        ],

        'security_events' => [
            'enabled' => (bool) env('CICTO_PRUNE_SECURITY_EVENTS_ENABLED', false),
            'after_days' => (int) env('CICTO_SECURITY_EVENT_RETENTION_DAYS', 365),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports (feature #15)
    |--------------------------------------------------------------------------
    |
    | Hard row caps, enforced with a 422 BEFORE generation rather than an
    | out-of-memory 500 halfway through. Exports run synchronously because
    | QUEUE_CONNECTION=database has no worker -- a queued export would produce
    | no file and no error.
    |
    | The PDF ceiling is an estimate until measured on the real host; dompdf is
    | memory-bound and a 256MB shared plan gives out somewhere near it.
    |
    */

    'reports' => [
        'max_pdf_rows' => (int) env('CICTO_MAX_PDF_ROWS', 1000),
        'max_xlsx_rows' => (int) env('CICTO_MAX_XLSX_ROWS', 25000),
        'default_months' => (int) env('CICTO_REPORT_MONTHS', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup (feature #20)
    |--------------------------------------------------------------------------
    |
    | driver 'auto' probes the host: shell dumper when proc_open and a matching
    | client binary exist, PHP dumper otherwise. See the decision tree in
    | docs/implementation/phase-3-trust-and-toolchain.md.
    |
    */

    'backup' => [
        'disk' => env('CICTO_BACKUP_DISK', 'backups'),
        'driver' => env('CICTO_BACKUP_DRIVER', 'auto'),   // auto|shell|php
        'keep_runs' => (int) env('CICTO_BACKUP_KEEP', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security (feature #19 / spec §21)
    |--------------------------------------------------------------------------
    |
    | Both of these are OFF until HTTPS is confirmed on the deployment host,
    | and both are actively harmful if switched on before that:
    |
    | - hsts makes browsers REMEMBER to refuse plain HTTP for a year. Set it on
    |   a host without TLS and you have locked users out of their own system in
    |   a way clearing the cache does not fix.
    | - csp_enforce turns Content-Security-Policy from report-only into
    |   blocking. Watch the reports for a week first.
    |
    | SESSION_SECURE_COOKIE lives in .env and follows the same rule.
    |
    */

    'security' => [
        'hsts' => (bool) env('CICTO_HSTS', false),
        'csp_enforce' => (bool) env('CICTO_CSP_ENFORCE', false),

        // Where the browser posts violations. On by default: a report-only
        // policy that reports nowhere makes the "watch it for a week before
        // enforcing" instruction impossible to follow. Reports land in the
        // `csp` log channel.
        'csp_report' => (bool) env('CICTO_CSP_REPORT', true),

        // §21 / RA 10173. Named on the privacy notice so a data subject knows
        // who to contact -- this is the LGU's Data Protection Officer, not the
        // developer.
        'privacy_contact' => env('CICTO_PRIVACY_CONTACT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Support (spec §23)
    |--------------------------------------------------------------------------
    */

    /*
     * Contact details for §23's Contact Support page.
     *
     * The defaults are the ones the client printed on their own design, so the
     * page ships showing real details rather than "not published yet". They
     * remain env-overridable because a support number is exactly the sort of
     * thing that changes without a deployment.
     *
     * `hours` read "Monday - Thursday" on the supplied design and were flagged
     * back to the client as a likely typo for Friday. CONFIRMED NOT A TYPO on
     * 2026-08-18: the counter really does work Monday to Thursday, and the
     * times are 7:00 AM to 6:00 PM rather than the 8:00 to 5:00 the design
     * carried. Do not "fix" the Thursday.
     *
     * cicto.deadlines.business_end_hour is the machine-readable half of the
     * same fact and must move with it.
     *
     * `office` is the client's own name for themselves, from the office list
     * they supplied on the same day (code CICTO). It is printed on every
     * signature certificate and every exported register, so it is the full
     * official name rather than a convenient short one.
     */
    'support' => [
        'office' => env('CICTO_SUPPORT_OFFICE', 'Office of the City Mayor - City Information and Communications Technology Office'),
        'email' => env('CICTO_SUPPORT_EMAIL', 'cicto@baliwag.gov.ph'),
        'phone' => env('CICTO_SUPPORT_PHONE', '(044) 798 0391'),
        'address' => env('CICTO_SUPPORT_ADDRESS', 'Baliuag, Philippines, 3006'),
        'hours' => env('CICTO_SUPPORT_HOURS', 'Monday - Thursday'),
        'hours_detail' => env('CICTO_SUPPORT_HOURS_DETAIL', '7:00 AM - 6:00 PM'),
        'response_window' => env('CICTO_SUPPORT_RESPONSE_WINDOW', '24-48 hours'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-office accounts
    |--------------------------------------------------------------------------
    |
    | Database\Seeders\OfficeAccountSeeder mints two Admins and one User for
    | every ACTIVE office, because §5's "send to" dropdown offers every active
    | office whether or not anybody works there -- and a document forwarded to
    | an office with no Admin is a document nobody can open. DocumentPolicy
    | grants office-scoped read and workflow rights to Role::Admin only, so the
    | Admins are the ones who receive; the User is the one who files. The two
    | Admins have identical rights and exist as separate accounts so the audit
    | trail can say which of them approved a document (asked for 2026-09-24).
    |
    | Addresses are {code}.admin@, {code}.admin2@ and {code}.clerk@{domain},
    | lower-cased -- ocm.admin@baliwag.gov.ph, ocm.admin2@baliwag.gov.ph,
    | gso-ms.clerk@baliwag.gov.ph. Derived from the office CODE rather than a
    | counter so re-running the seeder lands on the same address and creates
    | nothing twice.
    |
    | THESE ARE LOGIN IDENTIFIERS FIRST AND MAILBOXES SECOND. The city mail
    | server has no ocm.clerk@ box, so anything the app sends one of them -- a
    | deadline notice, a password reset -- goes nowhere. That is survivable for
    | a rollout set whose passwords are handed over on paper, and it is why
    | `cicto:user` exists: as each office names a real person, create them
    | against their real address and deactivate the shared account.
    |
    | Override the domain rather than editing the seeder, so a pilot or a
    | training instance can mint accounts under a domain that is not the city's.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Starting the live database again
    |--------------------------------------------------------------------------
    |
    | `migrate:fresh` DELETES EVERY DOCUMENT, ACCOUNT AND LOG, so production
    | refuses it (AppServiceProvider). CICTO_ALLOW_DATABASE_WIPE=true lifts that
    | for as long as it is set: set it, redeploy, run
    | `php artisan migrate:fresh --seed --force`, set it back to false, redeploy.
    |
    | A database with nobody in it is then seeded ready to use (DatabaseSeeder):
    | the offices and types, every office's accounts, and ONE Super Admin from
    | the three settings below -- a real inbox, because the sign-in code is
    | emailed. Nothing here touches a database that already has accounts.
    |
    */

    'allow_database_wipe' => (bool) env('CICTO_ALLOW_DATABASE_WIPE', false),

    'super_admin' => [
        'name' => env('CICTO_SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('CICTO_SUPER_ADMIN_EMAIL'),
        'password' => env('CICTO_SUPER_ADMIN_PASSWORD'),
    ],

    'office_accounts' => [
        'domain' => env('CICTO_OFFICE_ACCOUNT_DOMAIN', 'baliwag.gov.ph'),

        /*
         * ONE REAL INBOX FOR EVERY OFFICE ACCOUNT (2026-09-28). Set it, and
         * OfficeAccountSeeder puts each account on a "+" alias of it --
         * cictobaliwagcity+ocm.admin@gmail.com -- moving any still on its
         * {code}.{slot}@domain login name. With the emailed sign-in code on,
         * that is what lets every office sign in before each person has given
         * their own address; they can move to it themselves under Settings.
         */
        'inbox' => env('CICTO_OFFICE_ACCOUNT_INBOX'),

        /*
         * ONE PASSWORD, SHARED BY ALL 156 ACCOUNTS. Say plainly what that
         * means: anybody who can reach the login page can sign in as any
         * office Admin, and an office Admin can read, forward, approve and
         * reject that office's documents. The register's audit trail stays
         * intact -- every movement still names the account that made it -- but
         * it stops being evidence of WHO, because everybody shares the login.
         * That includes the two Admins of one office: the trail can only tell
         * them apart if each keeps their own account to themselves, which on
         * a shared password they cannot.
         *
         * It ships this way on purpose. Handing 52 offices a distinct
         * 14-character string on rollout day means 52 chances to mistype one
         * and no way to tell a wrong password from a broken account, and the
         * likeliest end of that story is the passwords being written on a
         * shared sheet anyway. One password everybody already knows is the
         * honest version of the same risk, and it is the one that can be
         * retired per-office without a support call.
         *
         * SO RETIRE IT. Two ways, and they compose:
         *
         *   - Set CICTO_OFFICE_ACCOUNT_PASSWORD before seeding a real
         *     installation, so the shared secret is at least not the word
         *     "password".
         *   - As each office names a real person, create that person with
         *     `cicto:user` against their own address and deactivate the shared
         *     account. `cicto:user <email> --reset-password` rotates one
         *     without touching the other 155.
         *
         * The seeder never re-writes a password it did not just create, so
         * changing this value later affects only accounts made after the
         * change.
         */
        'password' => env('CICTO_OFFICE_ACCOUNT_PASSWORD', 'password'),
    ],

];
