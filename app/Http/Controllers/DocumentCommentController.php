<?php

namespace App\Http\Controllers;

use App\Http\Requests\Documents\StoreDocumentCommentRequest;
use App\Models\Document;
use App\Models\DocumentComment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * §16 comments and collaboration.
 *
 * Only plain comments are writable here. Decision remarks are created by
 * TransitionDocument as part of the ledger write, and have no edit route at
 * all -- the movement holds an immutable copy of the same text.
 */
class DocumentCommentController extends Controller
{
    public function store(StoreDocumentCommentRequest $request, Document $document): RedirectResponse
    {
        DocumentComment::create([
            'document_id' => $document->id,
            'user_id' => $request->user()->id,
            'parent_id' => $request->integer('parent_id') ?: null,
            'context' => DocumentComment::CONTEXT_COMMENT,
            'body' => $request->string('body')->value(),
            'is_internal' => $request->boolean('is_internal'),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Comment added.']);
    }

    public function update(Request $request, Document $document, DocumentComment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $comment->forceFill([
            'body' => $validated['body'],
            'edited_at' => now(),
        ])->save();

        return back()->with('toast', ['type' => 'success', 'message' => 'Comment updated.']);
    }

    public function destroy(Document $document, DocumentComment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Comment removed.']);
    }
}
