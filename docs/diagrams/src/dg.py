"""Tiny SVG diagram helper. All units are millimetres."""
import html, math, datetime

FONT = "Helvetica, Arial, sans-serif"
MONO = "Menlo, Courier, monospace"
A3L = (420, 297)
A3P = (297, 420)
A2L = (594, 420)
TODAY = datetime.date.today().strftime("%d %B %Y")

C = dict(
    ink="#1c1c1c", line="#333333",
    proc_f="#F3F7FC", proc_s="#2B4C7E",
    term_f="#D9E8F5", term_s="#1F3A5F",
    dec_f="#FFF3D1", dec_s="#A66A00",
    io_f="#E6F4EA", io_s="#2E7D32",
    doc_f="#FBE9E7", doc_s="#B23C17",
    store_f="#FFF8E1", store_s="#8A6D00",
    ent_f="#EDEDED", ent_s="#444444",
    note_f="#FFFDE7", note_s="#9E9E9E",
    conn_f="#FFFFFF", conn_s="#1c1c1c",
    lane_f="#F5F7FA", lane_h="#DCE4EE",
    uc_f="#FFFFFF", uc_s="#2B2B2B",
    obj_f="#EEF6FF", obj_s="#2B4C7E",
    cap="#1F4E9C",
)


def esc(s):
    return html.escape(str(s), quote=True)


def tw(s, size):
    """Rough Helvetica text width in mm."""
    return len(s) * size * 0.52


class Canvas:
    def __init__(self, size=A3L):
        self.w, self.h = size
        self.items = []

    def add(self, s):
        self.items.append(s)

    # ---------- text ----------
    def text(self, x, y, lines, size=3.2, anchor="middle", weight="normal",
             fill=None, family=FONT, italic=False, lh=1.28):
        if isinstance(lines, str):
            lines = [lines]
        fill = fill or C["ink"]
        n = len(lines)
        step = size * lh
        y0 = y - (n - 1) * step / 2
        for i, l in enumerate(lines):
            by = y0 + i * step + size * 0.36
            st = ' font-style="italic"' if italic else ''
            self.add(f'<text x="{x:.2f}" y="{by:.2f}" font-size="{size}" text-anchor="{anchor}" '
                     f'font-weight="{weight}" fill="{fill}" font-family="{family}"{st}>{esc(l)}</text>')

    def block_text(self, x, y, lines, caption=None, size=3.2, cap_size=2.6, weight="normal", fill=None):
        """Main lines centred at (x,y); optional blue italic caption lines below."""
        if isinstance(lines, str):
            lines = [lines]
        if isinstance(caption, str):
            caption = [caption]
        caption = caption or []
        main_h = (len(lines) - 1) * size * 1.28
        cap_h = len(caption) * cap_size * 1.25
        total = main_h + (cap_h + size * 0.5 if caption else 0)
        top = y - total / 2
        self.text(x, top + main_h / 2, lines, size=size, weight=weight, fill=fill)
        if caption:
            cy = top + main_h + size * 0.5 + cap_h / 2 - cap_size * 0.1
            self.text(x, cy, caption, size=cap_size, fill=C["cap"], italic=True)

    # ---------- primitives ----------
    def rect(self, x, y, w, h, fill="#fff", stroke=None, sw=0.35, rx=0, dash=None):
        stroke = stroke or C["line"]
        d = f' stroke-dasharray="{dash}"' if dash else ''
        self.add(f'<rect x="{x:.2f}" y="{y:.2f}" width="{w:.2f}" height="{h:.2f}" rx="{rx}" '
                 f'fill="{fill}" stroke="{stroke}" stroke-width="{sw}"{d}/>')

    def poly(self, pts, fill="#fff", stroke=None, sw=0.35, close=True, dash=None):
        stroke = stroke or C["line"]
        d = " ".join(f"{x:.2f},{y:.2f}" for x, y in pts)
        tag = "polygon" if close else "polyline"
        da = f' stroke-dasharray="{dash}"' if dash else ''
        self.add(f'<{tag} points="{d}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}" stroke-linejoin="round"{da}/>')

    def line(self, x1, y1, x2, y2, stroke=None, sw=0.35, dash=None):
        stroke = stroke or C["line"]
        d = f' stroke-dasharray="{dash}"' if dash else ''
        self.add(f'<line x1="{x1:.2f}" y1="{y1:.2f}" x2="{x2:.2f}" y2="{y2:.2f}" stroke="{stroke}" stroke-width="{sw}"{d}/>')

    def circle(self, cx, cy, r, fill="#fff", stroke=None, sw=0.35):
        stroke = stroke or C["line"]
        self.add(f'<circle cx="{cx:.2f}" cy="{cy:.2f}" r="{r}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}"/>')

    def ellipse(self, cx, cy, rx, ry, fill="#fff", stroke=None, sw=0.35):
        stroke = stroke or C["line"]
        self.add(f'<ellipse cx="{cx:.2f}" cy="{cy:.2f}" rx="{rx}" ry="{ry}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}"/>')

    def path(self, d, fill="none", stroke=None, sw=0.35, dash=None, marker=None):
        stroke = stroke or C["line"]
        da = f' stroke-dasharray="{dash}"' if dash else ''
        mk = f' marker-end="url(#{marker})"' if marker else ''
        self.add(f'<path d="{d}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}" stroke-linejoin="round"{da}{mk}/>')

    # ---------- flowchart nodes ----------
    def process(self, cx, cy, w, h, lines, caption=None, fill=None, stroke=None, bold=False):
        self.rect(cx - w / 2, cy - h / 2, w, h, fill or C["proc_f"], stroke or C["proc_s"], 0.4)
        self.block_text(cx, cy, lines, caption, weight="bold" if bold else "normal")

    def terminator(self, cx, cy, w, h, lines):
        self.rect(cx - w / 2, cy - h / 2, w, h, C["term_f"], C["term_s"], 0.4, rx=h / 2)
        self.block_text(cx, cy, lines, weight="bold")

    def decision(self, cx, cy, w, h, lines, caption=None):
        self.poly([(cx, cy - h / 2), (cx + w / 2, cy), (cx, cy + h / 2), (cx - w / 2, cy)],
                  C["dec_f"], C["dec_s"], 0.4)
        self.block_text(cx, cy, lines, caption, size=3.0)

    def io(self, cx, cy, w, h, lines, caption=None):
        s = h * 0.35
        self.poly([(cx - w / 2 + s, cy - h / 2), (cx + w / 2, cy - h / 2),
                   (cx + w / 2 - s, cy + h / 2), (cx - w / 2, cy + h / 2)], C["io_f"], C["io_s"], 0.4)
        self.block_text(cx, cy, lines, caption)

    def document(self, cx, cy, w, h, lines, caption=None):
        x0, y0 = cx - w / 2, cy - h / 2
        x1, y1 = cx + w / 2, cy + h / 2
        wave = h * 0.18
        d = (f"M{x0:.2f},{y0:.2f} L{x1:.2f},{y0:.2f} L{x1:.2f},{y1 - wave:.2f} "
             f"Q{cx + w / 4:.2f},{y1 - wave * 2.6:.2f} {cx:.2f},{y1 - wave:.2f} "
             f"Q{cx - w / 4:.2f},{y1 + wave * 0.8:.2f} {x0:.2f},{y1 - wave:.2f} Z")
        self.path(d, C["doc_f"], C["doc_s"], 0.4)
        self.block_text(cx, cy - wave * 0.4, lines, caption)

    def cylinder(self, cx, cy, w, h, lines, size=2.8):
        ry = h * 0.14
        x0, y0 = cx - w / 2, cy - h / 2
        self.rect(x0, y0 + ry, w, h - 2 * ry, C["store_f"], "none", 0)
        self.line(x0, y0 + ry, x0, cy + h / 2 - ry, C["store_s"], 0.4)
        self.line(cx + w / 2, y0 + ry, cx + w / 2, cy + h / 2 - ry, C["store_s"], 0.4)
        self.path(f"M{x0:.2f},{cy + h / 2 - ry:.2f} A{w / 2:.2f},{ry:.2f} 0 0 0 {cx + w / 2:.2f},{cy + h / 2 - ry:.2f}",
                  C["store_f"], C["store_s"], 0.4)
        self.ellipse(cx, y0 + ry, w / 2, ry, C["store_f"], C["store_s"], 0.4)
        self.text(cx, cy + ry * 0.5, lines, size=size)

    def connector(self, cx, cy, label, caption=None, r=4.4):
        self.circle(cx, cy, r, C["conn_f"], C["conn_s"], 0.5)
        self.text(cx, cy, label, size=3.4, weight="bold")
        if caption:
            self.text(cx, cy + r + 2.4, caption, size=2.5, fill="#444")

    def note(self, x, y, w, h, lines, size=2.7, anchor="start"):
        fold = 3
        self.poly([(x, y), (x + w - fold, y), (x + w, y + fold), (x + w, y + h), (x, y + h)],
                  C["note_f"], C["note_s"], 0.3)
        self.poly([(x + w - fold, y), (x + w - fold, y + fold), (x + w, y + fold)], C["note_f"], C["note_s"], 0.3)
        tx = x + 2 if anchor == "start" else x + w / 2
        self.text(tx, y + h / 2, lines, size=size, anchor=anchor, fill="#333")

    # ---------- DFD (Gane & Sarson) ----------
    def gs_process(self, cx, cy, w, h, num, lines, band=6):
        x0, y0 = cx - w / 2, cy - h / 2
        self.rect(x0, y0, w, h, C["proc_f"], C["proc_s"], 0.45, rx=3)
        self.line(x0, y0 + band, x0 + w, y0 + band, C["proc_s"], 0.45)
        self.text(x0 + 3, y0 + band / 2, num, size=3.0, anchor="start", weight="bold")
        self.text(cx, y0 + band + (h - band) / 2, lines, size=3.2, weight="bold")

    def gs_store(self, cx, cy, w, h, sid, name, size=3.0):
        x0, y0 = cx - w / 2, cy - h / 2
        idw = 10
        self.rect(x0, y0, w, h, C["store_f"], "none", 0)
        self.line(x0, y0, x0 + w, y0, C["store_s"], 0.45)
        self.line(x0, y0 + h, x0 + w, y0 + h, C["store_s"], 0.45)
        self.line(x0, y0, x0, y0 + h, C["store_s"], 0.45)
        self.line(x0 + idw, y0, x0 + idw, y0 + h, C["store_s"], 0.45)
        self.text(x0 + idw / 2, cy, sid, size=3.0, weight="bold")
        self.text(x0 + idw + (w - idw) / 2, cy, name, size=size, family=MONO if name.islower() else FONT)

    def gs_entity(self, cx, cy, w, h, lines, dup=False):
        x0, y0 = cx - w / 2, cy - h / 2
        self.rect(x0 + 1.2, y0 + 1.2, w, h, "#CFCFCF", "none", 0)
        self.rect(x0, y0, w, h, C["ent_f"], C["ent_s"], 0.5)
        if dup:
            self.line(x0, y0 + 5, x0 + 5, y0, C["ent_s"], 0.5)
        self.text(cx, cy, lines, size=3.2, weight="bold")

    # ---------- UML ----------
    def actor(self, cx, cy, name, scale=1.0, size=3.0):
        s = scale
        self.circle(cx, cy - 9 * s, 2.6 * s, "#fff", C["ink"], 0.5)
        self.line(cx, cy - 6.4 * s, cx, cy + 1 * s, C["ink"], 0.5)
        self.line(cx - 5 * s, cy - 4 * s, cx + 5 * s, cy - 4 * s, C["ink"], 0.5)
        self.line(cx, cy + 1 * s, cx - 4.5 * s, cy + 7.5 * s, C["ink"], 0.5)
        self.line(cx, cy + 1 * s, cx + 4.5 * s, cy + 7.5 * s, C["ink"], 0.5)
        self.text(cx, cy + 11.5 * s, name, size=size, weight="bold")

    def usecase(self, cx, cy, rx, ry, lines, size=2.9, fill=None):
        self.ellipse(cx, cy, rx, ry, fill or C["uc_f"], C["uc_s"], 0.4)
        self.text(cx, cy, lines, size=size)

    def swimlane(self, x, y, w, h, title, head=9):
        self.rect(x, y, w, h, C["lane_f"], C["line"], 0.4)
        self.rect(x, y, w, head, C["lane_h"], C["line"], 0.4)
        self.text(x + w / 2, y + head / 2, title, size=3.4, weight="bold")

    def action(self, cx, cy, w, h, lines, caption=None):
        self.rect(cx - w / 2, cy - h / 2, w, h, C["proc_f"], C["proc_s"], 0.4, rx=3)
        self.block_text(cx, cy, lines, caption)

    def objnode(self, cx, cy, w, h, lines):
        self.rect(cx - w / 2, cy - h / 2, w, h, C["obj_f"], C["obj_s"], 0.4)
        self.text(cx, cy, lines, size=3.0, weight="bold")

    def initial(self, cx, cy, r=2.4):
        self.circle(cx, cy, r, C["ink"], C["ink"], 0.3)

    def final(self, cx, cy, r=3.0):
        self.circle(cx, cy, r, "#fff", C["ink"], 0.4)
        self.circle(cx, cy, r * 0.62, C["ink"], C["ink"], 0.3)

    def flow_final(self, cx, cy, r=3.0):
        self.circle(cx, cy, r, "#fff", C["ink"], 0.4)
        k = r * 0.65
        self.line(cx - k, cy - k, cx + k, cy + k, C["ink"], 0.4)
        self.line(cx - k, cy + k, cx + k, cy - k, C["ink"], 0.4)

    def bar(self, cx, cy, w, vertical=False):
        if vertical:
            self.rect(cx - 0.9, cy - w / 2, 1.8, w, C["ink"], "none", 0)
        else:
            self.rect(cx - w / 2, cy - 0.9, w, 1.8, C["ink"], "none", 0)

    def hourglass(self, cx, cy, s=3.2):
        self.poly([(cx - s, cy - s), (cx + s, cy - s), (cx - s, cy + s), (cx + s, cy + s)], "#fff", C["ink"], 0.45)

    def diamond(self, cx, cy, w=7, h=7):
        self.poly([(cx, cy - h / 2), (cx + w / 2, cy), (cx, cy + h / 2), (cx - w / 2, cy)], "#fff", C["ink"], 0.45)

    # ---------- edges ----------
    def edge(self, pts, label=None, lpos=None, dashed=False, arrow=True, stroke=None,
             sw=0.4, size=2.7, hollow=False, both=False, lanchor="middle", lfill=None, bg=True):
        stroke = stroke or C["line"]
        d = "M" + " L".join(f"{x:.2f},{y:.2f}" for x, y in pts)
        mk = ''
        if arrow:
            mk += f' marker-end="url(#{"tri" if hollow else "arr"})"'
        if both:
            mk += ' marker-start="url(#arrs)"'
        da = ' stroke-dasharray="1.6,1.2"' if dashed else ''
        self.add(f'<path d="{d}" fill="none" stroke="{stroke}" stroke-width="{sw}" stroke-linejoin="round"{da}{mk}/>')
        if label:
            if lpos is None:
                (x1, y1), (x2, y2) = pts[0], pts[-1]
                lpos = ((x1 + x2) / 2, (y1 + y2) / 2 - 2.2)
            self.label(lpos[0], lpos[1], label, size=size, anchor=lanchor, fill=lfill, bg=bg)

    def label(self, x, y, text, size=2.7, anchor="middle", fill=None, bg=True, weight="normal"):
        if isinstance(text, str):
            text = [text]
        if bg:
            w = max(tw(t, size) for t in text) + 1.6
            h = len(text) * size * 1.25 + 0.6
            x0 = x - w / 2 if anchor == "middle" else (x - 0.8 if anchor == "start" else x - w + 0.8)
            self.rect(x0, y - h / 2, w, h, "#fff", "none", 0)
        self.text(x, y, text, size=size, anchor=anchor, fill=fill or "#222", weight=weight)

    # ---------- page furniture ----------
    def title_block(self, title, subtitle, page=None, margin=15):
        self.text(margin, margin + 2.5, title, size=5.2, anchor="start", weight="bold")
        self.text(margin, margin + 8.5, subtitle, size=2.9, anchor="start", fill="#444")
        right = f"CICTO Document Tracking System  ·  Version 1.0  ·  {TODAY}"
        if page:
            right += f"  ·  Page {page}"
        self.text(self.w - margin, margin + 2.5, right, size=2.9, anchor="end", fill="#444")
        self.line(margin, margin + 12, self.w - margin, margin + 12, "#999", 0.3)

    def render(self):
        defs = (
            '<defs>'
            '<marker id="arr" viewBox="0 0 10 10" refX="9.5" refY="5" markerWidth="3.2" markerHeight="3.2" '
            'markerUnits="userSpaceOnUse" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#333"/></marker>'
            '<marker id="arrs" viewBox="0 0 10 10" refX="0.5" refY="5" markerWidth="3.2" markerHeight="3.2" '
            'markerUnits="userSpaceOnUse" orient="auto"><path d="M10,0 L0,5 L10,10 z" fill="#333"/></marker>'
            '<marker id="tri" viewBox="0 0 10 10" refX="9.5" refY="5" markerWidth="4.5" markerHeight="4.5" '
            'markerUnits="userSpaceOnUse" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="#fff" stroke="#333" stroke-width="1"/></marker>'
            '</defs>'
        )
        head = (f'<svg xmlns="http://www.w3.org/2000/svg" width="{self.w}mm" height="{self.h}mm" '
                f'viewBox="0 0 {self.w} {self.h}" font-family="{FONT}">')
        bg = f'<rect x="0" y="0" width="{self.w}" height="{self.h}" fill="#ffffff"/>'
        return head + defs + bg + "".join(self.items) + "</svg>"

    def save(self, path):
        with open(path, "w") as f:
            f.write(self.render())


# ---------- table pages ----------
def wrap(text, width_mm, size):
    words = str(text).split()
    lines, cur = [], ""
    for w in words:
        t = (cur + " " + w).strip()
        if tw(t, size) <= width_mm or not cur:
            cur = t
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines or [""]


def table_pages(title, subtitle, headers, rows, widths, size=None, page=None, start_page=2,
                margin=15, top=32, intro=None, mono_cols=()):
    """Return list of Canvas objects. widths in mm; rows are lists of strings."""
    size = size or A3L
    fs = 3.0
    lh = fs * 1.3
    pad = 1.6
    pages = []
    x0 = margin

    def new_page(pn):
        c = Canvas(size)
        c.title_block(title, subtitle, page=pn)
        return c

    pn = start_page
    c = new_page(pn)
    y = top
    if intro:
        for l in intro:
            c.text(margin, y, l, size=3.1, anchor="start", fill="#333")
            y += 4.6
        y += 2

    def header(c, y):
        x = x0
        h = lh + pad * 2
        for hd, w in zip(headers, widths):
            c.rect(x, y, w, h, "#DCE4EE", "#666", 0.3)
            c.text(x + pad, y + h / 2, hd, size=fs, anchor="start", weight="bold")
            x += w
        return y + h

    y = header(c, y)
    for row in rows:
        cells = [wrap(cell, w - pad * 2, fs) for cell, w in zip(row, widths)]
        h = max(len(cl) for cl in cells) * lh + pad * 2
        if y + h > size[1] - margin - 4:
            pages.append(c)
            pn += 1
            c = new_page(pn)
            y = header(c, top)
        x = x0
        for i, (cl, w) in enumerate(zip(cells, widths)):
            c.rect(x, y, w, h, "#fff", "#888", 0.25)
            fam = MONO if i in mono_cols else FONT
            for j, l in enumerate(cl):
                c.text(x + pad, y + pad + lh * j + lh / 2, l, size=fs, anchor="start", family=fam)
            x += w
        y += h
    pages.append(c)
    return pages
