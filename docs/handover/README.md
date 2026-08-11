# Handover documents

| File | What it is |
|---|---|
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Install and operations runbook, for whoever hosts the system. |
| `CICTO-Testing-Guide.pdf` | The client-facing UAT guide, **in English**. |
| `CICTO-Gabay-sa-Pagsubok.pdf` | The same guide **in Filipino**, for staff who prefer it. |
| `CICTO-Testing-Guide.html`<br>`CICTO-Gabay-sa-Pagsubok.html` | Sources for the two PDFs. Edit these, never the PDFs. |

Both guides say the same things in the same order, section for section. Send
whichever suits the audience — or both, since counter staff and department heads
do not always want the same language.

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

**If you edit one, edit the other.** They are separate files by design — a
translated document that has silently drifted from its original is worse than
having only one.

## Before sending either of them

Two things are deliberately blank or provisional:

- **The system address** (§2) is a blank line. Fill it in, or send the URL in
  the covering message.
- **The test accounts** (§2) all share the password `password`. Both guides say
  so plainly and warn the accounts are replaced before go-live — do not quietly
  delete that warning to make the document look tidier.

§7 lists everything that does not work yet and why; §8 lists the answers still
needed from the client. Both exist so the client does not spend their afternoon
reporting known gaps as defects.
