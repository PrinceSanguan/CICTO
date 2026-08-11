# Handover documents

| File | Ano ito |
|---|---|
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Install and operations runbook, for whoever hosts the system. |
| `CICTO-Gabay-sa-Pagsubok.pdf` | The client-facing UAT guide, in Filipino. This is the file to send. |
| `CICTO-Gabay-sa-Pagsubok.html` | Source for the PDF above. Edit this, never the PDF. |

## Regenerating the PDF

The guide is written as HTML and rendered with the same headless Chrome the QA
harness uses, so it needs no PDF dependency in `composer.json`:

```sh
node docs/qa/html-to-pdf.mjs \
    docs/handover/CICTO-Gabay-sa-Pagsubok.html \
    docs/handover/CICTO-Gabay-sa-Pagsubok.pdf \
    "CICTO — Gabay sa Pagsubok"
```

## Before sending it

Two things in the guide are deliberately blank or provisional:

- **The system address** (§2) is a blank line. Fill it in, or send the URL in
  the covering message.
- **The test accounts** (§2) all share the password `password`. The guide says
  so plainly and warns they must be replaced before go-live — do not quietly
  delete that warning to make the document look tidier.

The guide also lists, in §7, everything that does not yet work and why, and in
§8 the answers still needed from the client. Both sections exist so the client
does not spend their afternoon reporting known gaps as defects.
