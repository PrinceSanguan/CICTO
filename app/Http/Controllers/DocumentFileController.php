<?php

namespace App\Http\Controllers;

use App\Actions\Documents\StoreDocumentFile;
use App\Enums\SecurityEventType;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\SecurityEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploads and downloads for document versions.
 *
 * The download route MUST be declared with ->scopeBindings(). Without it,
 * {file} resolves globally rather than within {document}, and an attacker can
 * pair a document they may read with a file id belonging to one they may not --
 * the policy would then authorise against the wrong parent.
 */
class DocumentFileController extends Controller
{
    public function store(Request $request, Document $document, StoreDocumentFile $store): RedirectResponse
    {
        $this->authorize('uploadVersion', $document);

        $validated = $request->validate([
            'file' => [
                'required',
                File::types(config('cicto.uploads.mimes'))
                    ->extensions(config('cicto.uploads.extensions'))
                    ->max((int) config('cicto.uploads.max_size_kb')),
            ],
            'replace_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $store->handle(
            document: $document,
            upload: $request->file('file'),
            uploader: $request->user(),
            movement: $document->openMovement,
            replaceReason: $validated['replace_reason'] ?? null,
        );

        return back()->with('toast', ['type' => 'success', 'message' => "Uploaded as version {$file->version}."]);
    }

    public function download(Request $request, Document $document, DocumentFile $file): StreamedResponse
    {
        $this->authorize('download', $file);

        abort_unless($file->exists(), 410, 'This version is no longer stored.');

        // §21: reads are audited too. They go to security_events, NEVER to
        // document_movements -- the custody timeline records transfers, and
        // filling it with downloads would bury the routing history.
        SecurityEvent::log(
            SecurityEventType::FileDownloaded,
            sprintf(
                '%s downloaded %s v%d.',
                $request->user()->email ?? 'A user',
                $document->control_number,
                $file->version,
            ),
            $request->user(),
            $document->control_number,
        );

        return response()->streamDownload(
            function () use ($file): void {
                $stream = Storage::disk($file->disk)->readStream($file->path);

                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $file->original_name,
            [
                'Content-Type' => $file->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
