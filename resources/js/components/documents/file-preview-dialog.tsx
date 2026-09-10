import { Download } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import documents from '@/routes/documents';
import type { DocumentFileItem } from '@/types';

/**
 * Reading a version on screen, rather than downloading it and hunting through
 * a Downloads folder.
 *
 * §15 is the reason this matters more than convenience: a signature binds to
 * one exact file version, so the person signing should be looking at the bytes
 * that are about to be hashed. The dialog names the version for that reason.
 *
 * Only PDF, PNG and JPEG arrive here — DocumentFile::PREVIEWABLE is a closed
 * allowlist and the endpoint refuses everything else, so `is_previewable` is
 * checked before a button is ever offered. Word and Excel uploads are accepted
 * by the system but have no browser viewer; those get the download button and a
 * sentence saying so, never a blank frame.
 */
export function FilePreviewDialog({
    documentId,
    file,
    onOpenChange,
}: {
    documentId: number;
    file: DocumentFileItem | null;
    onOpenChange: (open: boolean) => void;
}) {
    if (file === null) {
        return null;
    }

    const source = documents.files.preview.url({
        document: documentId,
        file: file.id,
    });

    const downloadUrl = documents.files.download.url({
        document: documentId,
        file: file.id,
    });

    const isImage = file.mime_type.startsWith('image/');

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="flex h-[85vh] max-w-[calc(100%-2rem)] flex-col gap-3 sm:max-w-5xl">
                <DialogHeader className="pr-8">
                    <DialogTitle className="truncate text-base">
                        {file.original_name}
                    </DialogTitle>
                    <DialogDescription>
                        Version {file.version} · {file.size}
                        {file.uploaded_by &&
                            ` · uploaded by ${file.uploaded_by}`}
                    </DialogDescription>
                </DialogHeader>

                {/*
                    An iframe rather than <object>/<embed>: the app's CSP sets
                    object-src 'none', so those two render nothing. frame-src
                    falls through to default-src 'self', and this is same-origin,
                    so the frame is allowed.
                */}
                <div className="min-h-0 flex-1 overflow-auto rounded-md border bg-muted">
                    {isImage ? (
                        <img
                            src={source}
                            alt={`Version ${file.version} of ${file.original_name}`}
                            className="mx-auto block max-w-full"
                        />
                    ) : (
                        <iframe
                            src={source}
                            title={`Version ${file.version} of ${file.original_name}`}
                            className="h-full w-full"
                        />
                    )}
                </div>

                <p className="text-xs text-muted-foreground">
                    Can&rsquo;t see it? Some browsers refuse to display PDFs
                    inline —{' '}
                    <a
                        href={downloadUrl}
                        className="inline-flex items-center gap-1 underline"
                    >
                        <Download className="size-3" />
                        download this version
                    </a>{' '}
                    instead.
                </p>
            </DialogContent>
        </Dialog>
    );
}
