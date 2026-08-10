<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\QrCodeRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

/**
 * The printable QR label sheet, and the single-document QR image.
 *
 * The label page is plain Blade rather than Inertia on purpose: printing must
 * not depend on the SPA hydrating. The SVG is inlined for the same reason -- an
 * <img> can still be loading when the print dialog fires, and the sticker comes
 * out with a blank square.
 */
class DocumentLabelController extends Controller
{
    public function __construct(private readonly QrCodeRenderer $qr) {}

    /**
     * A4 sticker sheet, 24-up at 70x37mm (Avery L7159/L7173 geometry). LGUs
     * already own an A4 laser; a thermal label printer is a procurement cycle.
     *
     * ?offset=N leaves N cells blank so a half-used sheet can be reloaded.
     * Clerks will do this whether or not it is supported.
     */
    public function print(Request $request): View
    {
        $ids = collect($request->query('ids', []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->take(240)
            ->all();

        $documents = Document::query()
            ->visibleTo($request->user())
            ->whereIn('id', $ids)
            ->with(['openMovement.toOffice:id,code', 'originatingOffice:id,code'])
            ->get()
            ->map(fn (Document $document) => [
                'control_number' => $document->control_number,
                'office_code' => $document->openMovement?->toOffice->code
                    ?? $document->originatingOffice?->code,
                'received' => $document->created_at?->format('d M Y'),
                'priority' => $document->priority->label(),
                'svg' => new HtmlString($this->qr->forDocument($document, 240)),
            ]);

        return view('documents.labels', [
            'documents' => $documents,
            'offset' => max(0, min(23, (int) $request->query('offset', 0))),
        ]);
    }

    /** Single QR for the document detail screen. */
    public function svg(Document $document): Response
    {
        $this->authorize('view', $document);

        return response($this->qr->forDocument($document), 200, [
            'Content-Type' => 'image/svg+xml',
            // The token never changes, so this is safe to cache hard -- but
            // privately: it identifies a specific document.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
