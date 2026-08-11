<?php

namespace App\Http\Controllers;

use App\Actions\Documents\ArchiveDocument;
use App\Models\Document;
use App\Support\Presenters\DocumentPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §16 Archive Management.
 *
 * A read view plus two verbs over columns that already exist -- Phase 4 adds no
 * schema. Archived documents are FILED, never deleted: the row, its files, its
 * signatures and its whole movement history stay exactly where they were, and
 * `DocumentBuilder::active()` is what keeps them out of the working lists.
 */
class ArchiveController extends Controller
{
    public function __construct(private readonly DocumentPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $search = trim((string) $request->string('q'));

        $documents = Document::query()
            ->visibleTo($user)
            ->archived()
            ->with([
                'documentType:id,name',
                'archivedBy:id,name',
                'openMovement.toOffice:id,name',
            ])
            ->when($search !== '', fn ($query) => $query->search($search))
            ->orderByDesc('documents.archived_at')
            // Deterministic tiebreak: without it, rows sharing a timestamp can
            // reappear on page 2 while others never appear at all.
            ->orderByDesc('documents.id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Document $document) => [
                ...$this->presenter->listItem($document),
                'archived_at' => $document->archived_at?->toIso8601String(),
                'archived_by' => $document->archivedBy?->name,
                'archive_reason' => $document->archive_reason,
                'can' => [
                    'restore' => $request->user()->can('restore', $document),
                ],
            ]);

        return Inertia::render('documents/archive', [
            'documents' => $documents,
            'filters' => ['q' => $search],
        ]);
    }

    public function store(Request $request, Document $document, ArchiveDocument $archive): RedirectResponse
    {
        $this->authorize('archive', $document);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $archive->archive($document, $request->user(), $validated['reason'] ?? null);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$document->control_number} has been archived.",
        ]);
    }

    public function destroy(Request $request, Document $document, ArchiveDocument $archive): RedirectResponse
    {
        $this->authorize('restore', $document);

        $archive->restore($document, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$document->control_number} has been restored to the active list.",
        ]);
    }
}
