# Claude web prompts for the CICTO system diagrams

Paste the **SYSTEM BRIEF** block first, then one diagram prompt underneath it,
into a fresh claude.ai chat. One chat per diagram. The brief is written from
the code as of 2026-09-09 (workflow after the client's 2026-09-03 decision to
drop approve / reject / return).

---

## SYSTEM BRIEF (paste at the top of every prompt)

```
SYSTEM BRIEF — CICTO Document Tracking System (DTS)

Client: Office of the City Mayor – City Information and Communications Technology Office (CICTO), City Government of Baliuag, Bulacan, Philippines.
What it is: a web-based document tracking system used by about 52 city offices and departments to register, route, track, digitally sign, archive and report on official documents. Every physical folder gets a printed QR label; scanning it shows the document's current status and the office holding it. The movement ledger doubles as the audit trail.
Stack (context only, do not draw it unless asked): Laravel backend, React front end, PostgreSQL or MySQL database, file storage on a local disk or S3-compatible cloud, cron scheduler, SMTP mail via the operator's Gmail account.

ACTORS
1. User (Clerk / Staff) – registers (files) documents, uploads new file versions, adds comments, views documents they created, prints QR labels, uses the staff scan console, receives in-app notifications, manages own profile and security settings, submits support tickets.
2. Admin (Office Head / Records Officer) – everything a User can do, plus for documents held by their office: receive, forward, mark complete, digitally sign, archive and restore. Sees every document that ever passed through their office, the admin dashboard, office reports and exports, office user list, office settings.
3. Super Admin (CICTO system administrator) – everything above for every office, plus: create user accounts, assign roles, reset passwords, activate/deactivate accounts, change workflow settings (allow self-approval), run and record backups, verify all signatures, view the security event log, system-wide dashboard and reports.
4. Public Scanner (anonymous member of the public) – scans a QR label and sees a public status page (control number, status, current office, last update); verifies a signature certificate by serial at /verify/{serial}; reads the Help/FAQ pages and the privacy notice.
5. Scheduler (cron) – 01:00 daily backup; 02:30 daily signature re-verification; 08:00 daily deadline sweep that notifies offices of documents due soon and overdue.
6. Mail Server (SMTP) – external system that delivers email verification, password reset and support ticket emails. No emails are sent for document events.
7. File Storage – disk or cloud bucket holding uploaded document files, signature images and support screenshots.
8. Backup Storage – disk or cloud bucket holding backup archives (database dump + document files).

DOCUMENT LIFECYCLE (as built today)
Internal statuses: initiated → under_review → completed. Client-facing labels: Pending (initiated), In Process (under_review), Completed. "Rejected" and the legacy statuses approved/returned exist only for documents created before 2026-09-03 and appear in old audit trails and report counts. DO NOT draw approve, reject or return as available actions; the client removed them ("received only") so that a queued route never stalls.
Movement actions that can be performed today: registered, received, forwarded, completed, archived, restored. Each one is a row in the movement ledger (document_movements). Priority: low, normal, high, urgent.
Who may act: Receive and Forward – any active user of the office currently holding the document who can see it (in practice the office Admin, or the clerk who filed it while it is still at their office). Complete and Sign – Admin or Super Admin only, and blocked for the document's own author unless the Super Admin has enabled self-approval. Archive/Restore – Admin, only once the document is Completed (or legacy Rejected).

KEY PROCESSES
P1 Authentication & Account Management – login with email + password, optional two-factor code or passkey, email verification, password reset, account-active check, lockout after repeated failures, role-aware redirect (User / Admin / Super Admin dashboard). Super Admin creates accounts, assigns roles, resets passwords, deactivates accounts. Every auth and account event is written to the security event log.
P2 Document Registration – User enters title, document type, priority, description, remarks, optional attachment (pdf/doc/docx/xls/xlsx/png/jpg, max 10 MB) and picks one or more destination offices plus a delivery mode. The system allocates a gapless control number (OFFICE-CODE-YEAR-00001), generates a unique QR token, computes the due date (registration date + document type turnaround days, default 3, due at 6:00 PM), writes movement #1 "registered", stores the file with a SHA-256 checksum, and writes in-app notifications to the receiving office's Admins. Delivery modes: (a) sequential route – first office receives now, the remaining offices are queued as route stops in order; (b) simultaneous distribution – one separate document (own control number, own trail) per office, linked by a shared submission group id.
P3 Document Routing & Workflow – Receive: acknowledges arrival, closes the open leg and opens a "received" leg for the same office; if a queued route stop exists the system automatically forwards to that office and notifies it; if the route is exhausted the system automatically marks the document complete. Forward: the holder picks one or more offices; pending stops are cancelled, the first office becomes the destination, the rest are queued. Complete: Admin closes the document (blocked while route stops are pending). Every action closes the open movement leg (departed_at) and opens a new one (arrived_at, due_at). A stale-state check rejects an action when someone else moved the document first. Remarks typed on an action are stored as a comment on that leg.
P4 Digital Signature – Admin confirms password, signs the current file version with a drawn or typed signature; the system hashes (SHA-256) the signature payload together with the file checksum, issues a serial, stores the signature, logs a security event, and produces a downloadable certificate PDF. Anyone can verify at /verify/{serial}. The nightly job recomputes every hash and logs "signature tampered" on any mismatch.
P5 QR Scan & Tracking – The QR label encodes https://<scan-base-url>/s/{token}. A scan writes a scan record (document, user or IP, office, source camera/wedge/manual, time; repeats within 60 s are ignored). Signed-in staff with access are redirected to the full document page; everyone else sees the public status page. Staff also have a scan console (camera, barcode wedge or manual entry).
P6 Files, Comments & Archive – upload new file versions (versioned, checksummed; downloads logged as security events), comment threads per document (internal flag; edit/delete own), archive completed documents and restore them.
P7 Notifications & Deadlines – in-app notifications (bell + notifications page) fan out to the receiving office's Admins on register and forward; the 08:00 sweep writes one "due soon" or "overdue" notification per document per day and stamps the document so it is not repeated.
P8 Reports & Dashboards – role-specific dashboards (my documents / office queue / system-wide), status counts, monthly trend, per-office turnaround; export PDF, XLSX or CSV with row caps.
P9 System Administration – Super Admin settings (workflow rules), backup console (run now, history, record a restore), verify all signatures, security event log; Admin office settings.
P10 Help & Support – public FAQ / knowledge base / contact page and privacy notice; signed-in users submit a support ticket (subject, message, optional screenshot) that is emailed to the CICTO support mailbox.

DATA STORES (database tables)
D1 users, D2 offices (self-referencing parent for office / department / division), D3 document_types, D4 document_number_sequences, D5 documents, D6 document_movements (the ledger: one row per custody leg, also the audit trail), D7 document_files, D8 document_route_stops, D9 document_comments, D10 document_signatures, D11 document_scans, D12 notifications, D13 security_events, D14 app_settings, D15 backup_runs. Plus two file stores: F1 File Storage and F2 Backup Storage.
```

---

## PROMPT 1 — System Flowchart

```
TASK: Create the SYSTEM FLOWCHART of the CICTO Document Tracking System described in the brief above.

Scope: the end-to-end flow of one document, from a staff member logging in to the document being completed and archived, plus the QR scan side path and the daily scheduler jobs as a separate lane at the bottom.

Notation (standard ANSI/ISO flowchart symbols):
- Terminator (rounded rectangle) for Start / End
- Rectangle for a process step
- Diamond for a decision, with Yes / No on every outgoing arrow
- Parallelogram for input/output (form input, file upload, printed QR label, exported report)
- Cylinder for a database store, labelled with the table name from the brief (documents, document_movements, ...)
- Document symbol for generated documents (QR label, signature certificate PDF, report export)
- Off-page connector only if the diagram must split into pages

Required flow, in this order:
1. Start → Open system → Login (email + password) → decision "Credentials valid and account active?" (No → error; lockout after repeated failures → back to Login) → decision "Two-factor enabled?" (Yes → enter 2FA code or passkey) → Role-aware redirect → User dashboard / Admin dashboard / Super Admin dashboard.
2. Register document: fill registration form (title, type, priority, description, remarks, attachment, destination office(s), delivery mode) → validate → decision "Simultaneous distribution to several offices?" (Yes → create one document per office with a shared submission group; No → create one document and queue the remaining offices as route stops) → allocate control number → generate QR token → compute due date → write movement #1 "registered" → store file with checksum → notify receiving office Admins → print QR label (document symbol).
3. Receiving office: Admin opens document → Receive → close open leg, open "received" leg → decision "Queued route stop remaining?" (Yes → auto-forward to next office → notify → loop back to "Receiving office"; No → decision "Did the document have a route?" Yes → auto-complete (go to step 5); No → document rests with this office → step 4).
4. Holder's next action (one decision): Forward / Sign / Complete.
   - Forward → choose office(s) → cancel pending stops → close open leg, open new leg to first office, queue the rest → notify → loop to "Receiving office".
   - Sign → confirm password → decision "Drawn or typed?" → compute SHA-256 hash and serial → save signature → log security event → certificate PDF (document symbol) → back to step 4.
   - Complete → decision "Any pending route stops?" (Yes → not allowed, back to step 4; No → status Completed, stamp completed_at).
5. Terminal: decision "Archive?" → Yes → stamp archived_at → End. Show Restore as a dashed arrow back to the completed state. No → End.
6. Side path (small separate flow on the same page): QR label scanned → decision "Valid token and document exists?" (No → not-found page) → log scan (skip if same scanner within 60 s) → decision "Signed in with access?" (Yes → full document page; No → public status page).
7. Bottom lane "Scheduler": 01:00 backup → dump database + files → write backup_runs → security event; 02:30 verify all signatures → decision "Hash mismatch?" → log "signature tampered"; 08:00 deadline sweep → decision per document "Due soon / Overdue?" → write notifications → stamp document.

Rules:
- Every diamond has two or more labelled exits, and every path reaches End or loops back explicitly.
- Do not draw approve, reject or return steps; they no longer exist.
- Put a database cylinder beside each step that writes one: documents, document_movements, document_files, document_route_stops, document_signatures, document_scans, notifications, security_events, backup_runs.
- Use the client-facing status labels (Pending, In Process, Completed) inside the flow; add the internal status in brackets only where it differs.
- Keep text inside shapes to 6 words or fewer; longer explanations go in a note beside the shape.
- Landscape, top-to-bottom main flow, side flows on the right, Scheduler lane at the bottom.

Output:
1. A rendered SVG artifact I can download and print on A3.
2. The Mermaid source (flowchart TD) for the same diagram in a code block, so I can edit and regenerate it.
3. A legend listing every symbol used.
Do not add features that are not in the brief. Where something is ambiguous, choose the simplest reading and list the assumption under the diagram.
```

---

## PROMPT 2 — Activity Diagram (UML)

```
TASK: Create a UML 2.5 ACTIVITY DIAGRAM with swimlanes (activity partitions) for the CICTO Document Tracking System described in the brief above.

Swimlanes, left to right: User (Clerk) | System (CICTO DTS) | Admin (Holding Office) | Super Admin | Scheduler.

Notation:
- Filled circle = initial node; encircled filled circle = activity final; circle with X = flow final.
- Rounded rectangle = action.
- Diamond = decision and merge, with guards in square brackets on every outgoing edge, e.g. [valid] / [invalid].
- Thick bar = fork / join for parallel actions (every fork has a matching join).
- Rectangle = object node showing the document state in brackets, e.g. "Document [Pending]", "QR label", "Certificate PDF".
- Hourglass symbol for the time events 01:00, 02:30 and 08:00.
- Dashed note boxes for constraints.

Main activity: "Process an official document", from registration to archive.

User lane: Log in → Fill registration form → Attach file → Choose destination office(s) and delivery mode → Submit.
System lane: Validate input → decision [invalid] back to form / [valid] → decision [simultaneous distribution] create one document per office with shared submission group / [sequential route] create one document and queue route stops → Allocate control number → Generate QR token → Compute due date → Write movement #1 "registered" → Store file with checksum → fork: (a) Write notifications for receiving office Admins, (b) Render QR label → join → object node "Document [Pending]".
Admin lane: Open document → Receive.
System lane: Close open leg, open "received" leg → object node "Document [In Process]" → decision [queued stop exists] → Auto-forward to next stop and notify → back to Admin "Open document" (next office) / [no stops, route existed] → Mark complete → object node "Document [Completed]" / [no route] → Admin decision: Forward | Sign | Complete.
- Forward: Admin selects office(s) → System: cancel pending stops, create movement "forwarded", queue remaining offices, notify → back to Admin "Open document".
- Sign: Admin confirms password → Draw or type signature → System: compute hash, issue serial, save signature, log security event → object node "Certificate PDF" → merge back to the Admin decision.
- Complete: decision [pending stops] not allowed, back to decision / [none] → System: create movement "completed", stamp completed_at → object node "Document [Completed]".
After Completed: Admin decision [archive] → System: stamp archived_at → activity final; [keep open] → activity final. Show Restore as an optional action returning to "Document [Completed]".
Constraint notes: "Complete and Sign are Admin only; blocked for the document's own author unless self-approval is enabled", "Stale-state check: the action is rejected if another user moved the document first", "Approve, reject and return were removed on 2026-09-03; only receive advances a route".
Super Admin lane: only what gates the main flow: Create account / Reset password (feeding the User's Log in), Enable or disable self-approval (feeding the constraint note).
Scheduler lane, with its own initial node and running in parallel: 08:00 Deadline sweep → System: for each active document decision [due soon] write "pending" notification / [overdue] write "overdue" notification / [on track] skip; 02:30 Verify signatures → System: recompute hashes → [mismatch] log "signature tampered"; 01:00 Backup → System: dump database and files → store archive → record backup run.

Rules:
- Guards on every outgoing edge of every decision; every fork has a join.
- Object nodes use the client-facing labels (Pending, In Process, Completed).
- Keep arrows orthogonal and avoid crossing lanes more than necessary.
- Do not add approve, reject or return actions.

Output:
1. Rendered SVG artifact, landscape, A3-printable.
2. PlantUML source (@startuml ... @enduml, using |Swimlane| partitions) in a code block. If you cannot produce PlantUML, give Mermaid flowchart source with subgraphs as swimlanes and say so.
3. A legend.
Stick to the brief; do not invent extra actors or steps.
```

---

## PROMPT 3 — Use Case Diagram (UML)

```
TASK: Create a UML 2.5 USE CASE DIAGRAM for the CICTO Document Tracking System described in the brief above.

Notation:
- Stick figures for actors, placed outside the system boundary. Human actors on the left; non-human actors (Scheduler, Mail Server, File Storage, Backup Storage) on the right.
- One rectangle labelled "CICTO Document Tracking System" as the system boundary.
- Ovals for use cases inside the boundary, grouped visually by module: Authentication, Document Registration, Workflow, Signatures, Tracking, Files & Comments, Notifications, Reports, Administration, Help & Support.
- Solid lines for actor–use case associations.
- Actor generalisation: Admin inherits from User; Super Admin inherits from Admin. Draw the hollow-triangle arrows and do not repeat inherited associations.
- Dashed <<include>> and <<extend>> arrows only where listed below.

Actors: User (Clerk), Admin (Office Head), Super Admin, Public Scanner, Scheduler, Mail Server, File Storage, Backup Storage.

Use cases and associations:
User:
- Log in (<<include>> Verify credentials; <<extend>> Complete two-factor challenge; <<extend>> Sign in with passkey)
- Reset password (Mail Server as secondary actor)
- Verify email address (Mail Server secondary)
- Manage profile and security (change password, enable 2FA, register passkey)
- Register document (<<include>> Allocate control number; <<include>> Generate QR label; <<include>> Set due date; <<extend>> Attach file; <<extend>> Queue route stops; <<extend>> Distribute to several offices)
- Upload new file version (File Storage secondary)
- Download file
- Add / edit / delete comment
- View my documents
- Search and filter documents
- View document details and trail
- Print QR label
- Scan QR label (staff scan console; <<include>> Log scan)
- View notifications
- Submit support ticket (Mail Server secondary; <<extend>> Attach screenshot)
- Read help articles
Admin (additional to the inherited ones):
- Receive document (<<include>> Advance route)
- Forward document (<<extend>> Queue several offices)
- Mark document complete
- Sign document (<<include>> Confirm password; <<include>> Generate certificate PDF)
- Archive document
- Restore document
- View office dashboard
- Export office reports (PDF / XLSX / CSV)
- View office users
- Update office settings
Super Admin (additional):
- Create user account
- Assign role
- Reset user password
- Activate / deactivate account
- Update workflow settings
- Run backup now (Backup Storage secondary)
- Record backup restore
- Verify all signatures
- View security event log
- View system-wide dashboard and reports
Public Scanner:
- View document status via QR (<<include>> Log scan)
- Verify signature by serial
- Read help articles
- Read privacy notice
Scheduler:
- Run nightly backup (Backup Storage secondary)
- Re-verify signatures nightly
- Send deadline notifications

Rules:
- Use case names are verb + object, at most 5 words.
- Every use case has at least one actor. No approve, reject or return use cases.
- Keep <<include>> and <<extend>> arrows to the ones listed; do not chain them.
- Under the diagram add a numbered table: use case, primary actor, one-line goal.

Output:
1. Rendered SVG artifact (portrait A3 or landscape A2, legible).
2. PlantUML source (@startuml with actor / usecase / rectangle / package syntax) in a code block.
3. The use case table.
Do not add use cases beyond this list.
```

---

## PROMPT 4 — Context Diagram (Level 0 DFD)

```
TASK: Create the CONTEXT DIAGRAM (Level 0 Data Flow Diagram) of the CICTO Document Tracking System described in the brief above.

Notation: Gane & Sarson. One rounded rectangle for the single process, squares for external entities, labelled arrows for data flows. No data stores appear at Level 0.

Process in the centre: "0  CICTO Document Tracking System".

External entities (humans on the left, systems on the right):
1. User (Clerk)   2. Admin (Office Head)   3. Super Admin   4. Public Scanner
5. Scheduler (cron)   6. Mail Server (SMTP)   7. File Storage   8. Backup Storage

Data flows (each a labelled arrow in the correct direction; group related items with commas, at most 3–4 arrows per direction per entity):
User → System: login credentials, 2FA code / passkey; registration details (title, type, priority, description, remarks, destination offices, delivery mode) + attachment; new file version; comment; search / filter criteria; QR token (scan); support ticket (+ screenshot); profile / security changes.
System → User: role-based dashboard; document list, details and movement trail; control number and QR label; file download; in-app notifications; help articles; ticket confirmation.
Admin → System: everything the User sends, plus: workflow action (receive / forward / complete) with destination office(s), remarks and expected movement id; signature (drawn image or typed name) + password confirmation; archive / restore request; report filters and export format; office settings.
System → Admin: office queue and dashboard; action confirmation; signature certificate PDF; report export (PDF / XLSX / CSV); office user list; deadline notifications.
Super Admin → System: new account details; role assignment; password reset; activate / deactivate; workflow settings (allow self-approval); backup request; restore record; signature verification request.
System → Super Admin: system-wide dashboard and reports; user list; temporary password; backup history and status; signature verification result; security event log.
Public Scanner → System: QR token (scan); signature serial.
System → Public Scanner: public document status (control number, status, current office, last update); signature verification result; help / FAQ pages; privacy notice.
Scheduler → System: daily triggers (01:00 backup, 02:30 verify signatures, 08:00 deadline sweep).
System → Scheduler: job result / log line.
System → Mail Server: email verification message; password reset email; support ticket email.
Mail Server → System: delivery status.
System → File Storage: document file (versioned, checksummed); signature image; support screenshot.
File Storage → System: file contents for download.
System → Backup Storage: backup archive (database dump + files).
Backup Storage → System: stored backup listing and size.

Rules:
- Exactly one process. No data stores. No flow between two external entities.
- Every arrow has a noun-phrase label.
- Keep the process centred and labels non-overlapping.

Output:
1. Rendered SVG artifact, landscape.
2. Mermaid source (flowchart LR; entities as rectangles, process as a stadium node) in a code block.
3. A data flow table: number, source, destination, flow name, contents.
Do not add external entities beyond this list.
```

---

## PROMPT 5 — Data Flow Diagram (Level 1)

```
TASK: Create the LEVEL 1 DATA FLOW DIAGRAM of the CICTO Document Tracking System described in the brief above, decomposing process 0 of the context diagram. It must balance with Level 0: every external entity and every Level 0 flow must reappear here, attached to the sub-process that handles it.

Notation: Gane & Sarson. Processes = rounded rectangles with a numbered top band; data stores = open-ended rectangles with an ID (D1 ...); external entities = squares (may be duplicated with a diagonal slash mark to reduce crossings); data flows = labelled arrows (store → process = read, process → store = write, double-headed = both).

Processes (use exactly these numbers and names):
1.0 Authenticate & Manage Accounts
2.0 Register Document
3.0 Route & Process Document (receive, forward, complete, archive / restore)
4.0 Sign & Verify Document
5.0 Track via QR Scan
6.0 Manage Files & Comments
7.0 Notify & Monitor Deadlines
8.0 Generate Reports & Dashboards
9.0 Administer System (settings, backups, security log)
10.0 Help & Support

Data stores: D1 users, D2 offices, D3 document_types, D4 document_number_sequences, D5 documents, D6 document_movements, D7 document_files, D8 document_route_stops, D9 document_comments, D10 document_signatures, D11 document_scans, D12 notifications, D13 security_events, D14 app_settings, D15 backup_runs, F1 File Storage (external), F2 Backup Storage (external).

Required flows per process:
1.0: User / Admin / Super Admin → credentials, 2FA code; ← session and role-based redirect. Reads and writes D1; reads D2 (office membership); writes D13 (login success / failure / lockout / logout, password reset, 2FA on/off, user created, role changed, deactivated / reactivated). Super Admin → new account, role, password reset, activate / deactivate; ← user list, temporary password. → Mail Server: verification and reset emails.
2.0: User → registration details, attachment, destination offices, delivery mode. Reads D2, D3; reads and writes D4 (next control number); writes D5 (document, QR token, due date, submission group), D6 (movement #1 registered), D8 (queued route stops), D7 and F1 (file with checksum). → 7.0: "document registered" event. ← User: control number, QR label.
3.0: Admin → action (receive / forward / complete / archive / restore), destination office(s), remarks, expected movement id. Reads D14 (allow self-approval), D2 (active offices); reads and writes D5 (status, completed_at, archived_at), D6 (close open leg, open new leg), D8 (visit / cancel / queue stops); writes D9 (remarks stored as comment). → 7.0: "document forwarded" event. ← Admin: confirmation, updated trail.
4.0: Admin → signature (drawn / typed) + password confirmation; reads D7 (current file checksum), D6 (open leg), D1 (signer name, position, office); writes D10, F1 (signature image), D13 (document signed); ← Admin: certificate PDF. Public Scanner → serial; ← verification result. Scheduler → 02:30 trigger; reads D10, D7; writes D13 (signature tampered); ← Super Admin: verification summary on request.
5.0: Public Scanner / User → QR token; reads D5, D6 (open leg → current office); writes D11 (scan: user or IP, office, source, time, 60 s dedupe); ← public status page, or redirect to full document view.
6.0: User / Admin → new file version, comment, download request; reads and writes D7, F1, D9; writes D13 (file downloaded); ← file contents, comment thread.
7.0: ← events from 2.0 and 3.0; Scheduler → 08:00 trigger; reads D5 (due_at, warned / notified stamps), D6 (open leg → office), D1 (Admins of that office); writes D12 (deduplicated per document per day), D5 (stamps). → User / Admin: notification list, unread count.
8.0: Admin / Super Admin → report filters, export format; reads D5, D6, D2, D3, D1; → dashboard statistics, monthly trend, per-office turnaround, PDF / XLSX / CSV export (row caps enforced).
9.0: Super Admin → workflow setting, backup request, restore record, security log request; reads and writes D14; Scheduler → 01:00 trigger; reads D1–D15 and F1 (dump); writes F2 (archive), D15 (run status, size, path), D13 (backup completed / failed / restored); ← backup history, security event log, settings form.
10.0: Public Scanner / User → article request, ticket (subject, message, screenshot); reads a static knowledge base (show as a note, not a store) and D1 (reporter identity); writes F1 (screenshot); → Mail Server: support ticket email; ← articles, contact details, ticket confirmation.

Rules:
- Every data store is touched by at least one process; every process has at least one input and one output.
- No flow directly between two external entities, and none directly between two data stores.
- Process-to-process flows only where listed (2.0 → 7.0, 3.0 → 7.0).
- Label every arrow with a noun phrase of 5 words or fewer; merge related flows with commas so no process has more than about 6 arrows per side.
- Layout: external entities on the outer edge, processes in numeric order in a ring or grid, data stores between the processes that share them. Duplicate D5, D6 and D13 if that removes crossings.
- No approve, reject or return flows.

Output:
1. Rendered SVG artifact, landscape A2, font no smaller than 9 pt.
2. Mermaid source (flowchart LR; processes as stadium nodes, data stores as cylinders [( )], entities as rectangles) in a code block.
3. A balancing table: each Level 0 flow → the Level 1 process that now carries it.
4. A data dictionary for the 15 stores: ID, table, key fields, processes that read it, processes that write it.
Do not add processes, stores or entities beyond this list.
```
