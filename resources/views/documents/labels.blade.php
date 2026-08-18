<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Document labels</title>
    {{--
        Plain Blade, not Inertia: printing must never depend on the SPA
        hydrating. The QR is inlined as SVG for the same reason -- an <img> can
        still be loading when the print dialog fires, and the sticker comes out
        with a blank square where the code should be.
    --}}
    <style>
        /* A4 sticker stock, 24-up at 70 x 37 mm (Avery L7159/L7173 geometry).
           LGUs already own an A4 laser; a thermal label printer is a
           procurement cycle. */
        @page { size: A4 portrait; margin: 8.5mm 4.75mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            color: #000;
        }

        .toolbar { padding: 12px 16px; background: #f5f5f5; border-bottom: 1px solid #ddd; font-size: 13px; }
        .toolbar button { font: inherit; padding: 6px 14px; cursor: pointer; }
        .toolbar p { margin: 6px 0 0; color: #555; }

        .sheet {
            display: grid;
            grid-template-columns: repeat(3, 70mm);
            grid-auto-rows: 37mm;
            gap: 0 2.5mm;
        }

        .label {
            break-inside: avoid;
            page-break-inside: avoid;
            overflow: hidden;
            display: grid;
            grid-template-columns: 26mm 1fr;
            align-items: center;
            gap: 2mm;
            padding: 2mm;
        }

        /* Never below 24mm including the quiet zone: smaller than that stops
           resolving reliably on mid-range phones under office fluorescents. */
        .label svg { width: 24mm; height: 24mm; display: block; }

        /* overflow-wrap because .label clips (overflow: hidden) and the real
           office codes include hyphenated ones -- CENRO-SS-2026-00042 is 19
           monospace characters against roughly 38mm of text column. Wrapping
           onto a second line costs nothing here: the row is 37mm tall and the
           QR beside it is 24mm. */
        .cn { font: 700 11pt/1.1 ui-monospace, "SF Mono", Menlo, monospace; letter-spacing: .02em; overflow-wrap: anywhere; }
        .meta { font-size: 7pt; line-height: 1.35; color: #333; margin-top: 1mm; }
        .priority { font-size: 7pt; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }

        .blank { }

        @media print {
            html, body { margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button type="button" id="print-labels">Print labels</button>
        <p>
            Use matte A4 sticker stock, 24 labels per sheet (70 &times; 37&nbsp;mm).
            Gloss stock under packing tape reflects office lighting and can defeat the scan.
        </p>
        <p>
            Reusing a part-used sheet? Add <code>&amp;offset=N</code> to the URL to skip the first N labels.
        </p>
    </div>

    <div class="sheet">
        {{-- Blank cells so a half-used sheet can be reloaded. Clerks do this
             regardless of whether it is supported. --}}
        @for ($i = 0; $i < $offset; $i++)
            <div class="label blank"></div>
        @endfor

        @foreach ($documents as $document)
            <div class="label">
                <div>{!! $document['svg'] !!}</div>
                <div>
                    {{-- Control number in large mono: the human fallback, and
                         what a keyboard-wedge scanner operator reads aloud.
                         Nothing else identifying -- the folder travels through
                         mailrooms and the label is the part everyone sees. --}}
                    <div class="cn">{{ $document['control_number'] }}</div>
                    <div class="meta">
                        {{ $document['office_code'] ?? '' }}<br>
                        {{ $document['received'] }}
                    </div>
                    <div class="priority">{{ $document['priority'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($documents->isEmpty())
        <p class="no-print" style="padding:16px">No documents selected.</p>
    @endif
    {{-- Nonced rather than an onclick attribute: CSP treats an inline event
         handler as inline script, and a nonce cannot whitelist an attribute. --}}
    <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.getElementById('print-labels')?.addEventListener('click', function () {
            window.print();
        });
    </script>
</body>
</html>
