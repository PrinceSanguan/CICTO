<?php

namespace App\Http\Controllers;

use App\Actions\Documents\BroadcastDocument;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Broadcast to ALL offices" -- see BroadcastDocument.
 */
class DocumentBroadcastController extends Controller
{
    public function store(Request $request, Document $document, BroadcastDocument $broadcast): RedirectResponse
    {
        $this->authorize('broadcast', $document);

        try {
            $notified = $broadcast->handle($document, $request->user());
        } catch (\LogicException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$document->control_number} was sent to every office. "
                .($notified === 1 ? '1 person was' : "{$notified} people were").' notified.',
        ]);
    }
}
