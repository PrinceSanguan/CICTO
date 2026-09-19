<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Signature Certificate {{ $signature->serial }}</title>
    {{--
        dompdf cannot parse oklch(), flexbox or CSS grid, and Tailwind 4's theme
        is built on all three. So this view carries its own hex + table
        stylesheet and shares nothing with the application's CSS. The
        duplication is deliberate; the alternative renders as a blank page.
    --}}
    <style>
        @page { margin: 18mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111827; }
        h1 { font-size: 16pt; margin: 0 0 2mm; }
        .muted { color: #6b7280; }
        .small { font-size: 8pt; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1.5mm 0; }
        .label { width: 38mm; color: #6b7280; }
        .rule { border-top: 1px solid #d1d5db; margin: 5mm 0; }
        .verdict { padding: 3mm; border: 1px solid #d1d5db; }
        .bad { background: #fef2f2; border-color: #ef4444; color: #991b1b; }
        .warn { background: #fffbeb; border-color: #f59e0b; color: #92400e; }
        .mark { border: 1px solid #d1d5db; height: 26mm; width: 80mm; text-align: center; }
        .mark img { max-height: 24mm; max-width: 76mm; }
        .typed { font-size: 20pt; font-style: italic; padding-top: 5mm; }
        .qr { width: 34mm; }
        .qr img { width: 32mm; height: 32mm; }
    </style>
</head>
<body>

<h1>Signature Certificate</h1>
<p class="muted small" style="margin:0 0 5mm">
    {{ config('cicto.support.office') }} &middot; CICTO Document Tracking System
</p>

<div class="rule"></div>

<table>
    <tr>
        <td class="label">Document</td>
        <td><strong>{{ $document->control_number }}</strong><br>{{ $document->title }}</td>
        {{--
            An <img>, not the SVG inline. dompdf silently drops inline <svg>, so
            until 2026-09-19 this cell printed "Scan to verify" over an empty
            space on every certificate. As a data-URI image it goes through
            dompdf's SVG renderer and prints. It is our own QrCodeRenderer
            output, never user input.
        --}}
        <td class="qr" rowspan="5"><img src="data:image/svg+xml;base64,{{ base64_encode((string) $qr) }}" alt="QR code to verify this signature"><div class="small muted" style="text-align:center">Scan to verify</div></td>
    </tr>
    <tr><td class="label">Signed by</td><td><strong>{{ $signature->signer_name }}</strong></td></tr>
    <tr><td class="label">Position</td><td>{{ $signature->signer_position ?? '—' }}</td></tr>
    <tr><td class="label">Office</td><td>{{ $signature->signer_office ?? '—' }}</td></tr>
    <tr><td class="label">Date signed</td><td>{{ $signature->signed_at->format('d F Y, g:i A') }}</td></tr>
</table>

{{--
    File version, file fingerprint and certificate serial used to be printed
    here, and the client asked for all three to go on 2026-09-19. They are
    machine identifiers, not something a person holding the paper reads. None
    of them is lost: the fingerprint is still stored and still checked, and the
    QR above leads to the verification page, which is looked up by the serial.
--}}
<table style="margin-top:3mm">
    <tr><td class="label">Purpose</td><td>{{ $signature->purposeLabel() }}</td></tr>
</table>

<div class="rule"></div>

<p class="muted small" style="margin:0 0 2mm">Signature</p>
<div class="mark">
    @if ($signature->method->requiresImage() && $signature->image_path)
        @php
            $bytes = \Illuminate\Support\Facades\Storage::disk($signature->image_disk)->get($signature->image_path);
        @endphp
        <img src="data:image/png;base64,{{ base64_encode($bytes) }}" alt="Signature">
    @else
        <div class="typed">{{ $signature->signer_name }}</div>
    @endif
</div>

<div class="rule"></div>

{{--
    NO "VALID AT TIME OF PRINTING" BOX, at the client's request of 2026-09-19.
    It pointed at the fingerprint printed above, which has gone too, and on a
    certificate for a signature that is fine it said nothing the reader needed.

    The two warnings stay. They only ever print when something IS wrong -- a
    newer version has replaced the signed one, or the signed file no longer
    matches -- and a certificate that looked clean in either case would be a
    certificate lying about the document it is attached to.
--}}
@if ($valid && $superseded)
    <div class="verdict warn">
        <strong>Valid, but superseded.</strong>
        A newer version of this document has been uploaded since it was signed.
        This certificate covers version {{ $signature->file?->version }} only.
    </div>

    <div class="rule"></div>
@elseif (! $valid)
    <div class="verdict bad">
        <strong>Does not match.</strong>
        The file recorded against this signature has been changed or removed.
        Verify online before relying on this certificate.
    </div>

    <div class="rule"></div>
@endif

{{--
    The honest-limits paragraph. It is on the certificate itself, not buried in
    a manual, because the certificate is the artefact that will be handed to
    someone who has to decide whether to rely on it.
--}}
<p class="small muted" style="line-height:1.5">
    <strong>What this certificate is.</strong>
    It records that the named person, signed in to CICTO, applied their signature to this
    document on the date shown. The system keeps a fingerprint of the exact file that was
    signed, so a later substitution can be detected.
    <br><br>
    <strong>What it is not.</strong>
    This is an electronic signature, not a digital certificate issued under the Philippine
    National Public Key Infrastructure (PNPKI). No certificate authority has verified the
    signer's identity, and the signature is not embedded in the document file itself. Verify
    at <span style="word-break:break-all">{{ $verifyUrl }}</span>
</p>

<p class="small muted" style="margin-top:4mm">
    Generated {{ now()->format('d F Y, g:i A') }}.
</p>

</body>
</html>
