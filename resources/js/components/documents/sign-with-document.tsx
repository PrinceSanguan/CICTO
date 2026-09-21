import { Maximize2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { DocumentViewerNotice } from '@/components/documents/document-viewer';
import { PdfSignaturePlacer } from '@/components/documents/pdf-signature-placer';
import { SignaturePad } from '@/components/documents/signature-pad';
import type { SignatureCaptureMethod } from '@/components/documents/signature-pad';
import { Button } from '@/components/ui/button';
import type { StampPlacement } from '@/lib/pdf-stamp';
import documents from '@/routes/documents';
import type { DocumentFileItem } from '@/types';

/**
 * Read it, put your signature on it, and sign.
 *
 * §15 says a signature binds to one exact file version. Until 2026-09-20 the
 * page said so in a sentence and put the version behind a button that opened a
 * dialog OVER the pad, so the only way to sign was to close the thing you were
 * supposed to be reading. Then the client asked for the mark to land on the
 * paper the way it would in an office, and the panel became this.
 *
 * TWO DIFFERENT VIEWERS, chosen by what the version is:
 *
 *  - A PDF gets PdfSignaturePlacer, which draws the pages into canvases so a
 *    click has coordinates. That is the only way to know where somebody put
 *    their signature.
 *  - SO DOES EVERYTHING ELSE, since 2026-09-21. A .docx, .xlsx, PNG or JPEG
 *    is rendered to a PDF by the signable endpoint and placed on in exactly
 *    the same way. For those the signed copy is a PDF RENDITION and the
 *    original stays as its own version -- SignablePdf says why, and the
 *    notice under the pad says so to the signer.
 *
 * `@container` rather than viewport breakpoints, the same way
 * document-tracking.tsx does it: this panel is rendered both full width and
 * inside the narrower Forward column, and what decides whether the file can
 * sit beside the pad is how much room the PANEL got, not how wide the screen
 * is. Below @4xl they stack, file first -- read, then sign, in reading order.
 */
export function SignWithDocument({
    documentId,
    file,
    padKey,
    disabled = false,
    onChange,
    onOpenFullScreen,
    placement,
    onPlacementChange,
    onBytes,
    signaturePng,
    stampError,
    notice,
    children,
}: {
    documentId: number;

    /** The version a signature made right now would bind to. */
    file: DocumentFileItem | null;

    /** Bumped by the caller to remount the pad after a successful sign. */
    padKey?: number;

    disabled?: boolean;
    onChange: (dataUrl: string | null, method: SignatureCaptureMethod) => void;

    /** Opens the existing full-size dialog, for a long document. */
    onOpenFullScreen?: () => void;

    /** Where the mark sits on the page, from useSignatureStamp. */
    placement: StampPlacement | null;
    onPlacementChange: (placement: StampPlacement | null) => void;
    onBytes: (bytes: Uint8Array | null) => void;

    /** The mark itself, so it can be shown in position on the page. */
    signaturePng: string | null;

    /** A stamping failure raised by the caller's submit handler. */
    stampError?: string | null;

    /** The "what signing means" sentence. It differs per purpose. */
    notice: ReactNode;

    /**
     * Submit button and validation errors, when the panel owns them.
     *
     * Omitted inside the Forward panel, where the surrounding action form's
     * own Send button is what posts the signature along with the movement.
     */
    children?: ReactNode;
}) {
    const usable = file !== null && !file.is_purged;
    const previewable = usable && file.is_previewable;

    /*
     * EVERY readable type can be signed ON, since 2026-09-21.
     *
     * It used to be PDFs only, because the placer needs pages to point at and
     * a .docx has none a browser can address -- so Word and Excel could be
     * read here and not signed, which is what the client reported. The
     * signable endpoint answers with a PDF for all of them: the file itself
     * when it already is one, a rendition when it is not.
     */
    const source =
        previewable && file !== null
            ? documents.files.signable.url({
                  document: documentId,
                  file: file.id,
              })
            : null;

    return (
        <div className="@container">
            <div className="grid gap-4 @4xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="min-w-0 text-xs text-muted-foreground">
                            {file === null ? (
                                'Nothing attached'
                            ) : (
                                <>
                                    You are signing{' '}
                                    <span className="font-medium break-all text-navy">
                                        {file.original_name}
                                    </span>{' '}
                                    · v{file.version}
                                </>
                            )}
                        </p>

                        {previewable && onOpenFullScreen && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={onOpenFullScreen}
                            >
                                <Maximize2 className="size-4" />
                                Full screen
                            </Button>
                        )}
                    </div>

                    {source !== null ? (
                        <PdfSignaturePlacer
                            source={source}
                            signaturePng={signaturePng}
                            placement={placement}
                            onPlacementChange={onPlacementChange}
                            onBytes={onBytes}
                            disabled={disabled}
                        />
                    ) : (
                        <DocumentViewerNotice
                            documentId={documentId}
                            file={file}
                            height="h-[26rem] @4xl:h-[32rem]"
                        />
                    )}
                </div>

                <div className="space-y-3">
                    <SignaturePad
                        key={padKey}
                        disabled={disabled}
                        onChange={onChange}
                        height="h-36 @4xl:h-64"
                    />

                    {/*
                        The one instruction that is not obvious from looking at
                        the panel. Everything else about signing is a button
                        with a label on it; "now click the page" is not.
                    */}
                    {source !== null && (
                        <p
                            aria-live="polite"
                            className="text-xs text-muted-foreground"
                        >
                            {signaturePng === null ? (
                                'Sign or upload your signature above, then click the page where it should appear.'
                            ) : placement === null ? (
                                <span className="font-medium text-navy">
                                    Now click the spot on the document where
                                    your signature should be printed.
                                </span>
                            ) : (
                                <>
                                    Placed on{' '}
                                    <span className="font-medium text-navy">
                                        page {placement.page}
                                    </span>
                                    . Drag it to move, or pull the corner to
                                    resize.
                                </>
                            )}
                        </p>
                    )}

                    {stampError && (
                        <p role="alert" className="text-xs text-danger">
                            {stampError}
                        </p>
                    )}

                    {notice}
                    {children}
                </div>
            </div>
        </div>
    );
}
