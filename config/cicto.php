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
    | KNOWN INTERACTION, and it needs a decision from the client (question A4):
    | any document type whose turnaround_days is <= this value is flagged "due
    | soon" from the moment it is registered, which makes the badge meaningless
    | for that type. Keep this number BELOW the shortest real turnaround. A
    | per-type warning window would need date arithmetic across two columns,
    | which is not portable between PostgreSQL and MySQL without branching --
    | out of scope for a PHP 500 line item.
    |
    | Calendar days, not working days. A working-day engine needs a holidays
    | table reseeded every year (Philippine holidays move by proclamation), and
    | an unseeded table silently falls back to calendar days and reports wrong
    | SLAs -- worse than not offering the feature. See docs/implementation D18.
    |
    */

    'deadlines' => [
        'approaching_days' => (int) env('CICTO_APPROACHING_DAYS', 2),
        'default_turnaround_days' => (int) env('CICTO_DEFAULT_TURNAROUND_DAYS', 3),
        'business_end_hour' => (int) env('CICTO_BUSINESS_END_HOUR', 17),
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
    */

    'workflow' => [
        'allow_self_approval' => (bool) env('CICTO_ALLOW_SELF_APPROVAL', false),
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
    */

    'retention' => [
        'versions' => [
            'enabled' => (bool) env('CICTO_PRUNE_VERSIONS_ENABLED', false),
            'after_days' => (int) env('CICTO_VERSION_RETENTION_DAYS', 180),
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
     * `hours` reads "Monday - Thursday" because that is what the supplied
     * design says. Flagged to the client as a likely typo for Friday -- a
     * published counter that closes a day early is a complaint, so this is
     * copied verbatim rather than silently corrected.
     */
    'support' => [
        'office' => env('CICTO_SUPPORT_OFFICE', 'Municipal Information Technology Office'),
        'email' => env('CICTO_SUPPORT_EMAIL', 'cicto@baliwag.gov.ph'),
        'phone' => env('CICTO_SUPPORT_PHONE', '(044) 798 0391'),
        'address' => env('CICTO_SUPPORT_ADDRESS', 'Baliuag, Philippines, 3006'),
        'hours' => env('CICTO_SUPPORT_HOURS', 'Monday - Thursday'),
        'hours_detail' => env('CICTO_SUPPORT_HOURS_DETAIL', '8:00 AM - 5:00 PM'),
        'response_window' => env('CICTO_SUPPORT_RESPONSE_WINDOW', '24-48 hours'),
    ],

];
