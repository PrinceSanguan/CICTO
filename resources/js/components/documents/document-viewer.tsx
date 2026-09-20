import { Download, FileWarning } from 'lucide-react';
import documents from '@/routes/documents';
import type { DocumentFileItem } from '@/types';

/**
 * One version of an attachment, rendered in place.
 *
 * Pulled out of FilePreviewDialog so the signing panel can show the same file
 * the same way without opening a modal over the pad -- §15 binds a signature
 * to one exact version, and the client (2026-09-20) asked for that version to
 * be on screen WHILE it is signed rather than behind a dialog that has to be
 * closed first.
 *
 * WHAT ARRIVES IN THE FRAME is either the file's own bytes -- PDF, PNG, JPEG,
 * the closed allowlist in DocumentFile::PREVIEWABLE -- or, since 2026-09-20,
 * HTML the server produced from a .docx or .xlsx. The component cannot tell
 * the two apart and does not need to: both are things an iframe renders, and
 * `is_previewable` is the one question it asks.
 *
 * Only the older binary .doc and .xls have no viewer left. Those get
 * `DocumentViewerNotice` and a download button -- never a frame that renders
 * blank and looks broken.
 */
export function DocumentViewer({
    documentId,
    file,
    height = 'h-[32rem]',
    lazy = false,
}: {
    documentId: number;
    file: DocumentFileItem;

    /** Tailwind height for the frame. The caller knows how much room it has. */
    height?: string;

    /**
     * Defer the request until the frame is near the viewport.
     *
     * Not a performance tweak. A preview is an audited read
     * (SecurityEventType::FilePreviewed, DocumentFileController::preview:100),
     * and a viewer that streams on every page render would write "previewed"
     * for every page load by anyone who happens to be allowed to sign --
     * including the loads where they came to forward it and never scrolled
     * this far. Loading lazily means the log entry is written when the file
     * genuinely arrived on someone's screen, which is what the entry claims.
     */
    lazy?: boolean;
}) {
    const source = documents.files.preview.url({
        document: documentId,
        file: file.id,
    });

    const isImage = file.mime_type.startsWith('image/');
    const loading = lazy ? 'lazy' : 'eager';

    /*
        An iframe rather than <object>/<embed>: the app's CSP sets
        object-src 'none', so those two render nothing. frame-src falls
        through to default-src 'self', and this is same-origin, so the
        frame is allowed.
    */
    return (
        <div
            className={`min-h-0 overflow-auto rounded-md border bg-muted ${height}`}
        >
            {isImage ? (
                <img
                    src={source}
                    loading={loading}
                    alt={`Version ${file.version} of ${file.original_name}`}
                    className="mx-auto block max-w-full"
                />
            ) : (
                <iframe
                    src={source}
                    loading={loading}
                    title={`Version ${file.version} of ${file.original_name}`}
                    className="h-full w-full"
                />
            )}
        </div>
    );
}

/**
 * Why there is no frame, and what to do instead.
 *
 * Three different dead ends land here and they are not the same thing to the
 * person reading: nothing was ever attached, the bytes were purged under the
 * retention policy, or the type has no browser viewer. Only the last one has a
 * download worth offering.
 */
export function DocumentViewerNotice({
    documentId,
    file,
    height = 'h-[32rem]',
}: {
    documentId: number;
    file: DocumentFileItem | null;
    height?: string;
}) {
    const message =
        file === null
            ? 'No file is attached to this document, so there is nothing to read here.'
            : file.is_purged
              ? `Version ${file.version} is no longer stored — it was removed under the retention policy. What you sign is still recorded against it.`
              : /*
                     Reached by fewer types since 2026-09-20: .docx and .xlsx
                     are converted to HTML on the server and render in the
                     frame like anything else. What is left here is the older
                     binary .doc and .xls, which have no reader worth offering.
                 */
                `${file.original_name} cannot be shown in the browser. This is an older Word or Excel format with no viewer here, so download it to read it before you sign.`;

    const downloadable = file !== null && !file.is_purged;

    return (
        <div
            className={`flex flex-col items-center justify-center gap-3 rounded-md border border-dashed bg-muted p-6 text-center ${height}`}
        >
            <FileWarning className="size-6 text-muted-foreground" />
            <p className="max-w-sm text-sm text-muted-foreground">{message}</p>

            {downloadable && (
                <a
                    href={documents.files.download.url({
                        document: documentId,
                        file: file.id,
                    })}
                    className="inline-flex items-center gap-1 text-sm font-medium underline"
                >
                    <Download className="size-4" />
                    Download v{file.version}
                </a>
            )}
        </div>
    );
}
