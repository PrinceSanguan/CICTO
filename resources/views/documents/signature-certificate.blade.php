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
        .ok { background: #ecfdf5; border-color: #10b981; color: #065f46; }
        .bad { background: #fef2f2; border-color: #ef4444; color: #991b1b; }
        .warn { background: #fffbeb; border-color: #f59e0b; color: #92400e; }
        .mark { border: 1px solid #d1d5db; height: 26mm; width: 80mm; text-align: center; }
        .mark img { max-height: 24mm; max-width: 76mm; }
        .typed { font-size: 20pt; font-style: italic; padding-top: 5mm; }
        .qr { width: 34mm; }
        .qr svg { width: 32mm; height: 32mm; }
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
        <td class="qr" rowspan="5">{!! $qr !!}<div class="small muted" style="text-align:center">Scan to verify</div></td>
    </tr>
    <tr><td class="label">Signed by</td><td><strong>{{ $signature->signer_name }}</strong></td></tr>
    <tr><td class="label">Position</td><td>{{ $signature->signer_position ?? '—' }}</td></tr>
    <tr><td class="label">Office</td><td>{{ $signature->signer_office ?? '—' }}</td></tr>
    <tr><td class="label">Date signed</td><td>{{ $signature->signed_at->format('d F Y, g:i A') }}</td></tr>
</table>

<table style="margin-top:3mm">
    <tr><td class="label">Purpose</td><td>{{ ucfirst($signature->purpose) }}</td></tr>
    <tr>
        <td class="label">File version</td>
        <td>{{ $signature->file ? 'Version '.$signature->file->version : 'No file attached' }}</td>
    </tr>
    <tr>
        <td class="label">File fingerprint</td>
        <td class="small" style="word-break:break-all">{{ $signature->document_hash_sha256 ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Certificate serial</td>
        <td class="small" style="word-break:break-all">{{ $signature->serial }}</td>
    </tr>
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

@if ($valid && ! $superseded)
    <div class="verdict ok">
        <strong>Valid at time of printing.</strong>
        The signed file still matches the fingerprint recorded above.
    </div>
@elseif ($valid && $superseded)
    <div class="verdict warn">
        <strong>Valid, but superseded.</strong>
        A newer version of this document has been uploaded since it was signed.
        This certificate covers version {{ $signature->file?->version }} only.
    </div>
@else
    <div class="verdict bad">
        <strong>Does not match.</strong>
        The file recorded against this signature has been changed or removed.
        Verify online before relying on this certificate.
    </div>
@endif

<div class="rule"></div>

{{--
    The honest-limits paragraph. It is on the certificate itself, not buried in
    a manual, because the certificate is the artefact that will be handed to
    someone who has to decide whether to rely on it.
--}}
<p class="small muted" style="line-height:1.5">
    <strong>What this certificate is.</strong>
    It records that the named person, signed in to CICTO, applied their signature to the
    exact file version identified above on the date shown, and stores a fingerprint of that
    file so a later substitution can be detected.
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
