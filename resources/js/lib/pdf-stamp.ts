/**
 * Printing a signature onto a PDF page, in the browser.
 *
 * WHY HERE AND NOT ON THE SERVER. No free PHP library can reliably rewrite a
 * PDF 1.5+ file — the cross-reference and object streams most modern PDFs use
 * need the commercial FPDI parser — so server-side stamping would work on
 * dompdf output and quietly corrupt a Word export. pdf-lib reads all of them.
 * App\Actions\Documents\SignDocument carries the rest of that argument,
 * including what the app does instead of trusting these bytes.
 *
 * pdf-lib is imported dynamically. It is ~400 KB that only a signer needs, and
 * loading it into every page view of the app would be paid by the many for the
 * few.
 */

/**
 * Where the signer put the mark, as fractions of the page AS DISPLAYED —
 * origin top-left, y downwards, after the page's own /Rotate is applied.
 *
 * Fractions rather than points so the record survives a viewer that reports
 * the page box differently, and so the same numbers describe A4 and Legal.
 * Mirrors the stamp_* columns on document_signatures.
 */
export type StampPlacement = {
    /** 1-based, matching what the viewer showed. */
    page: number;
    x: number;
    y: number;
    width: number;
    height: number;
};

/**
 * Draw `png` onto `source` at `placement`, and hand back the new PDF's bytes.
 *
 * Throws with a sentence meant for the signer, not a stack trace: this runs
 * behind a button labelled "Sign document", and every failure here has to be
 * something a records clerk can act on.
 */
export async function stampSignatureOntoPdf({
    source,
    png,
    placement,
}: {
    source: ArrayBuffer | Uint8Array;
    /** A `data:image/png;base64,...` URL, exactly what the pad produces. */
    png: string;
    placement: StampPlacement;
}): Promise<Uint8Array> {
    const { PDFDocument, degrees } = await import('pdf-lib');

    let pdf;

    try {
        // ignoreEncryption: plenty of government PDFs carry an owner password
        // that permits printing and forbids nothing we do. Refusing to open
        // them would fail the common case to be strict about the rare one.
        pdf = await PDFDocument.load(source, { ignoreEncryption: true });
    } catch {
        throw new Error(
            'This PDF could not be opened for signing. Download it, re-save it as a PDF, and upload that as a new version.',
        );
    }

    const page = pdf.getPages()[placement.page - 1];

    if (!page) {
        throw new Error(
            'The page you placed your signature on is no longer in this document. Reload the page and sign again.',
        );
    }

    /*
     * The CROP box, not the media box. pdf.js lays out its viewport on the
     * crop box, so that is the rectangle the signer's fractions refer to. On
     * the overwhelming majority of files the two are identical; where they are
     * not, using the media box would put the mark a visible distance off.
     *
     * Its origin is carried through too. A crop box like [10 10 622 802] means
     * user-space (0,0) is outside the visible page, and ignoring the offset
     * shifts every stamp by that much.
     */
    const box = page.getCropBox();
    const rotation = ((page.getRotation().angle % 360) + 360) % 360;
    const quarterTurned = rotation === 90 || rotation === 270;

    // The page as the signer saw it.
    const viewWidth = quarterTurned ? box.height : box.width;
    const viewHeight = quarterTurned ? box.width : box.height;

    const left = placement.x * viewWidth;
    const top = placement.y * viewHeight;
    const width = placement.width * viewWidth;
    const height = placement.height * viewHeight;

    const image = await pdf.embedPng(png);

    /*
     * View space (origin top-left, y down, rotation applied) to PDF user space
     * (origin bottom-left, y up, rotation NOT applied).
     *
     * drawImage puts the image's bottom-left corner at (x, y) and then turns
     * it counter-clockwise about that same corner, so each case below is the
     * anchor that leaves the turned image covering the rectangle the signer
     * drew. Turning the image by the page's own rotation is what keeps the
     * signature upright to the reader rather than upright to the file.
     */
    const anchor = ((): { x: number; y: number } => {
        switch (rotation) {
            case 90:
                return { x: top + height, y: left };
            case 180:
                return { x: box.width - left, y: top + height };
            case 270:
                return { x: box.width - top - height, y: box.height - left };
            default:
                return { x: left, y: box.height - top - height };
        }
    })();

    page.drawImage(image, {
        x: box.x + anchor.x,
        y: box.y + anchor.y,
        width,
        height,
        rotate: degrees(rotation),
    });

    return pdf.save();
}

/**
 * The natural width-to-height ratio of a PNG data URL.
 *
 * Used to give a freshly placed signature the shape it was drawn in, rather
 * than the shape of whatever box it landed in. Falls back to 3:1 — a wide,
 * signature-ish default — for an image the browser will not decode, because a
 * usable default beats blocking the signature over a measurement.
 */
export async function pngAspectRatio(dataUrl: string): Promise<number> {
    try {
        const image = new Image();
        image.src = dataUrl;
        await image.decode();

        return image.naturalHeight > 0
            ? image.naturalWidth / image.naturalHeight
            : 3;
    } catch {
        return 3;
    }
}
