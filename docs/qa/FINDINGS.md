# QA findings — 11 Aug 2026

Whole-system adversarial review: eight independent lenses, every finding
handed to a refute-by-default verifier. **82 raised, 68 survived.**

Counts by severity: critical 2, high 11, medium 33, low 22

Ticked items were fixed in the same pass (15 of them, covering both
criticals and 9 of the 11 highs). Everything unticked is real,
verified, and outstanding.

A second pass on 11 Aug drove the running app in a real browser rather
than reading code, and found nine more. They are recorded below under
BROWSER PASS; all nine are fixed. Worth noting what separates them from
the list above: every one returned a correct HTTP status while showing
the user something wrong, so no status-code assertion could have caught
them. `tests/Feature/Documents/UserFacingFailureTest.php` now pins the
six that are assertable.


## BROWSER PASS — 11 Aug (all fixed)

- [x] **A stale second tab was told "This action is unauthorized."**
  `app/Http/Requests/Documents/TransitionDocumentRequest.php:20` · correctness
  Two tabs on one document: the second posts against a leg that has closed,
  the policy refuses because the action is no longer legal from the new
  state, and Laravel renders a bare 403. It is a conflict, not a
  permissions failure, and the Common Errors article promised different
  wording entirely. _Fixed:_ staleness is checked before the policy, so the
  request raises StaleWorkflowStateException and the user gets the sentence
  the knowledge base documents. A test now asserts the article and the
  exception cannot drift apart.

- [x] **Completed and rejected documents lost their Department**
  `app/Support/Presenters/DocumentPresenter.php:24` · correctness
  Every Department column read through the open leg, which is correctly
  null once a document is finished — so a document whose own timeline named
  three offices showed an em dash, and the tracking tile said "Not routed
  yet". _Fixed:_ added `Document::lastMovement` and a `resting_office`
  field that falls back to the last leg, then the originating office. The
  tile now reads "Last Stage" and the summary uses the past tense.

- [x] **Dark mode painted navy headings onto a near-black background**
  `resources/views/app.blade.php:7` · design
  The ~180 brand colours across the pages are fixed hex values chosen
  against a white card. With `.dark` on the root, "Admin Panel" rendered in
  rgb(21,27,84) on near-black — unreadable, and not mobile-specific.
  _Fixed:_ the whole app is light-only. Every screen the client designed is
  light and there is no dark artwork to fall back to, so the theme switcher
  is retired rather than left offering a broken option.

- [x] **Upload File carried a required asterisk that nothing enforced**
  `resources/js/pages/documents/create.tsx:322` · correctness
  Submitting with no file at all succeeded. _Fixed:_ the label, not the
  rule — a registry for tracking paper folders between offices has to be
  able to register a document that has not been scanned yet, which is the
  common case at a receiving counter. Flagged to the client as a question,
  since their mockup shows the asterisk.

- [x] **403 and 404 were unstyled pages with no way back into the app**
  `bootstrap/app.php:63` · design
  Office scoping makes 403 an ordinary outcome — a clerk opening another
  department's document gets one — and it dead-ended on Laravel's stock
  page. _Fixed:_ a branded error page for 403/404/419/429, and for 500/503
  once debug is off. A `Route::fallback` puts unmatched URLs back inside the
  web group, because a route that matches nothing never starts a session and
  the page could not otherwise tell whether the visitor was signed in.

- [x] **The stage stepper collided with itself below `sm`**
  `resources/js/components/documents/document-tracking.tsx:70` · design
  All the horizontal spacing came from the connector's `mx-2`, and the
  connector is hidden on phones — so the active chevron's arrow tip, which
  overflows its own box by 16px, printed over the previous label. _Fixed:_
  a gap-x below `sm` only, plus margin for the tip.

- [x] **The CSV export button was invisible**
  `resources/js/pages/reports/index.tsx:165` · design
  A ghost variant beside two solid pills put its white label directly on
  the decorative watermark — while staying perfectly clickable. _Fixed:_
  same variant as its neighbours.

- [x] **Every toast was the same dark pill**
  `resources/js/components/ui/sonner.tsx` · design
  "Registered" and "not delivered" differed only by a small icon, on a
  message that disappears after four seconds. _Fixed:_ `richColors`.

- [x] **Demo accounts were named for an office they were not in**
  `database/seeders/DatabaseSeeder.php:40` · data
  "MPDO Admin" and "MPDO Clerk" sat in the Mayor's Office, so every screen
  showed one department's name beside another's documents — which reads as
  a bug in the office scoping. _Fixed:_ renamed to MO Admin / MO Clerk.


## CRITICAL

- [x] **Self-service account deletion silently erases audit-trail attribution from document_movements**  
  `app/Http/Controllers/Settings/ProfileController.php:55` · destructive  
  _Fix:_ Do not hard-delete accounts that have touched the ledger. Either (a) remove the profile.destroy route and replace it with is_active = false plus a SecurityEventType::UserDeactivated row, or (b) add SoftDeletes to User so the FKs stay intact. Independently, add a denormalised actor_label column to document_movements written at leg-creation time, mirroring what security_events already does, so ledge
- [x] **Backups never contain the document files, but the printed restore runbook tells the operator to rsync them out of the archive**  
  `app/Services/Backup/BackupService.php:94` · signatures-backup  
  _Fix:_ Either (a) implement the `kind = 'full'` run the schema already reserves — tar/zip `storage/app/documents` alongside the SQL, record it on the row, and honour `cicto.backup.passphrase` (setting `is_encrypted` truthfully) — or (b) if file backup is out of scope, delete runbook steps 4 and 6, delete the AES/passphrase paragraph at phase-3:268, remove the unused `cicto.backup.passphrase` key, and sta

## HIGH

- [x] **Unauthenticated Inertia DevTools endpoints dump every recent page payload (bypassing DocumentPolicy, EnsureRole and visibleTo) whenever APP_ENV=local**  
  `config/inertia.php:64` · authz  
  _Fix:_ Do not let APP_ENV be the only control. Add an explicit devtools block to config/inertia.php:

    'devtools' => [
        'enabled' => (bool) env('INERTIA_DEVTOOLS', false),
    ],

and keep INERTIA_DEVTOOLS out of .env.example. Optionally also set 'gate' => 'viewInertiaDevtools' and register a Gate that only a Super Admin passes. Then pin it the same way the storage.local bypass is pinned, e.g. 
- [x] **Self-service "Delete account" 500s for real staff and, when it succeeds, permanently erases actor attribution from the document_movements ledger**  
  `app/Http/Controllers/Settings/ProfileController.php:55` · authz  
  _Fix:_ Municipal records systems should deactivate, not delete. Either drop the route and the DeleteUser card entirely, or replace ProfileController::destroy with a deactivation (`$user->forceFill(['is_active' => false])->save()` plus a SecurityEvent) — EnsureAccountIsActive already turns that into a full lockout. If a real delete is genuinely wanted, gate it behind a policy that refuses when the user ha
- [x] **There is no HTTP surface to assign a role or an office, so following the deployment runbook produces a system with exactly one usable account**  
  `resources/js/pages/admin/users/index.tsx:19` · consistency  
  _Fix:_ Wire `AssignUserRole` to routes behind `EnsureRole::using(Role::Admin, Role::SuperAdmin)` and replace the two placeholder pages, or — at minimum before handover — add a `cicto:assign-role` console command and document it in DEPLOYMENT.md §3, and correct the three placeholder pages that promise "Phase 3".
- [x] **Backups never include uploaded document files, and the documented restore runbook restores from an archive the code never produces**  
  `app/Services/Backup/BackupService.php:94` · destructive  
  _Fix:_ Either implement the file half of the backup (walk the documents disk into the archive, set kind='full', and schedule it) or delete the promise: remove steps 4 and 6 from the phase-3 restore runbook, remove the 'weekly full' line, and state in DEPLOYMENT.md §9 'Known limits' that storage/app/documents is NOT backed up by the application and must be covered by a separate host-level file backup. Ext
- [x] **Every `back()->with('toast', …)` message is silently discarded — the whole toast channel is dead**  
  `resources/js/hooks/use-flash-toast.ts:8` · frontend  
  _Fix:_ Pick one channel and use it everywhere. Either replace every `->with('toast', …)` with `Inertia::flash('toast', …)` (matching the two settings controllers), or add `'toast' => fn () => $request->session()->get('toast')` to `HandleInertiaRequests::share()` and read it in `useFlashToast` from `usePage().props.toast` instead of the `flash` event. Add a feature test that asserts the rendered Inertia p
- [x] **ShellDumper fatals with a PHP Error when shell_exec is disabled but proc_open is not, 500-ing the Super Admin settings page**  
  `app/Services/Backup/ShellDumper.php:142` · operations  
  _Fix:_ Guard the probe: replace the `@shell_exec(...)` branch with a check that the function is callable first — `if (! function_exists('shell_exec')) { continue; }` — and/or drop the `command -v` lookup entirely in favour of the explicit `is_executable()` candidate list already present at lines 147-152. Additionally wrap the whole of `isSupported()` in `try { ... } catch (\Throwable) { return false; }`,
- [x] **A backup that fails before the dump starts writes no backup_runs row, and the command falsely reports that it did**  
  `app/Services/Backup/BackupService.php:80` · operations  
  _Fix:_ Create the `BackupRun` row (status `running`, driver unknown/`pending`) before resolving the dumper, then resolve inside the existing try/catch so a dumper-resolution failure marks the row `failed` with the message. Alternatively wrap the `dumper()` call in its own try/catch that writes a `failed` BackupRun and a `BackupFailed` security event before rethrowing. Also fix `BackupRunCommand.php:48` s
- [ ] **BackupService writes the dump through a raw filesystem path, so a non-local backup disk dumps the whole database into the web root**  
  `app/Services/Backup/BackupService.php:107` · operations  
  _Fix:_ Refuse to run on a disk the dumper cannot write to directly: assert the adapter is local (`Storage::disk($disk)->getAdapter() instanceof \League\Flysystem\Local\LocalFilesystemAdapter`, or check `config("filesystems.disks.$disk.driver") === 'local'`) and throw a clear RuntimeException otherwise. For genuine off-site support, dump to a local temp path first and then `Storage::disk($offsite)->writeS
- [x] **The public verify page, the printed certificate and the document page all report "Valid" for a file whose bytes were swapped — even after the nightly sweep has already detected the tamper**  
  `app/Http/Controllers/DocumentSignatureController.php:113` · signatures-backup  
  _Fix:_ Pass `rehashBytes: true` in the two single-signature surfaces (DocumentSignatureController::verify and ::certificate) — that is one hash of one file per request. For the document detail list, either keep the cheap check but label it honestly ("fingerprint matches the record; last full byte check <date>") or persist the sweep's verdict on the row (e.g. `last_verified_at` / `last_verify_failed_at`) 
- [x] **A backup that fails before the dump starts is left at status `running` forever, while the command tells the operator the failure was recorded**  
  `app/Services/Backup/BackupService.php:106` · signatures-backup  
  _Fix:_ Move `makeDirectory` and `path()` inside the try, and wrap `$this->dumper()` so a driver-selection failure still writes a Failed row (or at minimum a SecurityEvent) before rethrowing. Add a reaper that marks any `running` row older than the dump timeout as Failed (`cicto:backup` start, or the scheduler) and deletes its orphaned file, so a crashed run cannot masquerade as in-progress.
- [ ] **PhpDumper takes no consistent snapshot, so a dump taken while the app is in use can be internally inconsistent and is still recorded as Completed with a checksum**  
  `app/Services/Backup/PhpDumper.php:152` · signatures-backup  
  _Fix:_ Open a read transaction around the whole dump: on PostgreSQL `DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY')` inside `DB::beginTransaction()`; on MySQL/InnoDB `START TRANSACTION WITH CONSISTENT SNAPSHOT`. SQLite gets it from a deferred read transaction. Roll back at the end — it is a read-only transaction, so it costs nothing but the snapshot.

## MEDIUM

- [ ] **The `verified` middleware on every protected route is inert: User does not implement MustVerifyEmail, so anyone can self-register and reach every authenticated screen with an unverified address**  
  `app/Models/User.php:46` · authz  
  _Fix:_ Decide and make it true in code. If verification is wanted: `class User extends Authenticatable implements PasskeyUser, MustVerifyEmail` (uncomment the import) — the routes and Fortify feature are already in place, but note MAIL_MAILER=log means nothing is delivered until SMTP is configured, so pair it with removing Features::registration() so accounts are created by an Admin via AssignUserRole in
- [ ] **Signature Certificate PDF hard-500s when the drawn-signature PNG is missing, instead of degrading like the download path does**  
  `resources/views/documents/signature-certificate.blade.php:76` · authz  
  _Fix:_ Read defensively in the controller, not the view, and pass a nullable base64 string to the Blade:

    $mark = null;
    if ($signature->method->requiresImage() && $signature->image_path) {
        try { $mark = Storage::disk($signature->image_disk)->get($signature->image_path); }
        catch (\Throwable) { $mark = null; }
    }

Then in the view fall back to the typed-name rendering plus an exp
- [ ] **The chart headed "Monthly Documents Processed" plots registrations, not completions — the real processed-per-month series is computed, shipped, and thrown away**  
  `resources/js/components/reports/report-charts-bundle.tsx:36` · consistency  
  _Fix:_ Either render `<MonthlyProcessedChart data={monthlyProcessed}/>` in its own card and retitle the by-status card to what it shows ("Documents received per month"), or drop `monthlyProcessed` from the controller, the page props and `report-charts.tsx` entirely. Do not leave a card whose heading says "processed" over a series bucketed by `created_at`.
- [ ] **"Pending" counts three different sets of documents across the Admin Panel, the Reports page, and the badges on the Admin Panel's own rows**  
  `app/Http/Controllers/Admin/AdminDashboardController.php:40` · consistency  
  _Fix:_ Derive the Admin Panel tiles from `DocumentStatus::publicOptions()` / `publicLabel()` so the tile label, the row badge and the Reports pie all read from the one mapping, or rename the tiles to words the badges never use (e.g. "Open", "Decided") so no two figures share a name while counting different rows.
- [ ] **Report exports ignore the reporting period, and the over-cap 422 tells the operator to do something no control can do**  
  `app/Http/Controllers/ReportController.php:88` · consistency  
  _Fix:_ Accept and apply the same date window in `export()` that `index()` uses — read `months` (or from/to) and add `->where('documents.created_at','>=',$start)` to `$query` before the count and before generation — and append the current period to the export hrefs in `reports/index.tsx`. If exports are deliberately whole-register, change the 422 message and the on-page note to say so instead of instructi
- [x] **Backups are configured, documented and instrumented as AES-256 encrypted archives; nothing encrypts or archives anything**  
  `config/cicto.php:182` · consistency  
  _Fix:_ Pick one and make everything agree: either implement the AES-256 archive step in `BackupService::run()` and set `is_encrypted`, or delete `cicto.backup.passphrase`, drop/stop advertising `is_encrypted`, remove the ZipArchive capability tile, and rewrite phase-3 lines 268-269 and restore step 4 to describe a plain gzip dump — with the "this is not encrypted, keep it somewhere that is" warning DEPLO
- [ ] **"Average time at each office" averages in zero-length bookkeeping legs written by completion, archive and restore**  
  `app/Support/Reporting/DocumentStats.php:334` · consistency  
  _Fix:_ Exclude non-custody legs from both aggregations: add `->whereNotIn('document_movements.action', [MovementAction::Archived->value, MovementAction::Restored->value])` and `->whereColumn('document_movements.departed_at','>','document_movements.arrived_at')` in `officePerformance()`, and skip the same actions in `DocumentPresenter::officeRollup()`.
- [ ] **The deploy-time super-admin command uses Password::uncompromised(), which AppServiceProvider explicitly rejects for this deployment**  
  `app/Console/Commands/CreateSuperAdminCommand.php:46` · consistency  
  _Fix:_ Use `Password::defaults()` in the command, or restate the production rule set inline (`min(12)->mixedCase()->letters()->numbers()->symbols()`) without `uncompromised()`, so the one account that matters most is held to the same bar the rest of the app is.
- [x] **profile.destroy logs the user out before a delete that can fail, half-completing the operation and 500ing**  
  `app/Http/Controllers/Settings/ProfileController.php:53` · destructive  
  _Fix:_ Check for blocking references (or attempt the delete) BEFORE tearing down the session, wrap the whole thing so a failure leaves the user logged in with a clear validation error ('Your account cannot be deleted because it is attached to N documents'), and move Auth::logout() plus session invalidation to after a confirmed successful delete.
- [ ] **Comment deletion hard-deletes, cascades to other users' replies, and writes no audit record anywhere**  
  `app/Http/Controllers/DocumentCommentController.php:54` · destructive  
  _Fix:_ Add SoftDeletes to DocumentComment (or restrict deletion to comments with no replies), and log every comment edit and deletion to security_events with a new closed-vocabulary type. If the routes are genuinely not part of the shipped UI, remove them rather than leaving an unaudited destructive endpoint live.
- [ ] **PhpDumper dumps and re-inserts the migrations table, rewinding the schema ledger on restore**  
  `app/Services/Backup/PhpDumper.php:53` · destructive  
  _Fix:_ Add 'migrations' to PhpDumper::SKIP. The schema ledger belongs to the target database, not to the snapshot; last_migration on backup_runs already records the snapshot's version for the compatibility check.
- [ ] **cicto:prune-personal-data --force reports "Deleted N record(s)" when pruning is disabled and nothing was deleted**  
  `app/Console/Commands/PrunePersonalDataCommand.php:72` · destructive  
  _Fix:_ Return 0 from the disabled branch, and refuse --force while disabled with a non-zero exit the way PruneDocumentVersionsCommand does. Report deleted and would-be-deleted counts as separate numbers so the summary line can never conflate them.
- [ ] **cicto:prune-versions collapses its retention window to zero when --days is empty or non-numeric**  
  `app/Console/Commands/PruneDocumentVersionsCommand.php:40` · destructive  
  _Fix:_ Validate the option explicitly: reject a non-numeric or empty --days with a failure exit, and refuse any value below a sane floor (e.g. 1). Use `$this->option('days') !== null && $this->option('days') !== '' ? (int) ... : $config['after_days']` rather than ??.
- [ ] **The runbook's demo-account cleanup is a mass hard delete that either aborts or strips actor attribution from UAT documents**  
  `docs/handover/DEPLOYMENT.md:111` · destructive  
  _Fix:_ Replace the command with a deactivation: `User::whereIn('email', [...])->update(['is_active' => false])`, which EnsureAccountIsActive already enforces as a real lockout, and change the checklist item to 'Demo accounts deactivated'. Add a HandoverTest case that executes the runbook's tinker snippets against a seeded database so a broken one-liner fails CI rather than a deployment.
- [ ] **Admin dashboard "Print QR label" uses an Inertia `<Link>` against a plain Blade route, so the label sheet opens inside a sandboxed error dialog and cannot be printed**  
  `resources/js/pages/admin/dashboard.tsx:471` · frontend  
  _Fix:_ Replace the Inertia `<Link>` with a plain anchor, as `documents/show.tsx` already does: `<a href={documents.labels.print.url({ query: { ids: [row.id] } })} target="_blank" rel="noopener">Print QR label</a>` inside the `DropdownMenuItem asChild`.
- [ ] **Camera stream is leaked on every failed start and on every "Try again", accumulating live capture tracks**  
  `resources/js/hooks/use-qr-scanner.ts:126` · frontend  
  _Fix:_ Call `release()` at the top of `start()` before requesting a new stream, and stop the acquired tracks in both post-acquisition bail-outs (`stream.getTracks().forEach(t => t.stop())`, as the `!video` branch at line 131 already does). Also render a "Stop the camera" control in the `denied`/`error`/`unsupported` notice.
- [ ] **Mutating action buttons fire `router.post`/`router.delete` with no in-flight guard, so every one can be double-submitted**  
  `resources/js/pages/super-admin/settings/index.tsx:114` · frontend  
  _Fix:_ Track in-flight state for each of these actions (`const [busy, setBusy] = useState(false)` with `onStart`/`onFinish` callbacks, or convert them to `useForm().post/delete` and use `form.processing`) and set `disabled` plus a working label on the button while the request is outstanding.
- [ ] **The QR scan console and Notifications have no entry point in the shell that non-admin users actually see**  
  `resources/js/components/app-top-nav.tsx:22` · frontend  
  _Fix:_ Add Scan QR Code and a Notifications bell (using the `unreadNotifications` shared prop) to `AppTopNav`, or route `documents/scan` and `notifications/*` through `AppLayout` so the workspace sidebar is present.
- [ ] **§19 "average processing time per office" is diluted by non-custody ledger rows, and drops further every time a document is archived**  
  `app/Support/Reporting/DocumentStats.php:349` · ledger  
  _Fix:_ Restrict the aggregate to rows that represent custody. Add a whereNotIn on action for the pure event verbs and exclude zero-length legs, e.g. ->whereNotIn('document_movements.action', [MovementAction::Archived->value, MovementAction::Restored->value]) plus ->whereColumn('document_movements.departed_at', '>', 'document_movements.arrived_at') (or exclude legs whose to_status is terminal). The same p
- [ ] **Archived documents stay in the Admin Panel document table and work queue**  
  `app/Http/Controllers/Admin/AdminDashboardController.php:59` · ledger  
  _Fix:_ Add ->active() to the $base closure used for the listing/queue/pending lists (keep stats() and trend() unscoped so the tiles and chart still count archived work per D16), or introduce a separate $listBase = fn () => $base()->active().
- [ ] **Retention pruners are never scheduled, so the public privacy notice states retention periods that are never enforced**  
  `routes/console.php:27` · operations  
  _Fix:_ Add both commands to `routes/console.php`, e.g. `Schedule::command('cicto:prune-personal-data --force')->dailyAt('03:15')->timezone(config('app.timezone'))->withoutOverlapping()->onOneServer();` and the same for `cicto:prune-versions --force`. The commands already self-gate on the `enabled` config flags and refuse to `--force` while disabled (`PruneDocumentVersionsCommand:57`), so scheduling them 
- [ ] **An upload larger than post_max_size produces a raw 413 error page, not a form error**  
  `bootstrap/app.php:34` · operations  
  _Fix:_ Register a renderer in `bootstrap/app.php`: `$exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, Request $request) { return back()->withErrors(['file' => 'That file is larger than this server accepts ('.ini_get('upload_max_filesize').'). Please compress or split it.']); });`. Also surface the effective limit to the UI — pass `min((int) config('cicto.uploads.max_size
- [ ] **The register export accepts no filters, so the row caps are permanently inescapable and the error tells the user to do something impossible**  
  `app/Http/Controllers/ReportController.php:63` · operations  
  _Fix:_ Accept the same filter set the document index already validates (`IndexDocumentRequest`: `from`, `to`, `status`, `office_id`, `document_type_id`, and the `months` selector) in `export()`, apply them to the query before the count, and pass the currently-selected filters into the export links in `reports/index.tsx`. Until that exists the cap message is misleading — at minimum change it to name the C
- [ ] **Backup and signature verification run synchronously in web requests with no execution-time handling, leaving backup_runs stuck at 'running'**  
  `app/Http/Controllers/SuperAdmin/SystemController.php:87` · operations  
  _Fix:_ Call `set_time_limit(0)` (and consider `ignore_user_abort(true)`) at the top of `BackupService::run()` and `VerifySignaturesCommand::handle()` so a CLI-shaped job is not killed by a web time limit; guard it with `function_exists('set_time_limit')` for hosts that disable it. Add a stale-run reaper — on `SystemController::index()` or at the start of `BackupService::run()`, mark any `running` row old
- [ ] **Password reset claims the email was sent when MAIL_MAILER=log, and writes the working reset token into the log file**  
  `config/fortify.php:165` · operations  
  _Fix:_ Apply the same honesty guard as HelpController. Either disable `Features::resetPasswords()` when `config('mail.default')` is `log`/`array` and hide the link, or override `Fortify::requestPasswordResetLinkView` / the reset response to render an explicit warning — 'Outgoing mail is not configured on this server. Ask your administrator to reset your password.' — instead of the success status. Pass a 
- [ ] **notifications grows without bound; the daily overdue sweep writes fresh rows forever and no pruner exists for the table**  
  `app/Console/Commands/NotifyDeadlinesCommand.php:93` · operations  
  _Fix:_ Add a `notifications` retention block to `config/cicto.php` and prune read notifications older than N days (and any notification older than a longer hard cap) from `PrunePersonalDataCommand`, then schedule that command (see the scheduling finding). Independently, consider making the overdue reminder cadence back off — read `overdue_notified_at` in the sweep and re-notify weekly rather than daily o
- [x] **Public self-registration is enabled, linked from the login page, and completely unthrottled**  
  `config/fortify.php:164` · operations  
  _Fix:_ Decide whether self-registration belongs in this system at all. If it does not: remove `Features::registration()` from `config/fortify.php` and remove the Register link from `auth/login.tsx`. If it does: add `->middleware('throttle:5,60')` to the registration route (Fortify allows overriding via a custom `RateLimiter::for('register', ...)` plus route middleware, or re-declare the route), and make 
- [ ] **ShellDumper never passes unix_socket, so on a socket-only MySQL host every backup is recorded as failed instead of falling back to PhpDumper**  
  `app/Services/Backup/ShellDumper.php:117` · portability  
  _Fix:_ In command(), when `$config['unix_socket']` is a non-empty string, pass `'--socket='.$config['unix_socket']` instead of --host/--port (mysqldump honours --socket the same way the PDO DSN does). Additionally, make BackupService::dumper() fall back rather than fail hard: when the configured driver is 'auto' and ShellDumper::dump() throws a connection-level ProcessFailedException, retry once with Php
- [ ] **`cicto:host-check`, `cicto:backup --probe` and the Super Admin System Settings page all hard-fail when CICTO_BACKUP_DRIVER=shell on a host that cannot run it**  
  `app/Console/Commands/HostCheckCommand.php:170` · signatures-backup  
  _Fix:_ Wrap the `capabilities()` call in HostCheckCommand::backups(), BackupRunCommand's probe branch and SystemController::index() in try/catch, reporting `driver => 'UNAVAILABLE: <reason>'` instead of throwing. Better: have `capabilities()` catch its own dumper-selection failure and return the reason as data — it is a diagnostic method and must never be able to take down its callers.
- [ ] **The signature certificate PDF 500s instead of degrading when the drawn signature PNG is missing from disk**  
  `resources/views/documents/signature-certificate.blade.php:76` · signatures-backup  
  _Fix:_ Guard the read: `$bytes = Storage::disk($signature->image_disk)->exists($signature->image_path) ? Storage::disk(...)->get(...) : null;` and fall back to the typed-name rendering plus a visible "signature image unavailable" note when `$bytes` is null. Never let a decorative asset determine whether the legal record can be printed.
- [ ] **Any user who has signed a document or registered one cannot delete their own account: unhandled QueryException 500 after they have already been logged out**  
  `app/Http/Controllers/Settings/ProfileController.php:55` · signatures-backup  
  _Fix:_ Refuse the deletion up front with a validation error rather than letting the database refuse it: check `$user->signatures()->exists() || $user->documents()->exists()` (or catch QueryException) and return `back()->withErrors(['password' => 'Your account is attached to signed or registered documents and cannot be deleted. Ask a Super Admin to deactivate it instead.'])`. Move `Auth::logout()` after t
- [x] **Restore runbook step 9 and the architecture doc's stated safeguard both invoke `documents:verify-status`, a command that does not exist**  
  `docs/implementation/phase-3-trust-and-toolchain.md:295` · signatures-backup  
  _Fix:_ Either write the command (walk each document, recompute status from its movement ledger, report and optionally --fix mismatches, exit non-zero on drift) and add the test the architecture doc claims exists, or strike it from runbook step 9 and from 00-architecture.md D5/§457 and replace it with the checks that do exist (`cicto:verify-signatures`, the one-open-leg count already in step 9).
- [ ] **The Super Admin "verify signatures" button re-hashes every signed file synchronously inside an HTTP request**  
  `app/Http/Controllers/SuperAdmin/SystemController.php:129` · signatures-backup  
  _Fix:_ Make the button start a bounded pass (e.g. `--limit=N` oldest-unverified first, persisting `last_verified_at` per signature) that provably completes inside the request budget and reports how many were checked, or run it via `Symfony\Component\Process` in the background when proc_open is available and poll for the result. Whichever route, surface the partial/complete state honestly instead of a sin

## LOW

- [ ] **`notification` is missing from the numeric route-key pattern list, so /notifications/{non-numeric}/go is a 500 on PostgreSQL**  
  `app/Providers/AppServiceProvider.php:58` · authz  
  _Fix:_ Add 'notification' (and, for the vendor passkey route DELETE user/passkeys/{passkey}, 'passkey') to the array in constrainNumericRouteKeys(). A cheap regression test: iterate Route::getRoutes(), and for every parameter whose bound model has an integer route key assert Route::getPatterns() contains a numeric pattern for it — that way the next nested route cannot be added without the guard.
- [ ] **AllocateControlNumber's documented race recovery is unreachable: the create() itself throws the unique violation, aborting document registration**  
  `app/Actions/Documents/AllocateControlNumber.php:38` · authz  
  _Fix:_ Make the insert idempotent instead of relying on unreachable recovery. Either use an upsert — `DocumentNumberSequence::query()->upsert([['office_id' => $office->id, 'period_year' => $year, 'last_number' => 0]], ['office_id', 'period_year'], [])` followed by the locked re-select — or wrap the create in `try { ... } catch (\Illuminate\Database\UniqueConstraintViolationException) { }` and then do the
- [ ] **The documented recovery from a concurrent control-number allocation is unreachable — there is no catch around the insert**  
  `app/Actions/Documents/AllocateControlNumber.php:38` · consistency  
  _Fix:_ Wrap the `create()` in `try { … } catch (\Illuminate\Database\UniqueConstraintViolationException) { }` so the re-select on line 47 actually runs, or replace the whole block with `DocumentNumberSequence::firstOrCreate(['office_id'=>…, 'period_year'=>…], ['last_number'=>0])` followed by the locked re-select.
- [ ] **The phase-3 restore runbook tells the operator to run `php artisan documents:verify-status`, which does not exist**  
  `docs/implementation/phase-3-trust-and-toolchain.md:295` · consistency  
  _Fix:_ Either remove the command from step 9 and keep only the manual checks it already lists, or point it at `cicto:verify-signatures` plus an explicit `is_open` count query — and extend `HandoverTest` to scan `docs/implementation/*.md` as well as DEPLOYMENT.md so this class of drift fails the build.
- [ ] **docs/DATABASE.md and AdminDashboardController both state MySQL/PostgreSQL NULL ordering backwards**  
  `docs/DATABASE.md:82` · consistency  
  _Fix:_ Correct both sentences: ASC puts NULLs last on PostgreSQL and first on MySQL; DESC is the reverse. Keep the surrounding advice ("if null ordering matters, say so explicitly") unchanged.
- [ ] **Every admin dashboard response ships the paginated rows twice — `queue` is a byte-for-byte duplicate of `documents.data` that no component reads**  
  `app/Http/Controllers/Admin/AdminDashboardController.php:112` · consistency  
  _Fix:_ Delete the `queue` prop and repoint `AdminQueueOrderTest` at `props['documents']['data']`, so the ordering assertion covers the list users actually see.
- [ ] **Backup archives are never encrypted; is_encrypted and CICTO_BACKUP_PASSPHRASE are dead config the UI never contradicts**  
  `app/Services/Backup/BackupService.php:145` · destructive  
  _Fix:_ Pick one and make it true. Either implement AES-256 archiving with the configured passphrase and set is_encrypted accordingly, or remove the passphrase config key and the is_encrypted column from the promise and surface a plain warning in the Super Admin backup panel ('These archives are NOT encrypted -- encrypt before moving them off-site'). Add is_encrypted to the SystemController projection eit
- [ ] **AllocateControlNumber's documented recovery from a concurrent first-of-year insert does not exist**  
  `app/Actions/Documents/AllocateControlNumber.php:38` · destructive  
  _Fix:_ Wrap the create() in a try/catch on the unique violation (or use upsert/insertOrIgnore) and then fall through to the existing lockForUpdate re-select, which is what the comment already describes. A regression test that fires two allocations against an empty sequence row would pin it.
- [ ] **Restore-drill form posts a `required` field whose validation error is never rendered, so "Save" with an empty note is a permanent silent no-op**  
  `resources/js/pages/super-admin/settings/index.tsx:231` · frontend  
  _Fix:_ Render `<InputError message={restore.errors.notes} />` next to the input, add `required` and `maxLength={2000}` to the `<Input>`, and add `disabled={restore.processing}` to the Save button.
- [ ] **Report exports ignore the selected reporting period, so PDF/Excel/CSV never match the report on screen**  
  `resources/js/pages/reports/index.tsx:94` · frontend  
  _Fix:_ Pass the active period through: `reports.export.url({ query: { format: 'pdf', months } })` on all five links, and have `ReportController::export()` apply the same `months` window (reusing its private `months()` helper) to the query before counting and streaming.
- [ ] **Breadcrumbs declared by twelve pages under the top-nav shell are silently discarded**  
  `resources/js/layouts/app/app-top-layout.tsx:20` · frontend  
  _Fix:_ Either accept and render them — `export default function AppTopLayout({ children, breadcrumbs = [] })` with `<Breadcrumbs breadcrumbs={breadcrumbs} />` inside the `<main>` — or delete the twelve dead `.layout` declarations so nobody trusts them.
- [ ] **Version-upload `replace_reason` is uncapped in the UI and its validation error is never rendered**  
  `resources/js/pages/documents/show.tsx:516` · frontend  
  _Fix:_ Add `maxLength={500}` to the `<Input>` and render `<InputError message={version.errors.replace_reason} />` beneath it.
- [ ] **Submit Document marks the file upload required in the UI while the server rule is `nullable`**  
  `resources/js/pages/documents/create.tsx:324` · frontend  
  _Fix:_ Decide which it is. If the file is optional, drop the `*` and label it "Upload File (optional)"; if it is mandatory, add `required` to the input and change the rule to `['required', File::types(...)…]`.
- [ ] **AllocateControlNumber's documented race recovery is unreachable — a concurrent first registration of the year 500s and loses the submission**  
  `app/Actions/Documents/AllocateControlNumber.php:38` · ledger  
  _Fix:_ Wrap the create() in try { ... } catch (UniqueConstraintViolationException) { } and fall through to the existing lockForUpdate()->firstOrFail() reload, which is what the comment already describes. Because this runs inside RegisterDocument's transaction the nested DB::transaction opens a savepoint, so the rollback-to-savepoint on the caught violation keeps the outer PostgreSQL transaction usable.
- [ ] **Single unrotated log channel at debug level receives every outbound email body, including password-reset links**  
  `config/logging.php:73` · operations  
  _Fix:_ Set `LOG_STACK=daily` and `LOG_LEVEL=warning` in `.env.example` and in the DEPLOYMENT.md production env block, so the main channel rotates on the existing `LOG_DAILY_DAYS` (14) window. Do not log full mail bodies in production — if `MAIL_MAILER=log` is going to remain the shipping default, route it to its own short-retention daily channel via `MAIL_LOG_CHANNEL` rather than into `stack`. Redact or 
- [ ] **CreateSuperAdminCommand uses uncompromised(), which AppServiceProvider explicitly rejects for this host profile**  
  `app/Console/Commands/CreateSuperAdminCommand.php:46` · operations  
  _Fix:_ Use `Password::defaults()` here so the one account that matters is held to the same production rules as everyone else (`min(12)->mixedCase()->letters()->numbers()->symbols()`), and drop `->uncompromised()` for the reason already documented in AppServiceProvider. If a breach check is genuinely wanted at install time, run it separately with a short timeout and report the result rather than folding i
- [ ] **The database cache table accumulates expired rate-limiter rows forever on public routes**  
  `config/cicto.php:110` · operations  
  _Fix:_ Schedule a periodic sweep of expired cache rows in `routes/console.php`, e.g. a small command running `DB::table('cache')->where('expiration', '<', now()->getTimestamp())->delete()` (chunked by key, not `LIMIT`, per the PostgreSQL issue above) nightly. Alternatively point the rate limiter at the `file` cache store via `config/cache.php` so the throttle keys never touch the database at all.
- [ ] **Numeric route-key guard omits {notification} and {passkey} — non-numeric id is a PostgreSQL-only 500**  
  `app/Providers/AppServiceProvider.php:58` · portability  
  _Fix:_ Add 'notification' and 'passkey' to the pattern list in constrainNumericRouteKeys(): `foreach (['document', 'file', 'office', 'user', 'run', 'comment', 'movement', 'notification', 'passkey'] as $key)`. Do not add 'signature' — that one binds on `serial`, a varchar, and a [0-9]+ pattern would break the certificate URL.
- [ ] **LIKE escaping in DocumentBuilder::search() has no ESCAPE clause, so it is a silent no-op on SQLite**  
  `app/Models/Builders/DocumentBuilder.php:139` · portability  
  _Fix:_ Append an explicit escape character to both predicates — `lower(documents.control_number) like ? escape '\\'` and the orWhereRaw twin. `LIKE ... ESCAPE '\'` is accepted by SQLite, MySQL and PostgreSQL alike. Then add a positive assertion to SearchAndFilterTest: seed a document titled '100% complete' and assert searching '100%' finds it and does not find '1005 complete'.
- [ ] **PhpDumper's MySQL preamble replaces the whole sql_mode, disabling strict mode for the entire restore**  
  `app/Services/Backup/PhpDumper.php:317` · portability  
  _Fix:_ Drop the SQL_MODE line entirely, or make it additive and reversible: `SET @cicto_sql_mode := @@SESSION.sql_mode; SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_AUTO_VALUE_ON_ZERO');` in header(), and `SET SESSION sql_mode = @cicto_sql_mode;` alongside the FOREIGN_KEY_CHECKS=1 in footer().
- [ ] **AllocateControlNumber's documented duplicate-key recovery is unreachable — the losing create() throws before the reload**  
  `app/Actions/Documents/AllocateControlNumber.php:38` · portability  
  _Fix:_ Wrap the create() in `try { ... } catch (\Illuminate\Database\UniqueConstraintViolationException) { }` so the existing re-select genuinely picks up the winner, or replace lines 37-52 with `DocumentNumberSequence::query()->firstOrCreate(['office_id' => $office->id, 'period_year' => $year], ['last_number' => 0])` followed by the locking re-select. Either way the reload must run on the failure path, 
- [ ] **The canonical signature payload is ambiguous: `|` in a signer field, and NULL vs empty string, produce identical hashes**  
  `app/Models/DocumentSignature.php:113` · signatures-backup  
  _Fix:_ Bump PAYLOAD_VERSION to v3 and make the encoding injective — either length-prefix each field (`strlen($v).':'.$v`) or, simplest, hash `json_encode([...], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)` of an ordered map, which distinguishes null from '' for free. Keep the v2 branch so existing signatures still verify under the rules they were made with — which is what PAYLOAD_VERSION exists for.
