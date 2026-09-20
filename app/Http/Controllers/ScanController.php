<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentScan;
use App\Support\Deadlines;
use App\Support\Presenters\DocumentPresenter;
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
    public function __construct(private readonly DocumentPresenter $presenter) {}

    /**
     * The staff-facing scan console: a focused input that a USB keyboard-wedge
     * scanner types into, plus an optional camera reader.
     *
     * The wedge is the default because it needs no library and no permissions.
     * Camera scanning requires a secure context, which is a deployment fact
     * nobody has confirmed yet -- the page decides at runtime.
     */
    public function console(Request $request): Response
    {
        return Inertia::render('documents/scan', [
            // What resolve() could not find, so the console can say so
            // instead of appearing to have ignored the scan.
            'miss' => $request->session()->get('scanMiss'),
        ]);
    }

    /**
     * The staff scan box, which accepts whatever is on the label.
     *
     * THE BUG THIS FIXES, reported 2026-09-20: an office scanned a label,
     * typed `OCM-2026-00014` into the box that says "Scan the label, or type
     * the code", and got "Document not found" for a document that exists. The
     * console was sending everything to the public /s/{token} path, which
     * resolves the 26-character QR token and nothing else -- so the control
     * number printed in large mono on that very label, the one the label's own
     * comment calls "the human fallback", was the one thing it could not
     * resolve.
     *
     * A control number is accepted HERE and never on the public path. It is
     * sequential, so resolving one without a session would hand anybody the
     * whole register a number at a time; behind auth it is something a clerk
     * can already search for.
     *
     * The answer is always a redirect, never a page of its own: whatever was
     * typed, the operator ends up where that thing lives.
     */
    public function resolve(Request $request): RedirectResponse
    {
        $code = trim((string) $request->query('code'));

        // A wedge scanner types the whole encoded URL, so take the last
        // segment the same way the console's own reader does.
        $segments = explode('/', rtrim($code, '/'));
        $tail = (string) end($segments);

        // A token goes to the public path, which already decides what to show
        // based on the viewer's session -- staff get redirected to the
        // document, everyone else gets the reduced page.
        if (QrToken::isValid($tail)) {
            return redirect()->route('scan.show', ['token' => $tail]);
        }

        $document = Document::query()
            ->where('control_number', $code)
            ->first();

        if ($document === null) {
            return $this->missed($code, 'missing');
        }

        /*
         * "NOT YOURS" IS SAID OUT LOUD, and it is a reversal.
         *
         * The first version answered a real-but-unreadable document exactly
         * like an invented one, so that this box could not be used to confirm
         * which control numbers exist across offices. Within hours it cost
         * exactly what a lie costs: an office typed a control number that was
         * on the label in their hand, were told nothing matched, and went and
         * queried the production database to find the document sitting there
         * (2026-09-21). The page had said "does not exist" about something
         * that did.
         *
         * What the honest answer leaks is EXISTENCE and nothing else -- no
         * title, no status, not even which office holds it. Control numbers
         * are sequential, printed on every label and read aloud across
         * mailrooms, so "OCM has reached 14 this year" is not a secret worth
         * making the register lie for. The contents stay behind the policy,
         * which is the part that was ever protecting anything.
         */
        if ($request->user()?->cannot('view', $document)) {
            return $this->missed($code, 'forbidden');
        }

        return redirect()->route('documents.show', $document);
    }

    /**
     * Back to the box, saying which kind of nothing it was.
     *
     * A redirect rather than a page of its own: a mistyped code is the
     * ordinary outcome of a smudged label, and losing the scanner's focus for
     * it means the next scan goes nowhere.
     */
    private function missed(string $code, string $reason): RedirectResponse
    {
        return redirect()
            ->route('documents.scan')
            ->with('scanMiss', ['code' => $code, 'reason' => $reason]);
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
            ->with([
                'openMovement.toOffice:id,name',
                'lastMovement.toOffice:id,name',
                'documentType:id,name',
            ])
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

                /*
                 * THE TITLE IS DELIBERATELY PUBLIC, client request 2026-09-20.
                 *
                 * It was withheld until then, and the reasoning that withheld
                 * it was not wrong: the control number identifies a folder
                 * only to someone who already has the register, while a title
                 * like "Termination of <name>" identifies its SUBJECT to
                 * anybody who scans the label in a mailroom. The printed label
                 * still carries nothing but the code for that same reason.
                 *
                 * The client's answer is that a courier holding an unlabelled
                 * folder cannot tell which document they are tracking, and
                 * that the code alone is useless to the citizen who filed it.
                 * That is their call to make -- it is their register and their
                 * RA 10173 exposure -- and it is recorded here so the next
                 * person does not "fix" it back.
                 *
                 * What did NOT change: description and remarks stay out. A
                 * title is a name; those two are contents.
                 */
                'title' => $document->title,

                'status_label' => $document->status->publicLabel(),
                // publicTone(), to pair with publicLabel() above. tone() is
                // per workflow STATE, so an initiated and a returned document
                // both reading "Pending" would render in different colours on
                // a page a member of the public sees.
                'status_tone' => $document->status->publicTone(),
                /*
                 * The same helper the staff pages use, so the two screens can
                 * never name different offices for the same folder.
                 *
                 * It falls back to the last closed leg, which matters here more
                 * than anywhere: openMovement is null on a finished document,
                 * and this field's own heading turns into "Last handled by"
                 * for exactly that case.
                 */
                'current_office' => $this->presenter->restingOffice(
                    $document,
                    $document->openMovement,
                ),
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
