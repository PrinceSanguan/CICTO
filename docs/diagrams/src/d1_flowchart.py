from dg import *

OUT = "build/d1"
NW = 58  # node width
DW, DH = 64, 17  # decision


def row(i):
    return 46 + 22 * i


# ============================================================ PAGE 1
c = Canvas(A3L)
c.title_block("System Flowchart", "Main flow: sign in, register, route between offices, sign, complete, archive.  Notation: ANSI/ISO flowchart symbols.", page=1)

# ---------------- column 1: sign in
x = 43
c.terminator(x, row(0), 30, 10, "Start")
c.io(x, row(1), NW, 11, ["Enter email", "and password"])
c.decision(x, row(2), DW, DH, ["Credentials valid and", "account active?"])
c.decision(x, row(3), DW, DH, ["Two-factor", "enabled?"])
c.io(x, row(4), NW, 11, ["Enter 2FA code", "or passkey"])
c.process(x, row(5), NW, 11, ["Redirect by role"])
c.process(x, row(6), NW, 14, ["Open dashboard:", "User, Admin or", "Super Admin"])
c.connector(x, row(7), "1")

c.edge([(x, row(0) + 5), (x, row(1) - 5.5)])
c.edge([(x, row(1) + 5.5), (x, row(2) - 8.5)])
c.edge([(x, row(2) + 8.5), (x, row(3) - 8.5)], "Yes", (x + 2.5, row(2) + 11.5), lanchor="start")
c.edge([(x, row(3) + 8.5), (x, row(4) - 5.5)], "Yes", (x + 2.5, row(3) + 11.5), lanchor="start")
c.edge([(x, row(4) + 5.5), (x, row(5) - 5.5)])
c.edge([(x, row(5) + 5.5), (x, row(6) - 7)])
c.edge([(x, row(6) + 7), (x, row(7) - 4.4)])
# credentials No loop
c.edge([(x + 32, row(2)), (x + 36, row(2)), (x + 36, row(1)), (x + 29, row(1))])
c.label(x + 37.5, row(1) + 8, ["No: error,", "lockout"], size=2.5, anchor="start")
# 2FA No bypass
c.edge([(x + 32, row(3)), (x + 36, row(3)), (x + 36, row(5)), (x + 29, row(5))])
c.label(x + 37.5, row(3) + 9, "No", size=2.6, anchor="start")

# ---------------- column 2: register
x = 121
c.connector(x, 29, "1")
c.io(x, row(0), NW, 16, ["Fill registration form:", "title, type, priority,", "description, remarks,", "attachment, office(s), mode"])
c.decision(x, row(1), DW, DH, ["Input valid?"])
c.decision(x, row(2), DW, DH, ["Send to several offices", "at the same time?"])
c.process(x, row(3), NW, 14, ["Create one document", "per office (shared", "submission group)"])
c.process(x, row(4), NW, 14, ["Create one document;", "queue other offices", "as route stops"])
c.process(x, row(5), NW, 14, ["Allocate control number", "OFFICE-YEAR-00001"], ["document_number_sequences"])
c.process(x, row(6), NW, 14, ["Generate QR token;", "compute due date"], ["documents"])
c.process(x, row(7), NW, 17, ["Write movement #1", "'registered'"], ["document_movements,", "document_route_stops"])
c.process(x, row(8), NW, 14, ["Store file with", "SHA-256 checksum"], ["document_files"])
c.process(x, row(9), NW, 14, ["Notify receiving", "office Admins"], ["notifications"])
c.document(x, row(10), NW, 13, ["Print QR label"])
c.connector(x + 39, row(10), "A")

c.edge([(x, 33.4), (x, row(0) - 8)])
c.edge([(x, row(0) + 8), (x, row(1) - 8.5)])
c.edge([(x, row(1) + 8.5), (x, row(2) - 8.5)], "Yes", (x + 2.5, row(1) + 11.5), lanchor="start")
c.edge([(x, row(2) + 8.5), (x, row(3) - 7)], "Yes", (x + 2.5, row(2) + 11.5), lanchor="start")
# input invalid loop
c.edge([(x + 32, row(1)), (x + 36, row(1)), (x + 36, row(0)), (x + 29, row(0))])
c.label(x + 37.5, row(0) + 11, "No", size=2.6, anchor="start")
# not simultaneous -> row 4
c.edge([(x + 32, row(2)), (x + 36, row(2)), (x + 36, row(4)), (x + 29, row(4))])
c.label(x + 37.5, row(2) + 10, "No", size=2.6, anchor="start")
# simultaneous box skips row 4
c.edge([(x - 29, row(3)), (x - 34, row(3)), (x - 34, row(5)), (x - 29, row(5))])
c.edge([(x, row(4) + 7), (x, row(5) - 7)])
c.edge([(x, row(5) + 7), (x, row(6) - 7)])
c.edge([(x, row(6) + 7), (x, row(7) - 8.5)])
c.edge([(x, row(7) + 8.5), (x, row(8) - 7)])
c.edge([(x, row(8) + 7), (x, row(9) - 7)])
c.edge([(x, row(9) + 7), (x, row(10) - 6.5)])
c.edge([(x + 29, row(10)), (x + 34.6, row(10))])

# ---------------- column 3: receiving office
x = 199
c.connector(x, 29, "A")
c.process(x, row(0), NW, 12, ["Admin opens document", "at holding office"])
c.process(x, row(1), NW, 16, ["Receive: close open leg,", "open 'received' leg"], ["document_movements"])
c.decision(x, row(2), DW, DH, ["Queued route stop", "remaining?"])
c.process(x, row(3), NW, 16, ["Auto-forward to next", "office; notify its Admins"], ["document_movements, notifications"])
c.decision(x, row(4), DW, DH, ["Did the document", "have a route?"])
c.connector(x, row(5), "C")
c.label(x + 7, row(5), "auto-complete", size=2.5, anchor="start", bg=False)
c.decision(x, row(6), DW, DH, ["Holder's next", "action?"])
c.connector(x - 39, row(6), "F", "Forward")
c.connector(x + 39, row(6), "S", "Sign")
c.connector(x, row(7) + 2, "K", "Complete")

c.edge([(x, 33.4), (x, row(0) - 6)])
c.edge([(x, row(0) + 6), (x, row(1) - 8)])
c.edge([(x, row(1) + 8), (x, row(2) - 8.5)])
c.edge([(x, row(2) + 8.5), (x, row(3) - 8)], "Yes", (x + 2.5, row(2) + 11.5), lanchor="start")
# no queued stop -> row 4 via right side
c.edge([(x + 32, row(2)), (x + 36, row(2)), (x + 36, row(4)), (x + 32.5, row(4))])
c.label(x + 37.5, row(2) + 10, "No", size=2.6, anchor="start")
# auto-forward loops back to top (next office)
c.edge([(x - 29, row(3)), (x - 34, row(3)), (x - 34, row(0)), (x - 29, row(0))])
c.label(x - 34, row(1) + 4, ["next", "office"], size=2.4)
# had a route -> auto complete
c.edge([(x, row(4) + 8.5), (x, row(5) - 4.4)], "Yes", (x + 2.5, row(4) + 11.5), lanchor="start")
# no route -> next action
c.edge([(x - 32, row(4)), (x - 36, row(4)), (x - 36, row(6) - 13), (x, row(6) - 13), (x, row(6) - 8.5)])
c.label(x - 37.5, row(4) + 10, "No", size=2.6, anchor="end")
c.edge([(x - 32, row(6)), (x - 34.6, row(6))])
c.edge([(x + 32, row(6)), (x + 34.6, row(6))])
c.edge([(x, row(6) + 8.5), (x, row(7) + 2 - 4.4)])

# ---------------- column 4: forward + sign
x = 277
c.connector(x, 29, "F")
c.label(x + 6, 29, "Forward", size=2.6, anchor="start", bg=False)
c.io(x, 46, NW, 12, ["Choose destination", "office(s)"])
c.process(x, 72, NW, 21, ["Cancel pending stops;", "open new leg to first", "office; queue the rest"], ["document_movements,", "document_route_stops"])
c.process(x, 98, NW, 13, ["Notify destination", "office Admins"], ["notifications"])
c.connector(x, 118, "A")
c.label(x + 6, 118, "to holding office", size=2.5, anchor="start", bg=False)
c.edge([(x, 33.4), (x, 40)])
c.edge([(x, 52), (x, 61.5)])
c.edge([(x, 82.5), (x, 91.5)])
c.edge([(x, 104.5), (x, 113.6)])

c.connector(x, 148, "S")
c.label(x + 6, 148, "Sign", size=2.6, anchor="start", bg=False)
c.io(x, 166, NW, 11, ["Confirm password"])
c.io(x, 188, NW, 12, ["Draw or type", "signature"])
c.process(x, 216, NW, 21, ["Compute SHA-256 hash", "and serial; save signature;", "log security event"], ["document_signatures,", "security_events"])
c.document(x, 244, NW, 14, ["Certificate PDF"])
c.connector(x, 266, "B")
c.label(x + 6, 266, "back to holder", size=2.5, anchor="start", bg=False)
c.edge([(x, 152.4), (x, 160.5)])
c.edge([(x, 171.5), (x, 182)])
c.edge([(x, 194), (x, 205.5)])
c.edge([(x, 226.5), (x, 237)])
c.edge([(x, 251), (x, 261.6)])

# ---------------- column 5: complete + archive
x = 355
c.connector(x, 29, "K")
c.label(x + 6, 29, "Complete", size=2.6, anchor="start", bg=False)
c.decision(x, 50, DW, DH, ["Any pending", "route stops?"])
c.connector(x + 41, 50, "B", "blocked")
c.process(x, 80, NW, 16, ["Status Completed;", "stamp completed_at"], ["documents, document_movements"])
c.connector(x - 39, 78, "C")
c.label(x - 39, 71, "auto-complete", size=2.5, bg=False)
c.decision(x, 110, DW, DH, ["Archive?", "(Admin only)"])
c.process(x, 140, NW, 16, ["Stamp archived_at"], ["documents, document_movements"])
c.terminator(x, 168, 30, 10, "End")

c.edge([(x, 33.4), (x, 41.5)])
c.edge([(x + 32, 50), (x + 36.6, 50)])
c.label(x + 34, 46, "Yes", size=2.5)
c.edge([(x, 58.5), (x, 72)], "No", (x + 2.5, 65), lanchor="start")
c.edge([(x - 34.6, 78), (x - 29, 78)])
c.edge([(x, 88), (x, 101.5)])
c.edge([(x, 118.5), (x, 132)], "Yes", (x + 2.5, 125), lanchor="start")
c.edge([(x + 32, 110), (x + 36, 110), (x + 36, 168), (x + 15, 168)])
c.label(x + 37.5, 120, "No", size=2.6, anchor="start")
c.edge([(x, 148), (x, 163)])
# restore (dashed)
c.edge([(x - 29, 140), (x - 36, 140), (x - 36, 86), (x - 29, 86)], dashed=True)
c.label(x - 36, 125, "Restore", size=2.5)

c.save(f"{OUT}_p1.svg")

# ============================================================ PAGE 2
c = Canvas(A3L)
c.title_block("System Flowchart", "Side paths: QR label scanning (public and staff) and the three daily scheduler jobs.", page=2)

c.text(15, 33, "QR scan path", size=3.8, anchor="start", weight="bold")
y = 52
widths = [26, 50, 58, 54, 58, 54, 26]
gap = (390 - sum(widths)) / 6
xs, left = [], 15
for w in widths:
    xs.append(left + w / 2)
    left += w + gap
c.terminator(xs[0], y, 26, 10, "Start")
c.io(xs[1], y, 50, 12, ["QR label scanned", "/s/{token}"])
c.decision(xs[2], y, 58, 17, ["Valid token and", "document exists?"])
c.process(xs[3], y, 54, 14, ["Log scan; skip a repeat", "within 60 seconds"], ["document_scans"])
c.decision(xs[4], y, 58, 17, ["Signed in with", "access?"])
c.process(xs[5], y, 54, 16, ["Public status page:", "control no., status,", "current office, last update"])
c.terminator(xs[6], y, 26, 10, "End")
c.edge([(xs[0] + 13, y), (xs[1] - 25, y)])
c.edge([(xs[1] + 25, y), (xs[2] - 29, y)])
c.edge([(xs[2] + 29, y), (xs[3] - 27, y)], "Yes", (xs[2] + 34, y - 3))
c.edge([(xs[3] + 27, y), (xs[4] - 29, y)])
c.edge([(xs[4] + 29, y), (xs[5] - 27, y)], "No", (xs[4] + 34, y - 3))
c.edge([(xs[5] + 27, y), (xs[6] - 13, y)])
# branches
c.process(xs[2], 84, 44, 11, ["Not-found page"])
c.terminator(xs[2] + 46, 84, 26, 10, "End")
c.edge([(xs[2], y + 8.5), (xs[2], 78.5)], "No", (xs[2] + 2.5, 70), lanchor="start")
c.edge([(xs[2] + 22, 84), (xs[2] + 33, 84)])
c.process(xs[4], 84, 50, 12, ["Full document page", "(staff view)"])
c.terminator(xs[4] + 50, 84, 26, 10, "End")
c.edge([(xs[4], y + 8.5), (xs[4], 78)], "Yes", (xs[4] + 2.5, 70), lanchor="start")
c.edge([(xs[4] + 25, 84), (xs[4] + 37, 84)])
c.note(15, 96, 150, 11, ["Staff can also open the scan console and read a label with the camera,",
                        "a barcode wedge scanner or manual entry; the same path applies."], size=2.6)

# scheduler lane
c.swimlane(15, 114, 390, 170, "Scheduler (cron) - runs every day without any user; each job is logged and never overlaps itself")


def hrow(y, term, nodes):
    """nodes: list of (kind, w, lines, caption). Returns list of centre x."""
    left = 30
    cx = []
    tw_ = 44
    c.terminator(left + tw_ / 2, y, tw_, 11, term)
    cx.append(left + tw_ / 2)
    left += tw_ + 12
    for kind, w, lines, cap in nodes:
        if kind == "p":
            c.process(left + w / 2, y, w, 16 if cap else 12, lines, cap)
        elif kind == "d":
            c.decision(left + w / 2, y, w, 17, lines)
        elif kind == "e":
            c.terminator(left + w / 2, y, w, 10, lines)
        cx.append(left + w / 2)
        left += w + 12
    return cx


# 01:00 backup
y = 140
xs = hrow(y, "01:00  Backup", [
    ("p", 56, ["Dump database and", "document files"], None),
    ("p", 60, ["Store archive; write", "backup run; log event"], ["backup_runs, security_events"]),
    ("p", 50, ["Prune old runs", "(keep 14)"], ["backup_runs"]),
    ("e", 26, "End", None),
])
c.edge([(xs[0] + 22, y), (xs[1] - 28, y)])
c.edge([(xs[1] + 28, y), (xs[2] - 30, y)])
c.edge([(xs[2] + 30, y), (xs[3] - 25, y)])
c.edge([(xs[3] + 25, y), (xs[4] - 13, y)])
c.hourglass(xs[0] - 28, y)

# 02:30 verify
y = 192
xs = hrow(y, "02:30  Verify signatures", [
    ("p", 56, ["Recompute every", "signature hash"], None),
    ("d", 52, ["Hash", "mismatch?"], None),
    ("p", 60, ["Log 'signature tampered'"], ["security_events"]),
    ("e", 26, "End", None),
])
c.edge([(xs[0] + 22, y), (xs[1] - 28, y)])
c.edge([(xs[1] + 28, y), (xs[2] - 26, y)])
c.edge([(xs[2] + 26, y), (xs[3] - 30, y)], "Yes", (xs[2] + 32, y - 3))
c.edge([(xs[3] + 30, y), (xs[4] - 13, y)])
c.edge([(xs[2], y + 8.5), (xs[2], y + 22), (xs[4], y + 22), (xs[4], y + 5)])
c.label((xs[2] + xs[4]) / 2, y + 22, "No: nothing to report", size=2.6)
c.hourglass(xs[0] - 28, y)

# 08:00 deadline sweep
y = 246
xs = hrow(y, "08:00  Deadline sweep", [
    ("p", 56, ["For each active", "document, check due_at"], None),
    ("d", 52, ["Due soon or", "overdue?"], None),
    ("p", 66, ["Write notification to holding", "office Admins; stamp document"], ["notifications, documents"]),
    ("e", 26, "End", None),
])
c.edge([(xs[0] + 22, y), (xs[1] - 28, y)])
c.edge([(xs[1] + 28, y), (xs[2] - 26, y)])
c.edge([(xs[2] + 26, y), (xs[3] - 33, y)], "Yes", (xs[2] + 32, y - 3))
c.edge([(xs[3] + 33, y), (xs[4] - 13, y)])
c.edge([(xs[2], y + 8.5), (xs[2], y + 22), (xs[4], y + 22), (xs[4], y + 5)])
c.label((xs[2] + xs[4]) / 2, y + 22, "No: on track, skip", size=2.6)
c.hourglass(xs[0] - 28, y)

c.note(332, 122, 68, 36, [
    "Deadline rule: due date =",
    "registration date + the type's",
    "turnaround (3 calendar days by",
    "default), at 6:00 PM. \"Due soon\"",
    "= within 2 days of due_at. One",
    "notification per document per",
    "day; the document is stamped",
    "so it is not repeated.",
], size=2.5)
c.note(332, 232, 68, 18, [
    "Every scheduler write is an",
    "in-app notification or a log",
    "row. No email is sent for",
    "document events.",
], size=2.5)

c.save(f"{OUT}_p2.svg")

# ============================================================ PAGE 3: legend + connectors + assumptions
c = Canvas(A3L)
c.title_block("System Flowchart", "Legend, connector key and assumptions.", page=3)
c.text(15, 34, "Legend", size=3.8, anchor="start", weight="bold")
ly = 46
items = [
    ("term", "Terminator: start or end of a flow."),
    ("proc", "Process: a step performed by the system or by a person."),
    ("dec", "Decision: a question; every exit is labelled Yes / No."),
    ("io", "Input / Output: data typed by a person or shown to them."),
    ("doc", "Document: a generated file (QR label, certificate PDF)."),
    ("conn", "Connector: the flow continues at the circle with the same letter."),
    ("cap", "Blue italic caption: database table(s) written by that step."),
    ("dash", "Dashed arrow: reverse or optional action (Restore)."),
    ("lane", "Swimlane: work done by the scheduler with no user involved."),
    ("hour", "Hourglass: a time-triggered event."),
]
for kind, desc in items:
    cx, cy = 40, ly
    if kind == "term":
        c.terminator(cx, cy, 30, 9, "Start")
    elif kind == "proc":
        c.process(cx, cy, 34, 9, ["Process"])
    elif kind == "dec":
        c.decision(cx, cy, 36, 12, ["Decision?"])
    elif kind == "io":
        c.io(cx, cy, 34, 9, ["Input / Output"])
    elif kind == "doc":
        c.document(cx, cy, 34, 11, ["Document"])
    elif kind == "conn":
        c.connector(cx, cy, "A")
    elif kind == "cap":
        c.process(cx, cy, 34, 12, ["Step"], ["table_name"])
    elif kind == "dash":
        c.edge([(cx - 15, cy), (cx + 15, cy)], dashed=True)
    elif kind == "lane":
        c.swimlane(cx - 17, cy - 6, 34, 12, "Lane", head=6)
    elif kind == "hour":
        c.hourglass(cx, cy)
    c.text(66, cy, desc, size=3.1, anchor="start")
    ly += 17

c.text(215, 34, "Connector key", size=3.8, anchor="start", weight="bold")
conns = [
    ("1", "Sign-in finished; continue with document registration."),
    ("A", "A document arrives at the office that now holds it (page 1, column 3)."),
    ("C", "Automatic completion after the last queued office received the document."),
    ("F", "The holder chose Forward (page 1, column 4)."),
    ("S", "The holder chose Sign (page 1, column 4)."),
    ("K", "The holder chose Complete (page 1, column 5)."),
    ("B", "Back to the holder's next-action decision."),
]
cy = 46
for k, d in conns:
    c.connector(222, cy, k)
    c.text(232, cy, d, size=3.1, anchor="start")
    cy += 12

c.text(215, 138, "Assumptions and notes", size=3.8, anchor="start", weight="bold")
notes = [
    "1. Approve, reject and return are not drawn. The client removed them on 2026-09-03 (\"received only\");",
    "    they survive only in older audit trails and in report counts.",
    "2. Receive and Forward can be done by the office Admin, or by the clerk who filed the document while it",
    "    is still at that office. Complete and Sign are Admin-only and are refused to the document's own",
    "    author unless the Super Admin has enabled self-approval.",
    "3. A stale-state check refuses any action when another user moved the document first.",
    "4. Status labels are the client-facing ones: Pending = initiated, In Process = under_review, Completed.",
    "5. Deadlines are calendar days. Every document type currently uses the 3-day default, due at 6:00 PM.",
    "6. Notifications are in-app only. Email is used for verification, password reset and support tickets.",
    "7. Simultaneous distribution creates one independent document per office; each has its own control",
    "    number, deadline and trail, linked by a shared submission group id.",
    "8. The scan log ignores a repeat scan by the same person or IP address within 60 seconds.",
]
cy = 148
for n in notes:
    c.text(215, cy, n, size=3.0, anchor="start")
    cy += 5.4

c.save(f"{OUT}_p3.svg")
print("d1 ok")
