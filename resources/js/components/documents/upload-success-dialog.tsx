import { router } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { FlashUpload } from '@/types/ui';

/**
 * "Upload successful", for every request that stored a document file:
 * registering a document, a corrected version, and the corrected copy sent
 * with a Return or Resubmit. The client asked for it on 2026-09-17, alongside
 * the pop-up that refuses a wrong file.
 *
 * Mounted once in `withApp`, beside <Toaster />, and fed by the same flash
 * event -- not by the form that uploaded. Registering redirects to the new
 * document's page, which unmounts the create form before the answer could be
 * shown from there. Being outside the Inertia page context is also why this
 * listens on router.on('flash') rather than calling usePage() (see
 * useFlashToast).
 */
export function UploadSuccessDialog() {
    const [upload, setUpload] = useState<FlashUpload | null>(null);

    // Separate from `upload` so closing keeps the text on screen while the
    // dialog animates out.
    const [open, setOpen] = useState(false);

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash as
                { upload?: FlashUpload } | undefined;

            if (!flash?.upload) {
                return;
            }

            setUpload(flash.upload);
            setOpen(true);
        });
    }, []);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-emerald-700">
                        <CircleCheck aria-hidden="true" className="size-5" />
                        Upload successful
                    </DialogTitle>
                    <DialogDescription className="text-[15px] text-navy">
                        <span className="block font-bold break-all">
                            {upload?.fileName}
                        </span>
                        {upload?.message}
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button onClick={() => setOpen(false)}>OK</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
