from dg import *

OUT = "build/d3"
c = Canvas(A2L)
c.title_block("Use Case Diagram", "Actors, use cases and the include / extend relationships.  Notation: UML 2.5 use case diagram.  Admin inherits every User use case; Super Admin inherits every Admin use case.", page=1)

RX, RY = 37, 5.4  # oval radii
PITCH = 13
X1, X2, X3, X4 = 136, 236, 336, 436
XL, XR = 44, 526
BX0, BX1, BY0, BY1 = 84, 486, 40, 400

# system boundary
c.rect(BX0, BY0, BX1 - BX0, BY1 - BY0, "#FBFCFE", "#1c1c1c", 0.5)
c.text(BX0 + 4, BY0 + 5, "CICTO Document Tracking System", size=3.8, anchor="start", weight="bold")

uc = {}  # name -> (x, y)


def group(x, y, title):
    c.text(x - RX, y, title, size=2.6, anchor="start", weight="bold", fill="#666")


def oval(x, y, name, label=None, fill=None):
    label = label or name
    lines = label if isinstance(label, list) else [label]
    c.usecase(x, y, RX, RY, lines, size=2.9, fill=fill)
    uc[name] = (x, y)


def column(x, y0, groups):
    y = y0
    for title, names in groups:
        group(x, y, title)
        y += 7
        for n in names:
            oval(x, y, n)
            y += PITCH
    return y


# ---------------- column 1: User + Public base use cases
column(X1, 54, [
    ("Account recovery and support (email)", ["Submit support ticket", "Reset password", "Verify email address"]),
    ("Authentication", ["Log in", "Manage profile and security"]),
    ("Registration", ["Register document"]),
    ("Tracking and viewing", ["View my documents", "Search and filter documents", "View document details and trail",
                              "Add / edit / delete comment", "Print QR label", "Scan QR label", "View notifications"]),
    ("Files", ["Upload new file version", "Download file"]),
    ("Public access", ["Read help articles", "View document status via QR", "Verify signature by serial", "Read privacy notice"]),
])

# ---------------- column 2: User sub use cases
subs2 = [("Attach screenshot", 61), ("Verify credentials", 80), ("Complete two-factor challenge", 93), ("Sign in with passkey", 106),
         ("Allocate control number", 126), ("Generate QR label", 139), ("Set due date", 152),
         ("Attach file", 165), ("Queue route stops", 178), ("Distribute to several offices", 191),
         ("Log scan", 250)]
for n, y in subs2:
    oval(X2, y, n, fill="#FAFAFA")

# ---------------- column 4: Admin, Super Admin, Scheduler base use cases
column(X4, 54, [
    ("Workflow (Admin)", ["Receive document", "Forward document", "Mark document complete", "Archive document", "Restore document"]),
    ("Signatures (Admin)", ["Sign document"]),
    ("Office administration (Admin)", ["View office dashboard", "Export office reports", "View office users", "Update office settings"]),
    ("Accounts (Super Admin)", ["Create user account", "Assign role", "Reset user password", "Activate / deactivate account"]),
    ("System administration (Super Admin)", ["Update workflow settings", "Verify all signatures", "View security event log",
                                             "View system-wide dashboard", "Record backup restore", "Run backup now"]),
    ("Scheduled jobs", ["Run nightly backup", "Re-verify signatures nightly", "Send deadline notifications"]),
])

# ---------------- column 3: Admin sub use cases
for n, y in [("Advance route", 61), ("Queue several offices", 74), ("Confirm password", 126), ("Generate certificate PDF", 139)]:
    oval(X3, y, n, fill="#FAFAFA")


# ---------------- relationships
def rel(a, b, kind):
    """dashed arrow from a to b with <<kind>>; include: base->included, extend: extension->base."""
    (x1, y1), (x2, y2) = uc[a], uc[b]
    dx = 1 if x2 > x1 else -1
    p1 = (x1 + dx * RX, y1)
    p2 = (x2 - dx * RX, y2)
    c.edge([p1, p2], dashed=True)
    mx, my = (p1[0] + p2[0]) / 2, (p1[1] + p2[1]) / 2
    c.label(mx, my - 2.2, f"«{kind}»", size=2.3, fill="#333")


rel("Log in", "Verify credentials", "include")
rel("Complete two-factor challenge", "Log in", "extend")
rel("Sign in with passkey", "Log in", "extend")
for n in ["Allocate control number", "Generate QR label", "Set due date"]:
    rel("Register document", n, "include")
for n in ["Attach file", "Queue route stops", "Distribute to several offices"]:
    rel(n, "Register document", "extend")
rel("Scan QR label", "Log scan", "include")
rel("View document status via QR", "Log scan", "include")
rel("Attach screenshot", "Submit support ticket", "extend")
rel("Receive document", "Advance route", "include")
rel("Queue several offices", "Forward document", "extend")
rel("Sign document", "Confirm password", "include")
rel("Sign document", "Generate certificate PDF", "include")

# ---------------- actors and associations
def assoc(ax, ay, names, side, end="mid"):
    for n in names:
        x, y = uc[n]
        if end == "bl":
            ex, ey = x - RX * 0.55, y + RY * 0.82
        else:
            ex, ey = (x - RX if side == "L" else x + RX), y
        c.line(ax, ay, ex, ey, C["line"], 0.35)


c.actor(XL, 74, "Mail Server (SMTP)", scale=0.9)
assoc(XL + 6, 72, ["Submit support ticket", "Reset password", "Verify email address"], "L")
c.actor(XL, 190, "User (Clerk)")
assoc(XL + 6, 188, ["Submit support ticket", "Reset password", "Verify email address"], "L", end="bl")
assoc(XL + 6, 188, ["Log in", "Manage profile and security", "Register document", "View my documents",
                    "Search and filter documents", "View document details and trail", "Add / edit / delete comment",
                    "Print QR label", "Scan QR label", "View notifications", "Upload new file version", "Download file",
                    "Read help articles"], "L")
c.actor(XL, 312, "Public Scanner", scale=0.9)
assoc(XL + 6, 310, ["Read help articles", "View document status via QR", "Verify signature by serial", "Read privacy notice"], "L")

c.actor(XR, 125, "Admin (Office Head)")
assoc(XR - 6, 123, ["Receive document", "Forward document", "Mark document complete", "Archive document", "Restore document",
                    "Sign document", "View office dashboard", "Export office reports", "View office users", "Update office settings"], "R")
c.actor(XR, 275, "Super Admin")
assoc(XR - 6, 273, ["Create user account", "Assign role", "Reset user password", "Activate / deactivate account",
                    "Update workflow settings", "Verify all signatures", "View security event log", "View system-wide dashboard",
                    "Record backup restore", "Run backup now"], "R")
c.actor(XR, 322, "File Storage", scale=0.85)
assoc(XR - 6, 320, ["Run backup now", "Run nightly backup"], "R")
c.actor(XR, 355, "Backup Storage", scale=0.85)
assoc(XR - 6, 353, ["Run backup now", "Run nightly backup"], "R")
c.actor(XR, 390, "Scheduler (cron)", scale=0.85)
assoc(XR - 6, 388, ["Run nightly backup", "Re-verify signatures nightly", "Send deadline notifications"], "R")

# generalisation: Super Admin -> Admin, Admin -> User
c.edge([(XR, 264), (XR, 141)], hollow=True)
c.label(XR + 3, 200, "inherits", size=2.4, anchor="start", bg=False, fill="#444")
c.edge([(XR, 114), (XR, 33), (22, 33), (22, 190), (XL - 7, 190)], hollow=True)
c.label(300, 33, "Admin inherits every User use case", size=2.5, fill="#444")
c.label(24, 130, "inherits", size=2.4, anchor="start", bg=False, fill="#444")

# key
c.text(BX0 + 4, 394, "Key: solid line = actor uses the use case;  dashed «include» = always part of the base use case;  dashed «extend» = optional addition;  hollow triangle = actor generalisation;  grey ovals = included / extending use cases.  File Storage also holds uploaded files, signature images and screenshots (see the Level 1 DFD).",
       size=2.7, anchor="start", fill="#333")
c.save(f"{OUT}_p1.svg")

# ---------------- pages 2+: table
rows = [
    ("Log in", "User", "Sign in with email and password; 2FA or passkey when enabled; role-aware redirect."),
    ("Verify credentials", "System (included)", "Check the password, account status and lockout counter; log the event."),
    ("Complete two-factor challenge", "User (extends Log in)", "Enter the one-time code when 2FA is on."),
    ("Sign in with passkey", "User (extends Log in)", "Authenticate with a registered passkey instead of a password."),
    ("Manage profile and security", "User", "Change name, password, appearance; enable 2FA; register a passkey."),
    ("Register document", "User", "File a new document with title, type, priority, description, remarks and destination office(s)."),
    ("Allocate control number", "System (included)", "Issue the next gapless number OFFICE-YEAR-00001 for the originating office."),
    ("Generate QR label", "System (included)", "Create a unique QR token and the printable label linking to /s/{token}."),
    ("Set due date", "System (included)", "Registration date + document type turnaround (3 days default) at 6:00 PM."),
    ("Attach file", "User (extends Register)", "Upload pdf, doc, docx, xls, xlsx, png or jpg up to 10 MB; stored with a SHA-256 checksum."),
    ("Queue route stops", "User (extends Register)", "Send to the first office now and queue the remaining offices in order."),
    ("Distribute to several offices", "User (extends Register)", "Create one independent document per office, linked by a submission group."),
    ("View my documents", "User", "List the documents the user filed, with status and due state."),
    ("Search and filter documents", "User", "Find documents by control number, title, status (Pending / In Process / Completed), office, type, date."),
    ("View document details and trail", "User", "See the document, its files, comments, signatures and the movement ledger with dwell times."),
    ("Add / edit / delete comment", "User", "Discuss a document; internal flag; edit or delete own comments."),
    ("Print QR label", "User", "Print the label for the physical folder."),
    ("Scan QR label", "User", "Read a label with the camera, a wedge scanner or manual entry from the staff scan console."),
    ("Log scan", "System (included)", "Record who scanned, from which office or IP, when and how; ignore repeats within 60 s."),
    ("View notifications", "User", "Read in-app notifications (assigned, forwarded, due soon, overdue); mark all read."),
    ("Upload new file version", "User", "Add a new version of the file; earlier versions are kept."),
    ("Download file", "User", "Download a file version; the download is logged as a security event."),
    ("Read help articles", "User, Public Scanner", "Read the FAQ / knowledge base and the contact page."),
    ("View document status via QR", "Public Scanner", "See control number, status, current office and last update on the public status page."),
    ("Verify signature by serial", "Public Scanner", "Check a certificate serial at /verify/{serial}."),
    ("Read privacy notice", "Public Scanner", "Read what is collected during a scan and the retention periods (RA 10173)."),
    ("Reset password", "User", "Request a reset link by email and set a new password."),
    ("Verify email address", "User", "Confirm the address through the emailed link."),
    ("Submit support ticket", "User", "Send subject, message and optional screenshot to the CICTO support mailbox."),
    ("Attach screenshot", "User (extends ticket)", "Add an image to the ticket; stored on the documents disk."),
    ("Receive document", "Admin", "Acknowledge arrival; the system advances the queued route or completes it."),
    ("Advance route", "System (included)", "Mark the next stop visited and forward, or complete when the route is exhausted."),
    ("Forward document", "Admin", "Send the document to one office; pending stops are cancelled."),
    ("Queue several offices", "Admin (extends Forward)", "Choose several offices: the first receives now, the rest are queued."),
    ("Mark document complete", "Admin", "Close the document; refused while route stops are pending."),
    ("Archive document", "Admin", "Move a completed document to the archive."),
    ("Restore document", "Admin", "Bring an archived document back."),
    ("Sign document", "Admin", "Apply a drawn or typed signature to the current file version."),
    ("Confirm password", "Admin (included)", "Re-enter the password so the signature identifies the person, not the browser."),
    ("Generate certificate PDF", "System (included)", "Produce the certificate with serial, hash and signer details."),
    ("View office dashboard", "Admin", "Office queue, counts by status and due state."),
    ("Export office reports", "Admin", "Monthly trend, turnaround; export PDF, XLSX or CSV (row caps apply)."),
    ("View office users", "Admin", "List the accounts in the office."),
    ("Update office settings", "Admin", "Maintain the office's own settings."),
    ("Create user account", "Super Admin", "Create an account with role and office; there is no self-registration."),
    ("Assign role", "Super Admin", "Set User, Admin or Super Admin."),
    ("Reset user password", "Super Admin", "Issue a temporary password for an account that cannot receive mail."),
    ("Activate / deactivate account", "Super Admin", "Block or restore sign-in."),
    ("Update workflow settings", "Super Admin", "Allow or block self-approval of Complete and Sign."),
    ("Verify all signatures", "Super Admin", "Recompute every signature hash on demand."),
    ("View security event log", "Super Admin", "Review logins, lockouts, role changes, downloads, signatures, backups."),
    ("View system-wide dashboard", "Super Admin", "Counts and trends across all offices; export reports."),
    ("Record backup restore", "Super Admin", "Note that a backup was restored, with a security event."),
    ("Run backup now", "Super Admin", "Dump database and files to the backup storage immediately."),
    ("Run nightly backup", "Scheduler", "01:00 daily backup; keeps the last 14 runs."),
    ("Re-verify signatures nightly", "Scheduler", "02:30 daily hash check; logs 'signature tampered' on mismatch."),
    ("Send deadline notifications", "Scheduler", "08:00 daily: 'due soon' and 'overdue' notifications to the holding office's Admins."),
]
rows = [[str(i + 1), a, b, d] for i, (a, b, d) in enumerate(rows)]
pages = table_pages("Use Case Diagram", "Use case table: number, use case, primary actor, goal.", ["#", "Use case", "Primary actor", "Goal (one line)"],
                    rows, [10, 70, 60, 420], size=A2L, start_page=2)
for i, pg in enumerate(pages):
    pg.save(f"{OUT}_p{i + 2}.svg")
print("d3 ok")
