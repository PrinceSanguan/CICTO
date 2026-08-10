<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Document register</title>
    {{--
        Hand-written hex + table CSS. dompdf cannot parse oklch(), flexbox or
        grid, so this shares nothing with the Tailwind 4 theme. It will drift
        from the app's look over time; the alternative is a blank PDF.
    --}}
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #111827; }
        h1 { font-size: 14pt; margin: 0 0 1mm; }
        .muted { color: #6b7280; }
        .meta { font-size: 8pt; margin: 0 0 4mm; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th {
            text-align: left; background: #f3f4f6; border-bottom: 1px solid #d1d5db;
            padding: 1.5mm; font-size: 7.5pt;
        }
        table.data td { padding: 1.5mm; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.data tr:nth-child(even) td { background: #fafafa; }
        .cn { font-family: DejaVu Sans Mono, monospace; font-size: 7.5pt; white-space: nowrap; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
        .summary td { border: 1px solid #d1d5db; padding: 2mm; width: 25%; }
        .summary .n { font-size: 13pt; font-weight: bold; }
    </style>
</head>
<body>

<h1>Document register</h1>
<p class="meta muted">
    {{ $office }} &middot; generated {{ now()->format('d F Y, g:i A') }}
    by {{ $generatedFor->name }}
    &middot; {{ $documents->count() }} document(s)
</p>

<table class="summary">
    <tr>
        <td><div class="muted">Total</div><div class="n">{{ $summary['total'] }}</div></td>
        <td><div class="muted">Processed this month</div><div class="n">{{ $summary['processed'] }}</div></td>
        <td><div class="muted">Delayed</div><div class="n">{{ $summary['delayed'] }}</div></td>
        <td>
            <div class="muted">Approval rate</div>
            {{-- An em dash, not 0%: nothing decided is not the same as everything rejected. --}}
            <div class="n">{{ $summary['approval_rate'] === null ? '—' : $summary['approval_rate'].'%' }}</div>
        </td>
    </tr>
</table>

<table class="data">
    <thead>
        <tr>
            <th style="width:22mm">Control no.</th>
            <th>Title</th>
            <th style="width:22mm">Type</th>
            <th style="width:18mm">Status</th>
            <th style="width:14mm">Priority</th>
            <th style="width:26mm">Currently at</th>
            <th style="width:18mm">Registered</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($documents as $document)
            <tr>
                <td class="cn">{{ $document->control_number }}</td>
                <td>{{ \Illuminate\Support\Str::limit($document->title, 70) }}</td>
                <td>{{ $document->documentType?->name }}</td>
                <td>{{ $document->status->publicLabel() }}</td>
                <td>{{ $document->priority->label() }}</td>
                <td>{{ $document->openMovement?->toOffice?->name ?? '—' }}</td>
                <td>{{ $document->created_at?->format('d M Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center; padding:6mm">No documents.</td></tr>
        @endforelse
    </tbody>
</table>

</body>
</html>
