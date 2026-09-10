from dg import *

OUT = "build/d5"
c = Canvas(A2L)
c.title_block("Data Flow Diagram (Level 1)", "Process 0 decomposed into ten processes.  Notation: Gane & Sarson.  Each band shows one process with its external entities (left) and the data stores it reads or writes (right); stores and entities are repeated per band.", page=1)

STORES = {
    "D1": "users", "D2": "offices", "D3": "document_types", "D4": "document_number_sequences", "D5": "documents",
    "D6": "document_movements", "D7": "document_files", "D8": "document_route_stops", "D9": "document_comments",
    "D10": "document_signatures", "D11": "document_scans", "D12": "notifications", "D13": "security_events",
    "D14": "app_settings", "D15": "backup_runs",
}
EW, EH = 44, 10
PW = 60
SW, SH = 58, 7.6
ENT_PITCH = 18
STORE_PITCH = 9.2
c.usage = {}


def band(ox, y0, num, name, entities, stores, extra_h=0):
    """entities: list of (label, in_lines, out_lines); stores: list of (id, mode, label).
    mode: r, w, rw. Returns (y_bottom, process centre)."""
    n_e, n_s = len(entities), len(stores)
    h = max(n_e * ENT_PITCH, n_s * STORE_PITCH, 22) + extra_h
    cy = y0 + h / 2
    ex = ox + 24
    px = ox + 130
    sx = ox + 226
    # process
    ph = max(20, min(h - 4, 20 + 6 * max(0, n_s - 3)))
    c.gs_process(px, cy, PW, ph, num, name, band=6)
    # entities
    ey = cy - (n_e - 1) * ENT_PITCH / 2
    for label, ins, outs in entities:
        lines = label if isinstance(label, list) else [label]
        c.gs_entity(ex, ey, EW, EH, lines, dup=True)
        x_from, x_to = ex + EW / 2, px - PW / 2
        if ins:
            c.edge([(x_from, ey - 1.6), (x_to, ey - 1.6)])
            c.text(x_from + 2, ey - 3.8 - (len(ins) - 1) * 1.5, ins, size=2.4, anchor="start", fill="#222")
        if outs:
            c.edge([(x_to, ey + 1.6), (x_from, ey + 1.6)])
            c.text(x_from + 2, ey + 3.8 + (len(outs) - 1) * 1.5, outs, size=2.4, anchor="start", fill="#222")
        ey += ENT_PITCH
    # stores
    sy = cy - (n_s - 1) * STORE_PITCH / 2
    for sid, mode, label in stores:
        name_s = STORES.get(sid, label if sid.startswith("all") else sid)
        if sid == "all":
            c.gs_store(sx, sy, SW, SH, "D1-15", "every table (dump)", size=2.7)
        else:
            c.gs_store(sx, sy, SW, SH, sid, name_s, size=2.6)
            c.usage.setdefault(sid, set()).add((num, mode))
        x_from, x_to = px + PW / 2, sx - SW / 2
        both = mode == "rw"
        if mode == "w" or both:
            c.edge([(x_from, sy), (x_to, sy)], both=both)
        else:
            c.edge([(x_to, sy), (x_from, sy)])
        c.text(x_from + 2, sy - 2.4, label, size=2.3, anchor="start", fill="#333")
        sy += STORE_PITCH
    return y0 + h, (px, cy, ph)


GAP = 11
# ================= LEFT HALF
ox = 15
y = 42
y, p1 = band(ox, y, "1.0", ["Authenticate &", "Manage Accounts"], [
    (["Staff (User, Admin,", "Super Admin)"], ["credentials, 2FA code / passkey,", "profile and security changes"], ["session, role-based redirect,", "dashboard"]),
    ("Super Admin", ["new account, role, password reset,", "activate / deactivate"], ["user list, temporary password"]),
    (["Mail Server", "(SMTP)"], ["delivery status"], ["verification email, reset email"]),
], [("D1", "rw", "account, password, 2FA"), ("D2", "r", "office membership"), ("D13", "w", "auth and account events")])
y += GAP
y, p2 = band(ox, y, "2.0", ["Register", "Document"], [
    ("User (Clerk)", ["registration details, attachment,", "destination offices, delivery mode"], ["control number, QR label"]),
    (["File Storage", "(disk / S3)"], [], ["document file (checksummed)"]),
], [("D2", "r", "active offices"), ("D3", "r", "type, turnaround days"), ("D4", "rw", "next control number"),
    ("D5", "w", "document, token, due, group"), ("D6", "w", "movement #1 'registered'"),
    ("D8", "w", "queued route stops"), ("D7", "w", "file record + SHA-256")])
y += GAP
y, p7 = band(ox, y, "7.0", ["Notify & Monitor", "Deadlines"], [
    ("Scheduler (cron)", ["08:00 sweep trigger"], ["sweep result"]),
    ("User / Admin", [], ["notification list, unread count"]),
], [("D5", "rw", "due_at, daily stamps"), ("D6", "r", "open leg -> office"),
    ("D1", "r", "Admins of that office"), ("D12", "w", "notification (1 per day)")])
y += GAP
y, p3 = band(ox, y, "3.0", ["Route & Process", "Document"], [
    (["Admin", "(holding office)"], ["action: receive / forward /", "complete / archive / restore;", "office(s), remarks, leg id"], ["confirmation, updated trail"]),
], [("D14", "r", "allow self-approval"), ("D2", "r", "active offices"), ("D5", "rw", "status, completed, archived"),
    ("D6", "rw", "close leg, open new leg"), ("D8", "rw", "visit / cancel / queue"), ("D9", "w", "remarks as comment")])
y += GAP
y, p5 = band(ox, y, "5.0", ["Track via", "QR Scan"], [
    ("Public Scanner", ["QR token"], ["public status page"]),
    ("User / Admin", ["QR token (scan console)"], ["redirect to full document page"]),
], [("D5", "r", "document by token"), ("D6", "r", "open leg -> office"), ("D11", "w", "scan: who, where, when")])

# process-to-process events
px, cy2, ph2 = p2
_, cy7, ph7 = p7
_, cy3, ph3 = p3
c.edge([(px, cy2 + ph2 / 2), (px, cy7 - ph7 / 2)])
c.label(px + 2, (cy2 + ph2 / 2 + cy7 - ph7 / 2) / 2, "'document registered' event", size=2.3, anchor="start")
c.edge([(px, cy3 - ph3 / 2), (px, cy7 + ph7 / 2)])
c.label(px + 2, (cy3 - ph3 / 2 + cy7 + ph7 / 2) / 2, "'document forwarded' event", size=2.3, anchor="start")

# ================= RIGHT HALF
ox = 302
y = 42
y, p4 = band(ox, y, "4.0", ["Sign & Verify", "Document"], [
    ("Admin", ["signature (drawn / typed)", "+ password confirmation"], ["certificate PDF"]),
    ("Public Scanner", ["signature serial"], ["verification result"]),
    ("Scheduler (cron)", ["02:30 verify trigger"], ["check result"]),
    (["File Storage", "(disk / S3)"], [], ["signature image"]),
], [("D7", "r", "current file checksum"), ("D6", "r", "open leg"), ("D1", "r", "signer name, position"),
    ("D10", "rw", "signature, serial, hash"), ("D13", "w", "signed / tampered")])
y += GAP
y, p6 = band(ox, y, "6.0", ["Manage Files", "& Comments"], [
    ("User / Admin", ["new file version, comment,", "download request"], ["file contents, comment thread"]),
    (["File Storage", "(disk / S3)"], ["file bytes"], ["new version"]),
], [("D7", "rw", "versions, checksums"), ("D9", "rw", "comments"), ("D13", "w", "file downloaded")])
y += GAP
y, p8 = band(ox, y, "8.0", ["Generate Reports", "& Dashboards"], [
    (["Admin /", "Super Admin"], ["report filters, export format"], ["dashboard statistics, monthly trend,", "PDF / XLSX / CSV export"]),
], [("D5", "r", "counts by status / due"), ("D6", "r", "turnaround, dwell time"),
    ("D2", "r", "office names"), ("D3", "r", "type names"), ("D1", "r", "actor names")])
y += GAP
y, p9 = band(ox, y, "9.0", ["Administer", "System"], [
    ("Super Admin", ["workflow setting, backup request,", "restore record, security log request"], ["settings form, backup history,", "security event log"]),
    ("Scheduler (cron)", ["01:00 backup trigger"], ["backup result"]),
    (["File Storage", "(disk / S3)"], ["document files (for the dump)"], []),
    (["Backup Storage", "(disk / S3)"], ["stored listing, size"], ["backup archive (db dump + files)"]),
], [("D14", "rw", "allow self-approval"), ("all", "r", "database dump"), ("D15", "w", "run status, size, path"),
    ("D13", "w", "backup events")])
y += GAP
y, p10 = band(ox, y, "10.0", ["Help &", "Support"], [
    (["Public Scanner", "/ User"], ["article request; ticket (subject,", "message, screenshot)"], ["articles, contact details,", "ticket confirmation"]),
    (["Mail Server", "(SMTP)"], [], ["support ticket email"]),
    (["File Storage", "(disk / S3)"], [], ["screenshot"]),
], [("D1", "r", "reporter identity")])
c.note(302 + 100, y - 8, 120, 8, ["Help articles come from a static knowledge base inside the application, not from a database table."], size=2.3)

# divider and key
c.line(297, 40, 297, 400, "#BBBBBB", 0.3, dash="2,2")
c.text(15, 405, "Key:  entity -> process arrow (upper) carries input, process -> entity arrow (lower) carries output;  store arrows: -> write, <- read, <-> read and write;  "
                "a slash in an entity's corner marks a repeated entity;  D5 / D6 / D13 appear in several bands on purpose.", size=2.7, anchor="start", fill="#333")
c.save(f"{OUT}_p1.svg")

# ================= page 2: balancing table
bal = [
    ("User -> System", "credentials, 2FA code / passkey", "1.0"),
    ("User -> System", "registration details + attachment, offices, delivery mode", "2.0"),
    ("User -> System", "new file version; comment; download request", "6.0"),
    ("User -> System", "search / filter criteria", "8.0 (dashboards and lists)"),
    ("User -> System", "QR token (scan console)", "5.0"),
    ("User -> System", "support ticket (+ screenshot)", "10.0"),
    ("User -> System", "profile and security changes", "1.0"),
    ("System -> User", "dashboard; document list, details, trail", "8.0 / 3.0"),
    ("System -> User", "control number and QR label", "2.0"),
    ("System -> User", "file download; comment thread", "6.0"),
    ("System -> User", "in-app notifications", "7.0"),
    ("System -> User", "help articles; ticket confirmation", "10.0"),
    ("Admin -> System", "workflow action, office(s), remarks, expected leg id", "3.0"),
    ("Admin -> System", "signature + password confirmation", "4.0"),
    ("Admin -> System", "report filters, export format", "8.0"),
    ("Admin -> System", "office settings", "9.0"),
    ("System -> Admin", "office queue; action confirmation; updated trail", "3.0 / 8.0"),
    ("System -> Admin", "certificate PDF", "4.0"),
    ("System -> Admin", "report export (PDF / XLSX / CSV)", "8.0"),
    ("System -> Admin", "deadline notifications", "7.0"),
    ("Super Admin -> System", "new account, role, password reset, activate / deactivate", "1.0"),
    ("Super Admin -> System", "workflow settings; backup request; restore record; security log request", "9.0"),
    ("Super Admin -> System", "signature verification request", "4.0"),
    ("System -> Super Admin", "system-wide dashboard and reports", "8.0"),
    ("System -> Super Admin", "user list; temporary password", "1.0"),
    ("System -> Super Admin", "backup history; security event log; settings form", "9.0"),
    ("System -> Super Admin", "signature verification result", "4.0"),
    ("Public Scanner -> System", "QR token", "5.0"),
    ("Public Scanner -> System", "signature serial", "4.0"),
    ("System -> Public Scanner", "public document status", "5.0"),
    ("System -> Public Scanner", "verification result", "4.0"),
    ("System -> Public Scanner", "help / FAQ pages; privacy notice", "10.0"),
    ("Scheduler -> System", "08:00 deadline sweep trigger", "7.0"),
    ("Scheduler -> System", "02:30 verify signatures trigger", "4.0"),
    ("Scheduler -> System", "01:00 backup trigger", "9.0"),
    ("System -> Scheduler", "job result / log line", "4.0 / 7.0 / 9.0"),
    ("System -> Mail Server", "verification and reset emails", "1.0"),
    ("System -> Mail Server", "support ticket email", "10.0"),
    ("Mail Server -> System", "delivery status", "1.0"),
    ("System -> File Storage", "document file; signature image; screenshot", "2.0 / 4.0 / 6.0 / 10.0"),
    ("File Storage -> System", "file contents", "6.0 / 9.0"),
    ("System -> Backup Storage", "backup archive", "9.0"),
    ("Backup Storage -> System", "stored listing and size", "9.0"),
]
rows = [[str(i + 1), a, b, p] for i, (a, b, p) in enumerate(bal)]
pages = table_pages("Data Flow Diagram (Level 1)", "Balancing check: every Level 0 flow and the Level 1 process that carries it.",
                    ["#", "Level 0 flow", "Data", "Level 1 process"], rows, [10, 60, 300, 80], size=A2L, start_page=2)

# ================= data dictionary
dd = [
    ("D1", "users", "id, name, email, password, role (user / admin / super_admin), office_id, position, is_active, two_factor, preferences", "1.0, 4.0, 7.0, 8.0, 10.0", "1.0"),
    ("D2", "offices", "id, code, name, type (office / department / division), parent_id, head_user_id, is_active", "1.0, 2.0, 3.0, 8.0", "seeded"),
    ("D3", "document_types", "id, name, turnaround_days, is_active", "2.0, 8.0", "seeded"),
    ("D4", "document_number_sequences", "office_id, year, last_number", "2.0", "2.0"),
    ("D5", "documents", "id, control_number, qr_token, title, description, remarks, document_type_id, originating_office_id, created_by_id, submission_group_id, status, priority, due_at, completed_at, archived_at, deadline_warned_at, overdue_notified_at", "3.0, 5.0, 7.0, 8.0", "2.0, 3.0, 7.0"),
    ("D6", "document_movements", "id, document_id, sequence, from_office_id, to_office_id, actor_id, action, from_status, to_status, remarks, arrived_at, departed_at, due_at, is_open, ip_address, user_agent", "4.0, 5.0, 7.0, 8.0", "2.0, 3.0"),
    ("D7", "document_files", "id, document_id, version, path, original_name, mime, size, checksum_sha256, uploaded_by_id, movement_id", "4.0, 6.0", "2.0, 6.0"),
    ("D8", "document_route_stops", "id, document_id, position, office_id, status (pending / visited / cancelled), resolved_at, created_by_id", "3.0", "2.0, 3.0"),
    ("D9", "document_comments", "id, document_id, document_movement_id, user_id, context, body, is_internal", "6.0", "3.0, 6.0"),
    ("D10", "document_signatures", "id, serial, document_id, document_movement_id, document_file_id, user_id, signer_name, signer_position, signer_office, method (drawn / typed), purpose, image_path, document_hash, signature_hash, signed_at", "4.0", "4.0"),
    ("D11", "document_scans", "id, document_id, user_id, office_id, source (camera / wedge / manual), ip_address, user_agent, scanned_at", "-", "5.0"),
    ("D12", "notifications", "id, user_id, document_id, document_movement_id, type, dedupe_key, title, body, control_number, read_at", "7.0 (read by users)", "7.0"),
    ("D13", "security_events", "id, type, user_id, subject_id, ip_address, user_agent, context, created_at", "9.0", "1.0, 4.0, 6.0, 9.0"),
    ("D14", "app_settings", "setting_key, setting_value, group_name", "3.0, 9.0", "9.0"),
    ("D15", "backup_runs", "id, status (running / completed / failed), driver, disk, path, size, started_at, finished_at, error, triggered_by_id, restored_at, restore_notes", "9.0", "9.0"),
]
rows = [[a, b, k, r, w] for (a, b, k, r, w) in dd]
pages += table_pages("Data Flow Diagram (Level 1)", "Data dictionary: the fifteen data stores.",
                     ["ID", "Table", "Key fields", "Read by", "Written by"], rows, [12, 50, 330, 50, 50], size=A2L, start_page=len(pages) + 2, mono_cols=(1,))
for i, pg in enumerate(pages):
    pg.save(f"{OUT}_p{i + 2}.svg")
print("d5 ok")
