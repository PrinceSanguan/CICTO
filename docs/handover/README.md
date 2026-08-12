# Handover documents

| File | What it is |
|---|---|
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Install and operations runbook, for whoever hosts the system. |
| `CICTO-Testing-Guide.pdf` | The client-facing testing checklist, **in English**. Plain, tick-box, written for someone with no technical background. This is the one to send. |
| `CICTO-Gabay-sa-Pagsubok.pdf` | An earlier, longer **Filipino** version. Same subject, but written as prose rather than a checklist. |
| `CICTO-Testing-Guide.html`<br>`CICTO-Gabay-sa-Pagsubok.html` | Sources for the two PDFs. Edit these, never the PDFs. |

**The two are no longer the same document.** The English one was rewritten as a
plain checklist with tick-boxes and space to write on: fewer words, no jargon,
and a deliberately unstyled look so it reads as an office handout rather than
something generated. The Filipino one is still the original prose version.

If the Filipino audience matters, it should be rewritten to match the English
checklist. Until then, do not describe them as translations of each other.

## Regenerating a PDF

They are written as HTML and rendered with the same headless Chrome the QA
harness already drives, so producing a PDF needs no dependency in
`composer.json`:

```sh
# English
node docs/qa/html-to-pdf.mjs \
    docs/handover/CICTO-Testing-Guide.html \
    docs/handover/CICTO-Testing-Guide.pdf \
    "CICTO — Testing Guide"

# Filipino — the last two arguments are the footer's "Page" and "of"
node docs/qa/html-to-pdf.mjs \
    docs/handover/CICTO-Gabay-sa-Pagsubok.html \
    docs/handover/CICTO-Gabay-sa-Pagsubok.pdf \
    "CICTO — Gabay sa Pagsubok" "Pahina" "ng"
```

**If you edit one, edit the other** — once they have been brought back into
line. A translated document that has silently drifted from its original is
worse than having only one.

## Before sending either of them

Two things are deliberately blank or provisional:

- **The system address** (§2) is a blank line. Fill it in, or send the URL in
  the covering message.
- **The test accounts** (§2) all share the password `password`. Both guides say
  so plainly and warn the accounts are replaced before go-live — do not quietly
  delete that warning to make the document look tidier.

Both documents carry a "Things that do not work yet" section and a "What we need
from you" section. They exist so the client does not spend an afternoon
reporting known gaps as defects, and so the outstanding questions are asked
somewhere the client will actually read.
