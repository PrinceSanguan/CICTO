<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $heading }}</title>
    {{--
        The Reports page's "User activity" on paper: the whole list, or one
        document's or one person's trail (client request, 2026-09-29).

        One view for two readers. dompdf renders it as the PDF, and the browser
        renders it as the print page -- so, like the document register, it is
        hand-written hex and tables: dompdf cannot parse oklch(), flexbox or
        grid, and the app's Tailwind theme is built on all three.
    --}}
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 8pt; color: #111827; margin: 0; }
        h1 { font-size: 14pt; margin: 0 0 1mm; }
        .muted { color: #6b7280; }
        .meta { font-size: 8pt; margin: 0 0 4mm; line-height: 1.5; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th {
            text-align: left; background: #f3f4f6; border-bottom: 1px solid #d1d5db;
            padding: 1.5mm; font-size: 7.5pt;
        }
        table.data td { padding: 1.5mm; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.data tr { page-break-inside: avoid; break-inside: avoid; }
        table.data tr:nth-child(even) td { background: #fafafa; }

        .toolbar {
            display: none; padding: 12px 16px; margin: 0 0 16px;
            background: #f5f5f5; border-bottom: 1px solid #ddd; font-size: 13px;
        }
        .toolbar button { font: inherit; padding: 6px 14px; cursor: pointer; }
        .toolbar span { color: #555; margin-left: 8px; }
        .sheet { padding: 0; }

        @if ($print)
            .toolbar { display: block; }
            .sheet { padding: 0 16px 16px; }

            @media print {
                html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .toolbar { display: none; }
                .sheet { padding: 0; }
            }
        @endif
    </style>
</head>
<body>

@if ($print)
    <div class="toolbar">
        <button type="button" id="print-sheet">Print</button>
        <span>To keep a file instead, choose “Save as PDF” as the printer.</span>
    </div>
@endif

<div class="sheet">
    <h1>{{ $heading }}</h1>
    <p class="meta muted">
        @foreach ($lines as $line)
            {{ $line }}<br>
        @endforeach
        {{ $generated }} &middot; {{ count($rows) }} {{ \Illuminate\Support\Str::plural('row', count($rows)) }}
        <br>
        {{ $systemOwner }} &middot; CICTO Document Tracking System
    </p>

    <table class="data">
        <thead>
            <tr>
                @foreach ($headings as $title)
                    <th>{{ $title }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell ?? '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headings) }}" class="muted" style="text-align:center; padding:6mm">{{ $empty }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($print)
    {{-- Nonced, as on the label sheet: CSP counts an onclick attribute as
         inline script, and a nonce cannot whitelist one. --}}
    <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.getElementById('print-sheet')?.addEventListener('click', function () {
            window.print();
        });

        // Opened from the card's Print button: straight to the dialog.
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
@endif

</body>
</html>
