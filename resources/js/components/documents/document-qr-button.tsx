import { QrCode } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import documents from '@/routes/documents';

/**
 * A document's QR code, one press away from wherever the document is listed.
 *
 * It used to live on the detail page as a "Show QR" toggle that expanded a
 * panel under the Label heading, which meant opening a document to reach it.
 * The client moved it to the list on 2026-08-27: the QR is what a clerk hands
 * to a courier, and wanting it for a row is not the same as wanting to read
 * that row.
 *
 * A dialog rather than an expanding cell. A table row that grows on click
 * reflows every row under it, and the code needs to be big enough to scan off
 * a screen -- ~190px, which is taller than the row it would open in.
 *
 * The <img> lives inside DialogContent on purpose. Radix mounts content only
 * while the dialog is open, so a page of twenty rows makes zero requests for
 * QR SVGs until somebody asks for one, and the one it then makes is cached for
 * the next open.
 */
export function DocumentQrButton({
    id,
    controlNumber,
    className,
}: {
    id: number;
    controlNumber: string;
    className?: string;
}) {
    return (
        <Dialog>
            {/*
                Outlined, not tinted. It shipped as `bg-[#EEF4FD]` -- a very pale
                blue -- and the rows behind it are white, so at a glance the
                control was a faintly-shaded gap beside View rather than a
                button. The client flagged it on 2026-08-27. A 2px #3B72C4 edge
                is what makes it legible on white; the fill can then stay white
                and invert on hover.

                The height is still exactly View's 40px, just counted
                differently: `border-2` (4) + `py-2` (16) + the size-5 icon (20)
                where the tinted version was `py-2.5` (20) + icon (20).
            */}
            <DialogTrigger
                aria-label={`Show QR code for ${controlNumber}`}
                className={cn(
                    'inline-flex items-center justify-center rounded-md border-2 border-[#3B72C4] bg-white px-3 py-2 text-[#3B72C4] transition duration-200 ease-out hover:bg-[#3B72C4] hover:text-white active:scale-[0.97]',
                    className,
                )}
            >
                <QrCode aria-hidden="true" className="size-5" />
            </DialogTrigger>

            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>QR code</DialogTitle>
                    <DialogDescription>
                        Scan this to open the document&rsquo;s tracking page.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col items-center gap-3 pb-2">
                    <img
                        src={documents.qr.url({ document: id })}
                        alt={`QR code for ${controlNumber}`}
                        className="size-48"
                    />
                    <p className="font-mono text-xs text-copy">
                        {controlNumber}
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}
