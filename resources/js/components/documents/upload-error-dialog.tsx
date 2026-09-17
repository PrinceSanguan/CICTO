import { FileWarning } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type UploadError = {
    fileName: string | null;
    message: string;
};

/**
 * The pop-up for a file that cannot be uploaded.
 *
 * A pop-up rather than only the red line under the field: the client asked for
 * it on 2026-09-17, and on the corrected-file inputs the line sits inside a
 * long panel where a clerk who has already scrolled to Confirm never sees it.
 * The inline error stays as well, so the reason is still on the page after the
 * pop-up is dismissed.
 *
 * Driven by useUploadGuard, which opens it both when a file is picked and when
 * the server refuses one the browser could not judge (a .txt renamed to .pdf).
 */
export function UploadErrorDialog({
    open,
    error,
    allowed,
    maxLabel,
    onClose,
}: {
    open: boolean;
    error: UploadError | null;
    allowed: string;
    maxLabel: string;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-danger">
                        <FileWarning aria-hidden="true" className="size-5" />
                        File cannot be uploaded
                    </DialogTitle>
                    <DialogDescription className="text-[15px] text-navy">
                        {error?.fileName && (
                            <span className="block font-bold break-all">
                                {error.fileName}
                            </span>
                        )}
                        {error?.message}
                    </DialogDescription>
                </DialogHeader>

                <p className="rounded-md bg-[#F4F7FC] px-3 py-2 text-sm text-copy">
                    Accepted: {allowed}, up to {maxLabel}.
                </p>

                <DialogFooter>
                    <Button onClick={onClose}>OK</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
