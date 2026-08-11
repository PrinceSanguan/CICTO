<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentScan;
use App\Support\Deadlines;
use App\Support\QrToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §7 QR scanning.
 *
 * The token carries no authority. Possession of it proves only that you have
 * seen the folder -- which anyone who handles the document has. Confidentiality
 * is therefore enforced HERE, against the viewer's session, not against
 * knowledge of the token.
 */
class ScanController extends Controller
{
    /**
     * The staff-facing scan console: a focused input that a USB keyboard-wedge
     * scanner types into, plus an optional camera reader.
     *
     * The wedge is the default because it needs no library and no permissions.
     * Camera scanning requires a secure context, which is a deployment fact
     * nobody has confirmed yet -- the page decides at runtime.
     */
    public function console(): Response
    {
        return Inertia::render('documents/scan');
    }

    /**
     * Resolve a scanned token.
     *
     * Deliberately NOT route-model bound: a bad token must render a friendly
     * "not found" rather than a 404 stack, because couriers will mistype.
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        if (! QrToken::isValid($token)) {
            return Inertia::render('documents/scan-not-found', ['token' => $token]);
        }

        $document = Document::query()
            ->where('qr_token', $token)
            ->with(['openMovement.toOffice:id,name', 'documentType:id,name'])
            ->first();

        if ($document === null) {
            return Inertia::render('documents/scan-not-found', ['token' => $token]);
        }

        $this->recordScan($request, $document);

        $user = $request->user();

        // Staff who may read the document get the real thing, at the same URL
        // every other link uses -- which is why document routes are top-level
        // and not nested under a role prefix.
        if ($user !== null && $user->can('view', $document)) {
            return redirect()->route('documents.show', $document);
        }

        // Everyone else gets a separate, reduced page. Not the staff page with
        // fields hidden: hidden-in-props is how confidential data leaks through
        // the Inertia payload.
        return Inertia::render('documents/scan-public', [
            'document' => [
                'control_number' => $document->control_number,
                'status_label' => $document->status->publicLabel(),
                // publicTone(), to pair with publicLabel() above. tone() is
                // per workflow STATE, so an initiated and a returned document
                // both reading "Pending" would render in different colours on
                // a page a member of the public sees.
                'status_tone' => $document->status->publicTone(),
                'current_office' => $document->openMovement?->toOffice?->name,
                'updated_at' => $document->updated_at?->toIso8601String(),
                'is_complete' => $document->status->isTerminal(),
            ],
        ]);
    }

    /**
     * A scan is a lookup, not a transfer, so it never touches the ledger.
     *
     * Deduplicated within a short window: a courier waving a phone at a label
     * fires the reader repeatedly and would otherwise write twenty rows.
     */
    private function recordScan(Request $request, Document $document): void
    {
        $user = $request->user();
        $window = (int) config('cicto.scans.dedupe_seconds', 60);

        $recent = DocumentScan::query()
            ->where('document_id', $document->id)
            ->where('scanned_at', '>=', Deadlines::now()->subSeconds($window))
            ->when(
                $user !== null,
                fn ($query) => $query->where('user_id', $user->id),
                fn ($query) => $query->whereNull('user_id')->where('ip_address', $request->ip()),
            )
            ->exists();

        if ($recent) {
            return;
        }

        DocumentScan::create([
            'document_id' => $document->id,
            'user_id' => $user?->id,
            'office_id' => $user?->office_id,
            'source' => in_array($request->query('via'), ['camera', 'wedge', 'manual'], true)
                ? $request->query('via')
                : 'camera',
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 191) ?: null,
            'scanned_at' => Deadlines::now(),
        ]);
    }
}
