<?php

namespace App\Http\Controllers;

use App\Actions\Documents\RegisterDocument;
use App\Enums\DocumentPriority;
use App\Enums\DocumentStatus;
use App\Http\Requests\Documents\IndexDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Office;
use App\Support\Presenters\DocumentPresenter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentPresenter $presenter) {}

    /**
     * §8 Track Documents.
     */
    public function index(IndexDocumentRequest $request): Response
    {
        $user = $request->user();

        // Re-narrowed to literals here even though IndexDocumentRequest already
        // validates them through Rule::in. These two values are interpolated
        // into orderBy, so the whitelist is restated where the interpolation
        // happens rather than trusted from three files away.
        $sort = match ($request->input('sort')) {
            'control_number' => 'control_number',
            'status' => 'status',
            'due_at' => 'due_at',
            default => 'created_at',
        };

        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';

        $documents = Document::query()
            ->visibleTo($user)
            ->active()
            ->with(['documentType:id,name', 'openMovement.toOffice:id,name'])
            ->search($request->input('q'))
            ->when($request->filled('status'), fn ($query) => $query->whereIn(
                'documents.status',
                DocumentStatus::fromPublicValue((string) $request->input('status')),
            ))
            ->when($request->filled('priority'), fn ($query) => $query->where('documents.priority', $request->input('priority')))
            ->when($request->filled('document_type_id'), fn ($query) => $query->where('documents.document_type_id', $request->integer('document_type_id')))
            ->when($request->filled('office_id'), fn ($query) => $query->heldByOffice($request->integer('office_id')))
            ->when($request->date('from'), fn ($query, $date) => $query->where('documents.created_at', '>=', $date->startOfDay()))
            ->when($request->date('to'), fn ($query, $date) => $query->where('documents.created_at', '<=', $date->endOfDay()))
            ->when($request->input('due') === 'overdue', fn ($query) => $query->overdue())
            ->when($request->input('due') === 'approaching', fn ($query) => $query->approachingDeadline())
            ->orderBy("documents.{$sort}", $dir)
            // Deterministic tiebreak. Without it, rows with equal sort keys can
            // reappear on page 2 and others never appear at all.
            ->orderBy('documents.id', 'desc')
            ->paginate($request->integer('per_page') ?: 25)
            ->withQueryString()
            ->through(fn (Document $document) => $this->presenter->listItem($document));

        /*
         * Deliberately does NOT ship offices/documentTypes/priorities.
         *
         * Track Documents filters by status and by search term; it has never
         * rendered an office or type picker, and documents/index.tsx destructures
         * only documents, filters and statuses. Those three unread props cost two
         * queries and about 5 KB on every full navigation -- roughly a quarter of
         * the payload -- and the client's real reference lists made that five
         * times worse than the placeholder ones did. IndexDocumentRequest still
         * accepts office and type filters from the query string, so a bookmarked
         * or hand-written URL keeps working; the day a picker is added, add the
         * props back beside it.
         */
        return Inertia::render('documents/index', [
            'documents' => $documents,
            'filters' => $request->filters(),
            'statuses' => DocumentStatus::publicOptions(),
        ]);
    }

    /**
     * §5 Submit Document form.
     */
    public function create(): Response
    {
        $this->authorize('create', Document::class);

        $offices = Office::query()->active()->ordered()->get(['id', 'code', 'name']);

        /*
         * Only pre-select the user's office if it is still on the list.
         *
         * A <select> asked to default to a value none of its <option>s carry
         * does not stay blank -- the browser selects the first selectable
         * option instead. For a user whose office has been deactivated (which
         * happens the moment an installation re-seeds onto the client's real
         * office list) that silently pre-fills SOMEBODY ELSE'S department, and
         * the form submits happily: a document filed under the wrong office,
         * with that office's prefix burned into a control number that is then
         * printed on a label. Sending null instead leaves the department list
         * empty, so the field is visibly unanswered and StoreDocumentRequest's
         * `required` refuses the submit.
         */
        $userOfficeId = request()->user()?->office_id;

        return Inertia::render('documents/create', [
            'offices' => $offices,
            'documentTypes' => DocumentType::query()->active()->ordered()
                ->get(['id', 'name', 'turnaround_days']),
            'priorities' => $this->priorityOptions(),
            'defaultOfficeId' => $offices->contains('id', $userOfficeId) ? $userOfficeId : null,
        ]);
    }

    public function store(StoreDocumentRequest $request, RegisterDocument $register): RedirectResponse
    {
        /*
         * Departments in the order the submitter picked them. The first one
         * registers the document -- it owns the control number prefix and the
         * genesis leg -- and the rest are queued as the route it travels from
         * there. StoreDocumentRequest guarantees at least one; the `?? 0` is
         * only what makes that guarantee legible to static analysis.
         *
         * @var list<int> $officeIds
         */
        $officeIds = array_values(array_map('intval', (array) $request->input('office_ids', [])));

        $office = Office::query()->findOrFail($officeIds[0] ?? 0);
        $queued = array_slice($officeIds, 1);

        $document = $register->handle(
            title: $request->string('title')->value(),
            documentTypeId: $request->integer('document_type_id'),
            priority: $request->enum('priority', DocumentPriority::class) ?? DocumentPriority::Normal,
            originatingOffice: $office,
            creator: $request->user(),
            description: $request->input('description'),
            remarks: $request->input('remarks'),
            upload: $request->file('file'),
            routeOfficeIds: $queued,
            request: $request,
        );

        return to_route('documents.show', $document)
            ->with('toast', [
                'type' => 'success',
                'message' => $this->registrationConfirmation($document, $queued),
            ]);
    }

    /**
     * "Document registered as X." for one department, and for several the
     * sentence that reads the route back -- the submitter's proof that the
     * extra picks were kept and where the folder goes next.
     *
     * Names in the order they were picked, not in id or alphabetical order.
     *
     * @param  list<int>  $queued  the departments after the originating one
     */
    private function registrationConfirmation(Document $document, array $queued): string
    {
        $plain = "Document registered as {$document->control_number}.";

        if ($queued === []) {
            return $plain;
        }

        $names = Office::query()->whereKey($queued)->pluck('name', 'id');

        $ordered = array_values(array_filter(array_map(
            static fn (int $id) => $names[$id] ?? null,
            $queued,
        )));

        if ($ordered === []) {
            return $plain;
        }

        $last = array_pop($ordered);
        $list = $ordered === [] ? $last : implode(', ', $ordered).' and '.$last;

        return "Document registered as {$document->control_number}, then queued for {$list}.";
    }

    public function show(Document $document): Response
    {
        $this->authorize('view', $document);

        $user = request()->user();

        $document->load([
            'documentType:id,name',
            'originatingOffice:id,name',
            'creator:id,name',
            'openMovement.toOffice:id,name',
            'lastMovement.toOffice:id,name',
            'movements.actor:id,name',
            'movements.fromOffice:id,name',
            'movements.toOffice:id,name',
            'files.uploader:id,name',
            'signatures.file:id,version,document_id,checksum_sha256',
            'comments.author:id,name',
            // DocumentCommentPolicy::view() reads $comment->document, which
            // lazy-loaded once per comment before this was here.
            'comments.document',
            'routeStops.office:id,name',
        ]);

        return Inertia::render('documents/show', [
            'document' => $this->presenter->detail($document, $user),
            'timeline' => $this->presenter->timeline($document->movements),
            'longestStage' => $this->presenter->longestStage($document->movements),
            'officeRollup' => $this->presenter->officeRollup($document->movements),
            'files' => $this->presenter->files($document->files),
            'signatures' => $this->presenter->signatures(
                $document->signatures,
                (int) $document->files->max('version'),
            ),
            'comments' => $this->presenter->comments($document->comments, $user),
            /*
             * Everywhere the folder can be sent, minus where it already is.
             *
             * Read through the relation's QUERY rather than the loaded model:
             * openMovement is null on a completed or rejected document, and
             * `$document->openMovement->to_office_id` warned and fell through
             * to 0 for those -- right answer, by luck rather than by design.
             */
            'offices' => Office::query()->active()->ordered()
                ->whereKeyNot($document->openMovement()->value('to_office_id') ?? 0)
                ->get(['id', 'name']),
        ]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function priorityOptions(): array
    {
        return array_map(
            static fn (DocumentPriority $priority) => [
                'value' => $priority->value,
                'label' => $priority->label(),
            ],
            DocumentPriority::cases(),
        );
    }
}
