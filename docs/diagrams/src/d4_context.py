from dg import *

OUT = "build/d4"
c = Canvas(A3L)
c.title_block("Context Diagram (Level 0 DFD)", "One process, eight external entities, and every data flow between them.  Notation: Gane & Sarson.", page=1)

PX, PW, PY0, PY1 = 210, 76, 52, 272
c.gs_process(PX, (PY0 + PY1) / 2, PW, PY1 - PY0, "0", ["CICTO", "Document", "Tracking", "System"], band=8)

EW, EH = 46, 16
LX, RX = 46, 374
flows = []  # (source, dest, name, contents)


def entity_flows(side, y, name, num, inbound, outbound, sub=None):
    """inbound: list of lines (entity -> system); outbound: list of lines (system -> entity)."""
    ex = LX if side == "L" else RX
    lines = [f"{num}  {name}"] + ([sub] if sub else [])
    c.gs_entity(ex, y, EW, EH, lines)
    if side == "L":
        x_from, x_to = ex + EW / 2, PX - PW / 2
        lx = x_from + 3
    else:
        x_from, x_to = ex - EW / 2, PX + PW / 2
        lx = x_to + 3
    n_in, n_out = len(inbound), len(outbound)
    # inbound arrow sits above centre, outbound below
    yi = y - 3
    yo = y + 3
    c.edge([(x_from, yi), (x_to, yi)])
    c.edge([(x_to, yo), (x_from, yo)])
    c.text(lx, yi - 2.2 - (n_in - 1) * 1.55, inbound, size=2.5, anchor="start", fill="#222")
    c.text(lx, yo + 2.2 + (n_out - 1) * 1.55, outbound, size=2.5, anchor="start", fill="#222")


# ---------------- left: humans
entity_flows("L", 78, "User (Clerk)", "1",
             ["credentials, 2FA code / passkey; registration details",
              "(title, type, priority, description, remarks, offices,",
              "delivery mode) + attachment; new file version; comment;",
              "search / filter criteria; QR token (scan); support ticket",
              "(+ screenshot); profile and security changes"],
             ["role-based dashboard; document list, details and",
              "movement trail; control number and QR label; file",
              "download; in-app notifications; help articles;",
              "ticket confirmation"])
entity_flows("L", 136, "Admin", "2",
             ["everything a User sends, plus: workflow action (receive /",
              "forward / complete / archive / restore) with destination",
              "office(s), remarks, expected leg id; signature (drawn or",
              "typed) + password confirmation; report filters and",
              "export format; office settings"],
             ["office queue and dashboard; action confirmation;",
              "signature certificate PDF; report export (PDF / XLSX /",
              "CSV); office user list; deadline notifications"], sub="(Office Head)")
entity_flows("L", 194, "Super Admin", "3",
             ["new account details; role assignment; password reset;",
              "activate / deactivate; workflow settings (allow",
              "self-approval); backup request; restore record;",
              "signature verification request"],
             ["system-wide dashboard and reports; user list;",
              "temporary password; backup history and status;",
              "signature verification result; security event log"])
entity_flows("L", 246, "Public Scanner", "4",
             ["QR token (scan of a printed label); signature serial"],
             ["public document status (control number, status, current",
              "office, last update); signature verification result;",
              "help / FAQ pages; privacy notice"], sub="(anonymous)")

# ---------------- right: systems
entity_flows("R", 78, "Scheduler (cron)", "5",
             ["daily triggers: 01:00 backup, 02:30 verify",
              "signatures, 08:00 deadline sweep"],
             ["job result / log line"])
entity_flows("R", 136, "Mail Server", "6",
             ["delivery status"],
             ["email verification message; password reset email;",
              "support ticket email (with optional screenshot)"], sub="(SMTP)")
entity_flows("R", 194, "File Storage", "7",
             ["file contents for download / verification"],
             ["document file (versioned, checksummed); signature",
              "image; support screenshot"], sub="(disk or S3)")
entity_flows("R", 246, "Backup Storage", "8",
             ["stored backup listing and size"],
             ["backup archive (database dump + document files)"], sub="(disk or S3)")

c.text(15, 284, "Reading the diagram: the upper arrow of each pair flows INTO the system, the lower arrow flows OUT of it. "
                "Related items are grouped on one arrow and separated by semicolons. No data stores appear at Level 0.",
       size=2.7, anchor="start", fill="#333")
c.save(f"{OUT}_p1.svg")

# ---------------- page 2: data flow table
rows = [
    ("User", "System", "Sign-in data", "Email and password; 2FA code or passkey."),
    ("User", "System", "Registration", "Title, document type, priority, description, remarks, destination office(s), delivery mode; optional attachment (pdf/doc/docx/xls/xlsx/png/jpg, max 10 MB)."),
    ("User", "System", "File version", "A new version of the document file."),
    ("User", "System", "Comment", "Comment text and internal flag; edits and deletions of own comments."),
    ("User", "System", "Search / filter", "Control number, title, status, office, type, date range."),
    ("User", "System", "QR token", "Token read from a label by camera, wedge scanner or manual entry."),
    ("User", "System", "Support ticket", "Subject, message, contact details, optional screenshot."),
    ("User", "System", "Profile changes", "Name, password, appearance, 2FA enable / disable, passkey registration."),
    ("System", "User", "Dashboard and lists", "Role-based dashboard; document list with status and due state; details and movement trail."),
    ("System", "User", "Control number, QR label", "OFFICE-YEAR-00001 and the printable label."),
    ("System", "User", "File download", "Contents of a stored file version (download is logged)."),
    ("System", "User", "Notifications", "Assigned, forwarded, due soon, overdue; unread count."),
    ("System", "User", "Help and confirmation", "Help articles, contact details, ticket confirmation."),
    ("Admin", "System", "Workflow action", "Receive / forward / complete / archive / restore, destination office(s), remarks, expected leg id."),
    ("Admin", "System", "Signature", "Drawn image or typed name plus password confirmation."),
    ("Admin", "System", "Report request", "Filters and export format (PDF / XLSX / CSV)."),
    ("Admin", "System", "Office settings", "Office-level settings."),
    ("System", "Admin", "Office queue", "Documents held by the office, counts by status and due state."),
    ("System", "Admin", "Confirmation", "Result of the action and the updated trail."),
    ("System", "Admin", "Certificate PDF", "Signature certificate with serial and hash."),
    ("System", "Admin", "Report export", "PDF / XLSX / CSV file (row caps enforced)."),
    ("System", "Admin", "Deadline notifications", "Due soon and overdue notices for documents the office holds."),
    ("Super Admin", "System", "Account management", "New account details, role, password reset, activate / deactivate."),
    ("Super Admin", "System", "Workflow settings", "Allow or block self-approval."),
    ("Super Admin", "System", "Backup request", "Run backup now; record that a backup was restored."),
    ("Super Admin", "System", "Verification request", "Verify all signatures on demand."),
    ("System", "Super Admin", "System-wide views", "Dashboard and reports across all offices; user list; temporary password."),
    ("System", "Super Admin", "Backup history", "Run status, size, path, failures, restores."),
    ("System", "Super Admin", "Security event log", "Logins, lockouts, role changes, downloads, signatures, backups."),
    ("Public Scanner", "System", "QR token", "Token from a printed label."),
    ("Public Scanner", "System", "Signature serial", "Serial from a certificate."),
    ("System", "Public Scanner", "Public status", "Control number, status label, current office, last update."),
    ("System", "Public Scanner", "Verification result", "Whether the signature is valid and who signed."),
    ("System", "Public Scanner", "Public pages", "Help / FAQ pages, privacy notice."),
    ("Scheduler", "System", "Daily triggers", "01:00 backup, 02:30 signature verification, 08:00 deadline sweep."),
    ("System", "Scheduler", "Job result", "Exit status and log line."),
    ("System", "Mail Server", "Outgoing email", "Email verification, password reset, support ticket (with optional screenshot)."),
    ("Mail Server", "System", "Delivery status", "Accepted / failed."),
    ("System", "File Storage", "Stored files", "Document file versions, signature images, support screenshots."),
    ("File Storage", "System", "File contents", "Bytes for download, hashing and backup."),
    ("System", "Backup Storage", "Backup archive", "Database dump plus document files; oldest runs pruned (keep 14)."),
    ("Backup Storage", "System", "Backup listing", "Stored runs and sizes."),
]
rows = [[str(i + 1), a, b, n, d] for i, (a, b, n, d) in enumerate(rows)]
pages = table_pages("Context Diagram (Level 0 DFD)", "Data flow table.", ["#", "Source", "Destination", "Data flow", "Contents"],
                    rows, [10, 34, 34, 52, 260], size=A3L, start_page=2)
for i, pg in enumerate(pages):
    pg.save(f"{OUT}_p{i + 2}.svg")
print("d4 ok")
