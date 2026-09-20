import { useCallback, useState } from 'react';
import type { StampPlacement } from '@/lib/pdf-stamp';
import { stampSignatureOntoPdf } from '@/lib/pdf-stamp';

/**
 * The state behind "print my signature onto the page".
 *
 * A hook rather than state inside SignWithDocument because the two signing
 * forms on the document page both need it and both post it themselves: the
 * standalone Sign form, and the optional release signature carried along by
 * the Forward action. Keeping it here means one copy of the rule about when a
 * stamp is possible instead of two that drift.
 *
 * Stamping happens on SUBMIT, not on every drag. Composing a fresh PDF costs
 * real time on a scanned twenty-page upload, and doing it while somebody nudges
 * the mark half a centimetre would make the drag stutter for no gain — the
 * only bytes that matter are the ones produced by the last position.
 */
export function useSignatureStamp() {
    const [placement, setPlacement] = useState<StampPlacement | null>(null);

    /*
     * The version's bytes, fetched once by the placer and kept for stamping.
     *
     * State rather than a ref on purpose: whether these exist is what decides
     * if the panel can offer stamping at all, and the button label has to
     * change when they arrive.
     */
    const [bytes, setBytes] = useState<Uint8Array | null>(null);

    const [error, setError] = useState<string | null>(null);

    /**
     * The signed copy, or null when this signature is not being stamped.
     *
     * Throws with a signer-facing sentence, so the caller can abandon the
     * submit and show it rather than posting a signature whose stamp silently
     * failed to materialise.
     */
    const buildStampedFile = useCallback(
        async (png: string | null, name: string): Promise<File | null> => {
            if (placement === null || png === null || bytes === null) {
                return null;
            }

            setError(null);

            const stamped = await stampSignatureOntoPdf({
                source: bytes,
                png,
                placement,
            });

            /*
             * Named after the version it came from, not "signed.pdf". The name
             * is what the Attachments list and every download shows, and a
             * register full of identical filenames is a register nobody can
             * read. StoreDocumentFile keeps the extension from this name.
             */
            return new File([stamped as BlobPart], name, {
                type: 'application/pdf',
            });
        },
        [bytes, placement],
    );

    const reset = useCallback(() => {
        setPlacement(null);
        setError(null);
    }, []);

    return {
        placement,
        setPlacement,
        setBytes,
        /** True once the version has been read and really is a stampable PDF. */
        stampable: bytes !== null,
        buildStampedFile,
        error,
        setError,
        reset,
    };
}

/**
 * A stamping failure, as a sentence for the signer.
 *
 * stampSignatureOntoPdf throws messages already written for them; anything
 * else reaching here is a browser that ran out of memory on a large scan, or
 * a bug. Both get the same instruction, because both have the same fix.
 */
export function stampFailure(error: unknown): string {
    return error instanceof Error && error.message !== ''
        ? error.message
        : 'Your signature could not be printed onto the document. Reload the page and try again.';
}
