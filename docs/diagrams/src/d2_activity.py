from dg import *

OUT = "build/d2"
SUB = "Activity: process an official document.  Notation: UML 2.5 activity diagram with swimlanes (activity partitions)."


def guard(c, x, y, text, anchor="start"):
    c.label(x, y, text, size=2.6, anchor=anchor, fill="#333")


# ============================================================ PAGE 1: register
c = Canvas(A3L)
c.title_block("Activity Diagram", SUB + "  Part 1 of 3: sign in and register.", page=1)
top, bot = 30, 276
c.swimlane(15, top, 90, bot - top, "User (Clerk)")
c.swimlane(105, top, 210, bot - top, "System (CICTO DTS)")
c.swimlane(315, top, 90, bot - top, "Super Admin")

U, S, SA = 60, 210, 360
UW = 56  # user action width

# Super Admin creates the account
c.action(SA, 48, 74, 12, ["Create account; reset", "password; assign role"], ["users, security_events"])
c.edge([(SA - 37, 48), (96, 48), (96, 56), (U + UW / 2, 56)], dashed=True)
c.label(200, 44.5, "account exists (precondition)", size=2.5, fill="#333")

# User: log in
c.initial(U, 46)
c.action(U, 58, UW, 10, ["Log in (email, password)"])
c.edge([(U, 48.4), (U, 53)])
c.action(S, 72, 76, 10, ["Verify credentials; 2FA or passkey"], None)
c.edge([(U + UW / 2, 60), (S, 60), (S, 67)])
c.diamond(S, 86)
c.edge([(S, 77), (S, 82.5)])
# invalid -> back to log in (from below)
c.edge([(S - 3.5, 86), (U, 86), (U, 63)])
guard(c, S - 8, 83, "[invalid credentials or locked out]", anchor="end")
# valid -> fill form
c.edge([(S, 89.5), (S, 96), (U, 96), (U, 101)])
guard(c, S + 2, 93, "[valid]")

c.action(U, 106, UW, 12, ["Fill registration form;", "attach file (optional)"])
c.action(U, 122, UW, 12, ["Choose office(s) and", "delivery mode; submit"])
c.edge([(U, 112), (U, 116)])
c.action(S, 122, 60, 10, ["Validate input"])
c.edge([(U + UW / 2, 122), (S - 30, 122)])
c.diamond(S, 138)
c.edge([(S, 127), (S, 134.5)])
# invalid -> back to fill form (right edge)
c.edge([(S - 3.5, 138), (96, 138), (96, 106), (U + UW / 2, 106)])
guard(c, S - 6, 141.5, "[invalid: missing field, bad file type, size > 10 MB]", anchor="end")
guard(c, S + 2, 145, "[valid]")
c.edge([(S, 141.5), (S, 150.5)])
c.diamond(S, 154)
guard(c, S - 6, 151, "[all offices at once]", anchor="end")
guard(c, S + 6, 151, "[one after another]")
L, R = 160, 260
c.action(L, 172, 70, 14, ["Create one document per", "office (shared submission", "group id)"])
c.action(R, 172, 70, 14, ["Create one document;", "queue the other offices", "as route stops"])
c.edge([(S - 3.5, 154), (L, 154), (L, 165)])
c.edge([(S + 3.5, 154), (R, 154), (R, 165)])
c.diamond(S, 188)
c.edge([(L, 179), (L, 188), (S - 3.5, 188)])
c.edge([(R, 179), (R, 188), (S + 3.5, 188)])
c.action(S, 202, 84, 14, ["Allocate control number", "OFFICE-YEAR-00001;", "generate QR token; set due date"], ["document_number_sequences, documents"])
c.edge([(S, 191.5), (S, 195)])
c.action(S, 220, 84, 12, ["Write movement #1 'registered';", "queue route stops"], ["document_movements, document_route_stops"])
c.edge([(S, 209), (S, 214)])
c.action(S, 235, 84, 10, ["Store file with SHA-256 checksum"], ["document_files"])
c.edge([(S, 226), (S, 230)])
c.bar(S, 243, 130)
c.edge([(S, 240), (S, 242)])
c.action(L, 252, 76, 12, ["Write notifications for the", "receiving office's Admins"], ["notifications"])
c.action(R, 252, 60, 10, ["Render QR label"])
c.edge([(L, 243.9), (L, 246)])
c.edge([(R, 243.9), (R, 247)])
c.bar(S, 262, 130)
c.edge([(L, 258), (L, 261)])
c.edge([(R, 257), (R, 261)])
c.objnode(S, 271, 60, 9, "Document [Pending]")
c.edge([(S, 263), (S, 266.5)])
c.connector(302, 271, "A")
c.edge([(S + 30, 271), (297.6, 271)])
c.label(302, 264, "page 2", size=2.4, bg=False)

# notes in the User lane
c.note(20, 140, 80, 22, ["Attachment: pdf, doc, docx, xls,", "xlsx, png or jpg, at most 10 MB.", "Destination offices: any active", "office or department (up to 20)."], size=2.5)
c.note(20, 168, 80, 22, ["Delivery mode: 'one after another'", "sends to the first office and queues", "the rest; 'all at once' creates one", "independent document per office."], size=2.5)
# notes in Super Admin lane
c.note(320, 70, 80, 18, ["There is no self-registration.", "Every account is created by the", "Super Admin, who also assigns the", "role and the office."], size=2.5)
c.note(320, 200, 80, 14, ["Due date = registration day +", "type turnaround (3 days default)", "at 6:00 PM, calendar days."], size=2.5)

c.save(f"{OUT}_p1.svg")

# ============================================================ PAGE 2: route / sign / complete / archive
c = Canvas(A3L)
c.title_block("Activity Diagram", SUB + "  Part 2 of 3: route, sign, complete, archive.", page=2)
c.swimlane(15, top, 110, bot - top, "Admin (Holding Office)")
c.swimlane(125, top, 190, bot - top, "System (CICTO DTS)")
c.swimlane(315, top, 90, bot - top, "Super Admin")
AD, SL, SC, SR, SA = 62, 178, 220, 274, 360
AW = 60

# --- arrive and receive
c.connector(AD, 47, "A")
c.label(AD + 6, 47, "document arrives (page 1 / next office)", size=2.4, anchor="start", bg=False)
c.action(AD, 60, AW, 10, ["Open document"])
c.edge([(AD, 51.4), (AD, 55)])
c.action(AD, 75, AW, 10, ["Receive"])
c.edge([(AD, 65), (AD, 70)])
c.action(SC, 75, 86, 12, ["Close open leg; open a", "'received' leg for this office"], ["document_movements"])
c.edge([(AD + AW / 2, 75), (SC - 43, 75)])
c.objnode(SC, 92, 66, 9, "Document [In Process]")
c.edge([(SC, 81), (SC, 87.5)])
c.diamond(SC, 106)
c.edge([(SC, 96.5), (SC, 102.5)])
# [queued stop exists] -> auto-forward -> connector A
c.action(SR, 120, 70, 14, ["Auto-forward to the next", "queued office; notify", "its Admins"], ["document_movements, notifications"])
c.edge([(SC + 3.5, 106), (SR, 106), (SR, 113)])
guard(c, SC + 6, 102.5, "[queued stop exists]")
c.connector(SR, 140, "A")
c.label(SR + 6, 140, "next office opens it", size=2.4, anchor="start", bg=False)
c.edge([(SR, 127), (SR, 135.6)])
# [no route] -> admin decides
c.edge([(SC - 3.5, 106), (AD, 106), (AD, 134.5)])
guard(c, SC - 6, 102.5, "[no route]", anchor="end")
# [route finished] -> completed leg
c.edge([(SC, 109.5), (SC, 132)])
guard(c, SC - 2.5, 116, "[no stop left and", anchor="end")
guard(c, SC - 2.5, 120, "the route existed]", anchor="end")

# --- admin decision
c.diamond(AD, 138)
c.label(AD - 5, 131, "next action?", size=2.5, anchor="end", bg=False, weight="bold")
# [complete] -> Mark complete (right)
c.action(98, 138, 44, 10, ["Mark complete"])
c.edge([(AD + 3.5, 138), (76, 138)])
guard(c, 76, 144.5, "[complete]")
c.diamond(SL, 138)
c.edge([(120, 138), (SL - 3.5, 138)])
c.action(SC, 138, 70, 12, ["Create 'completed' leg;", "stamp completed_at"], ["documents, document_movements"])
c.edge([(SL + 3.5, 138), (SC - 35, 138)])
guard(c, SL + 5, 133.5, "[none pending]")
c.flow_final(SL, 154)
c.edge([(SL, 141.5), (SL, 151)])
guard(c, SL - 2.5, 146, "[route stops still pending: refused,", anchor="end")
guard(c, SL - 2.5, 150, "finish the route first]", anchor="end")
c.objnode(SC, 156, 66, 9, "Document [Completed]")
c.edge([(SC, 144), (SC, 151.5)])
# [sign] -> down
c.edge([(AD, 141.5), (AD, 151)])
guard(c, AD + 2, 147.5, "[sign]")
c.action(AD, 156, AW, 10, ["Confirm password"])
c.action(AD, 172, AW, 10, ["Draw or type signature"])
c.edge([(AD, 161), (AD, 167)])
c.action(SL, 172, 76, 16, ["Compute SHA-256 hash; issue", "serial; save signature;", "log security event"], ["document_signatures, security_events"])
c.edge([(AD + AW / 2, 172), (SL - 38, 172)])
c.objnode(SL, 190, 50, 9, "Certificate PDF")
c.edge([(SL, 180), (SL, 185.5)])
c.flow_final(136, 190)
c.edge([(SL - 25, 190), (139, 190)])
c.label(136, 197, ["holder may", "act again"], size=2.3, bg=False, fill="#444")
c.label(SL - 6, 160, "holder acts again", size=2.3, bg=False, fill="#444")
# [forward] -> left path
c.edge([(AD - 3.5, 138), (28, 138), (28, 208), (AD - AW / 2, 208)])
guard(c, 30, 145, "[forward]")
c.action(AD, 208, AW, 10, ["Select office(s)"])
c.action(SL, 208, 76, 16, ["Cancel pending stops; create", "'forwarded' leg to the first office;", "queue the rest; notify"], ["document_movements, document_route_stops,", "notifications"])
c.edge([(AD + AW / 2, 208), (SL - 38, 208)])
c.connector(SL, 228, "A")
c.label(SL + 6, 228, "next office opens it", size=2.4, anchor="start", bg=False)
c.edge([(SL, 216), (SL, 223.6)])

# --- archive
c.diamond(AD, 246)
c.edge([(SC, 160.5), (SC, 246), (AD + 3.5, 246)])
guard(c, AD + 6, 251.5, "[archive]")
guard(c, AD - 6, 251.5, "[keep]", anchor="end")
c.final(30, 246)
c.edge([(AD - 3.5, 246), (33, 246)])
c.action(SL, 262, 74, 10, ["Stamp archived_at"], ["documents, document_movements"])
c.edge([(AD, 249.5), (AD, 262), (SL - 37, 262)])
c.objnode(262, 262, 64, 9, "Document [Archived]")
c.edge([(SL + 37, 262), (230, 262)])
c.final(305, 262)
c.edge([(294, 262), (302, 262)])
c.edge([(262, 257.5), (262, 156), (SC + 33, 156)], dashed=True)
guard(c, 264, 200, "[restore, Admin]")

# --- Super Admin lane
c.action(SA, 130, 78, 12, ["Enable or disable", "self-approval"], ["app_settings"])
c.objnode(SA, 150, 70, 9, "Setting [allow self-approval]")
c.edge([(SA, 136), (SA, 145.5)])
c.edge([(SA - 35, 150), (SL + 38, 172)], dashed=True)
c.note(320, 50, 80, 22, ["Auto-forward marks the stop", "'visited' and creates the next", "'forwarded' leg without any", "human action."], size=2.5)
c.note(320, 165, 80, 30, ["Complete and Sign are Admin-only", "and are refused to the document's", "own author unless this setting", "is on. Receive and Forward are", "open to any user of the holding", "office who can see the document."], size=2.5)
c.note(320, 200, 80, 22, ["Stale-state check: every action", "carries the id of the leg it saw;", "if another user moved the", "document first, it is refused."], size=2.5)
c.note(320, 226, 80, 22, ["Approve, reject and return were", "removed on 2026-09-03 at the", "client's request. Only 'received'", "advances a queued route."], size=2.5)

c.save(f"{OUT}_p2.svg")

# ============================================================ PAGE 3: scheduler + legend
c = Canvas(A3L)
c.title_block("Activity Diagram", SUB + "  Part 3 of 3: scheduler activities and legend.", page=3)
top3, bot3 = 30, 212
c.swimlane(15, top3, 90, bot3 - top3, "Scheduler (cron)")
c.swimlane(105, top3, 300, bot3 - top3, "System (CICTO DTS)")

# 08:00 sweep
y = 62
c.hourglass(28, y)
c.text(28, y + 7.5, "08:00 daily", size=2.5)
c.action(68, y, 60, 10, ["Run deadline sweep"])
c.edge([(31.5, y), (38, y)])
c.action(150, y, 68, 12, ["For each active document,", "compare now with due_at"], ["documents"])
c.edge([(98, y), (116, y)])
c.diamond(197, y)
c.edge([(184, y), (193.5, y)])
c.action(255, y - 16, 66, 10, ["Write 'pending' notification"], ["notifications"])
c.action(255, y, 66, 10, ["Write 'overdue' notification"], ["notifications"])
c.edge([(197, y - 3.5), (197, y - 16), (222, y - 16)])
c.edge([(200.5, y), (222, y)])
c.edge([(197, y + 3.5), (197, y + 16), (300, y + 16), (300, y + 3.5)])
guard(c, 200, y - 12, "[due within 2 days]")
guard(c, 203, y - 3, "[past due_at]")
guard(c, 200, y + 12.5, "[on track: skip]")
c.diamond(300, y)
c.edge([(288, y - 16), (300, y - 16), (300, y - 3.5)])
c.edge([(288, y), (296.5, y)])
c.action(340, y, 52, 12, ["Stamp document", "(once per day)"], ["documents"])
c.edge([(303.5, y), (314, y)])
c.final(380, y)
c.edge([(366, y), (377, y)])
c.note(112, y + 22, 200, 12, ["Only Admins of the office that currently holds the document are notified. The stamp (deadline_warned_at /",
                             "overdue_notified_at) makes the sweep write at most one notification per document per day."], size=2.5)

# 02:30 verify
y = 120
c.hourglass(28, y)
c.text(28, y + 7.5, "02:30 daily", size=2.5)
c.action(68, y, 60, 10, ["Run signature check"])
c.edge([(31.5, y), (38, y)])
c.action(150, y, 68, 12, ["Recompute the hash of", "every stored signature"], ["document_signatures, document_files"])
c.edge([(98, y), (116, y)])
c.diamond(197, y)
c.edge([(184, y), (193.5, y)])
c.action(255, y, 66, 10, ["Log 'signature tampered'"], ["security_events"])
c.edge([(200.5, y), (222, y)])
guard(c, 203, y - 3, "[hash mismatch]")
c.final(300, y)
c.edge([(288, y), (297, y)])
c.flow_final(197, y + 18)
c.edge([(197, y + 3.5), (197, y + 15)])
guard(c, 200, y + 10, "[all match: quiet]")

# 01:00 backup
y = 172
c.hourglass(28, y)
c.text(28, y + 7.5, "01:00 daily", size=2.5)
c.action(68, y, 60, 10, ["Run backup"])
c.edge([(31.5, y), (38, y)])
c.action(150, y, 68, 12, ["Dump database and", "document files"])
c.edge([(98, y), (116, y)])
c.action(235, y, 74, 12, ["Store archive; record", "backup run; log event"], ["backup_runs, security_events"])
c.edge([(184, y), (198, y)])
c.action(315, y, 60, 12, ["Prune old runs", "(keep 14)"], ["backup_runs"])
c.edge([(272, y), (285, y)])
c.final(365, y)
c.edge([(345, y), (362, y)])

# legend
c.text(15, 224, "Legend", size=3.8, anchor="start", weight="bold")
ly = 236
c.initial(24, ly); c.text(32, ly, "Initial node: the activity starts here.", size=3.0, anchor="start")
c.final(24, ly + 12); c.text(32, ly + 12, "Activity final: the whole activity ends.", size=3.0, anchor="start")
c.flow_final(24, ly + 24); c.text(32, ly + 24, "Flow final: this path ends, others continue.", size=3.0, anchor="start")
c.action(112, ly, 30, 8, ["Action"]); c.text(132, ly, "Action performed in that lane.", size=3.0, anchor="start")
c.diamond(112, ly + 12); c.text(132, ly + 12, "Decision (guards in [brackets]) or merge.", size=3.0, anchor="start")
c.bar(112, ly + 24, 24); c.text(132, ly + 24, "Fork / join: parallel actions.", size=3.0, anchor="start")
c.objnode(237, ly, 40, 8, "Document [Pending]"); c.text(262, ly, "Object node with its state.", size=3.0, anchor="start")
c.hourglass(237, ly + 12); c.text(262, ly + 12, "Time event that starts a flow.", size=3.0, anchor="start")
c.edge([(225, ly + 24), (249, ly + 24)], dashed=True); c.text(262, ly + 24, "Dashed: optional or reverse path, or a setting that constrains an action.", size=3.0, anchor="start")
c.connector(237, ly + 36, "A"); c.text(262, ly + 36, "Connector: the flow continues on the page named beside it.", size=3.0, anchor="start")
c.process(112, ly + 36, 30, 10, ["Action"], ["table"]); c.text(132, ly + 36, "Blue italic caption: database table(s) written.", size=3.0, anchor="start")
c.note(15, ly + 33, 26, 8, ["Note"], size=2.6, anchor="middle"); c.text(46, ly + 37, "Note: constraint or explanation.", size=3.0, anchor="start")
c.save(f"{OUT}_p3.svg")
print("d2 ok")
