import { Download } from 'lucide-react';
import { DocumentViewer } from '@/components/documents/document-viewer';
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
 * Reading a version at full size.
 *
 * Still here after the signing panel learned to render the file inline
 * (2026-09-20): the panel shows the version beside the pad, which is enough
 * for a one-page memo and cramped for a twenty-page ordinance. This is the
 * "make it big" path, reached from the Attachments list and from the Full
 * screen button on the signing panel.
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

    const downloadUrl = documents.files.download.url({
        document: documentId,
        file: file.id,
    });

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

                <DocumentViewer
                    documentId={documentId}
                    file={file}
                    height="min-h-0 flex-1"
                />

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
