import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronLeft, LockKeyhole } from 'lucide-react';
import { useEffect, useState } from 'react';
import { UPLOAD_DIALOG_CLOSED } from '@/components/documents/upload-success-dialog';
import { minutesLabel } from '@/components/security-pin/pin-input';
import { SecurityPinDialog } from '@/components/security-pin/security-pin-dialog';
import { Button } from '@/components/ui/button';
import documents from '@/routes/documents';

type Props = {
    document: { id: number; control_number: string };
    hasPin: boolean;
    attemptsLeft: number;
    maxAttempts: number;
    idleMinutes: number;
    /** Flashed by SecurityPinController::lock. */
    lockedBecause: 'idle' | 'manual' | 'action' | null;
};

/**
 * View Documents, before the Security PIN (client request, 2026-09-25).
 *
 * DocumentController::show renders this instead of the document while the
 * session is locked, and sends nothing but the control number -- there is no
 * blurred document underneath to un-blur. The PIN pop-up opens by itself; if
 * it is closed, the card offers it again.
 */
export default function DocumentLocked({
    document,
    hasPin,
    attemptsLeft,
    maxAttempts,
    idleMinutes,
    lockedBecause,
}: Props) {
    /*
        Filing a document lands here when the PIN has not been entered yet,
        and the "Upload successful" dialog opens at the same moment. One
        dialog at a time: the PIN waits until that one is closed, instead of
        stacking under it with two dark backdrops (found in QA, 2026-09-25).
    */
    const flash = usePage().flash as { upload?: unknown } | undefined;
    const uploading = Boolean(flash?.upload);
    const [open, setOpen] = useState(!uploading);

    useEffect(() => {
        if (!uploading) {
            return;
        }

        const openPin = () => setOpen(true);

        window.addEventListener(UPLOAD_DIALOG_CLOSED, openPin, { once: true });

        return () => window.removeEventListener(UPLOAD_DIALOG_CLOSED, openPin);
    }, [uploading]);

    return (
        <>
            <Head title={`${document.control_number} — Enter PIN`} />

            <Link
                href={documents.index()}
                className="inline-flex items-center gap-1 text-sm font-bold text-white/90 transition hover:text-white"
            >
                <ChevronLeft className="size-4" />
                Back to Track Document
            </Link>

            <section className="mt-4 flex flex-col items-center rounded-xl bg-white px-6 py-14 text-center shadow-xl sm:px-8">
                <span className="flex size-16 items-center justify-center rounded-full bg-[#E8F0FB] text-[#3B72C4]">
                    <LockKeyhole aria-hidden="true" className="size-8" />
                </span>

                <h1 className="mt-5 text-2xl font-bold text-navy">
                    View Documents
                </h1>
                <p className="mt-1 text-sm font-bold text-link">
                    {document.control_number}
                </p>

                {lockedBecause === 'idle' && (
                    <p
                        role="status"
                        className="mt-5 rounded-lg bg-[#FFF4E5] px-4 py-2 text-sm text-[#8A4B00]"
                    >
                        Locked after {minutesLabel(idleMinutes)} without
                        activity.
                    </p>
                )}

                {lockedBecause === 'action' && (
                    <p
                        role="alert"
                        className="mt-5 max-w-md rounded-lg bg-[#FDECEA] px-4 py-2 text-sm text-[#8A1C12]"
                    >
                        Nothing was saved — this document had locked. Enter your
                        PIN, then do it again.
                    </p>
                )}

                <p className="mt-5 max-w-md text-sm text-copy">
                    {hasPin
                        ? 'This document is protected by your Security PIN. Enter it to view the document.'
                        : 'Documents are protected by a Security PIN that only you know. Create yours to view this document.'}
                </p>

                <Button className="mt-6" onClick={() => setOpen(true)}>
                    {hasPin ? 'Enter PIN' : 'Create PIN'}
                </Button>
            </section>

            <SecurityPinDialog
                open={open}
                onOpenChange={setOpen}
                hasPin={hasPin}
                attemptsLeft={attemptsLeft}
                maxAttempts={maxAttempts}
                idleMinutes={idleMinutes}
            />
        </>
    );
}
