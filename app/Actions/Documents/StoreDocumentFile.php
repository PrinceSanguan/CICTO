<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Appends a new immutable version to a document.
 *
 * Version numbers are allocated under the same document row lock the workflow
 * uses, so a concurrent upload cannot produce two rows with the same version.
 */
final class StoreDocumentFile
{
    public function handle(
        Document $document,
        UploadedFile $upload,
        User $uploader,
        ?DocumentMovement $movement = null,
        ?string $replaceReason = null,
    ): DocumentFile {
        $checksum = hash_file('sha256', $upload->getRealPath());

        // false means the temporary upload could not be read. Storing it would
        // write an empty checksum, which then dedupes against nothing and makes
        // every signature bound to this version unverifiable.
        if ($checksum === false) {
            throw new RuntimeException('The uploaded file could not be read for hashing.');
        }

        return DB::transaction(function () use ($document, $upload, $uploader, $movement, $replaceReason, $checksum): DocumentFile {
            Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            $highest = (int) DocumentFile::query()
                ->where('document_id', $document->id)
                ->max('version');

            // Re-uploading the CURRENT file is a no-op -- a double-submitted
            // form should not manufacture a version.
            //
            // Re-uploading an OLDER one is not. Someone uploading v1's bytes
            // over v2 is REVERTING, and that has to become v3. Matching against
            // any version at all meant the action returned v1, the current file
            // stayed v2, and the UI still said "New version uploaded" -- the
            // document silently kept the content the user had just replaced.
            $current = DocumentFile::query()
                ->where('document_id', $document->id)
                ->where('version', $highest)
                ->whereNull('purged_at')
                ->first();

            if ($current !== null && hash_equals($current->checksum_sha256, $checksum)) {
                return $current;
            }

            $version = $highest + 1;

            // On-disk names are generated ULIDs, never the client filename --
            // the original is kept as metadata for the download response.
            $extension = mb_strtolower($upload->getClientOriginalExtension());
            $name = (string) Str::ulid().($extension !== '' ? '.'.$extension : '');
            $path = $upload->storeAs("documents/{$document->id}", $name, 'documents');

            return DocumentFile::create([
                'document_id' => $document->id,
                'version' => $version,
                'disk' => 'documents',
                'path' => $path,
                'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                'mime_type' => mb_substr((string) $upload->getMimeType(), 0, 127),
                'size_bytes' => $upload->getSize(),
                'checksum_sha256' => $checksum,
                'uploaded_by_id' => $uploader->id,
                'document_movement_id' => $movement?->id,
                'replace_reason' => $replaceReason,
            ]);
        });
    }
}
