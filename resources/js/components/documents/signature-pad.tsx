import { ImageUp } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';

/** Mirrors the image-bearing cases of App\Enums\SignatureMethod. */
export type SignatureCaptureMethod = 'drawn' | 'uploaded';

/**
 * What a signature image may be, before the browser redraws it as a PNG.
 *
 * Deliberately NOT svg: an SVG is a document, not a picture, and the one thing
 * this pad must never do is hand the server markup. The server refuses
 * anything that is not a PNG by its magic bytes anyway -- this is the sentence
 * the signer sees instead of that refusal.
 */
const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

/**
 * The byte ceiling SignDocument enforces (512_000), as base64 plus the
 * `data:image/png;base64,` prefix -- the same arithmetic as the 683008 cap in
 * StoreSignatureRequest, with a little room left under it.
 */
const MAX_DATA_URL_LENGTH = 680_000;

/** Largest box an uploaded signature is redrawn into. Wide, like a signature. */
const MAX_UPLOAD_WIDTH = 900;
const MAX_UPLOAD_HEIGHT = 300;

/** Narrowest a picture may be shrunk to while fitting it under the ceiling. */
const MIN_SHRUNK_WIDTH = 120;

/**
 * §15 signature capture.
 *
 * A plain canvas with pointer events — no npm package. Pointer events cover
 * mouse, stylus and touch in one API, which is the whole reason a library is
 * usually reached for here.
 *
 * Two details that matter on a phone:
 *  - the canvas backing store is sized to devicePixelRatio, or the mark is a
 *    blurry mess on any retina screen;
 *  - `touch-none` stops the browser scrolling the page while someone signs.
 *
 * OR AN IMAGE, since 2026-09-19: the client asked for a signature file to be
 * dragged in rather than drawn every time. The picture is redrawn onto a
 * canvas and sent as a PNG, exactly like a drawn mark, so the server's
 * PNG-only check is unchanged and nothing but the pixels leaves the browser --
 * no EXIF, no file name, no original bytes. It is reported as `uploaded`, not
 * `drawn`, because the signature record says how the mark was made.
 */
export function SignaturePad({
    onChange,
    disabled = false,
    height = 'h-36',
}: {
    onChange: (dataUrl: string | null, method: SignatureCaptureMethod) => void;
    disabled?: boolean;

    /**
     * Tailwind height for the pad.
     *
     * Taller beside a rendered document, where the pad would otherwise be a
     * 144px strip against a 500px page. Static per render on purpose: the
     * backing store is sized once, because resizing a canvas wipes what is
     * drawn on it, so this must not be animated or toggled while someone signs.
     */
    height?: string;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const fileRef = useRef<HTMLInputElement>(null);
    const drawing = useRef(false);

    // Whether the backing store has been sized to a real box yet.
    const sized = useRef(false);

    // Bumped by every new picture and by Clear. A picture still decoding when
    // it is bumped has been superseded, and must not land on the pad after all.
    const loads = useRef(0);
    const [decoding, setDecoding] = useState(false);
    const [hasMark, setHasMark] = useState(false);

    // Set while the pad is showing an uploaded image. Drawing is paused until
    // it is cleared, so a stray stroke cannot be silently discarded (the
    // image is what gets sent) or silently merged into somebody's signature.
    const [uploadedName, setUploadedName] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);

    /*
     * Size the backing store to the display size × DPR -- once, and only once
     * there is a box to measure.
     *
     * The pad usually mounts inside the document page's collapsed <details>,
     * where some engines lay out nothing and report 0×0. Sizing at mount would
     * then leave a zero-pixel canvas that no stroke can mark. A ResizeObserver
     * catches the moment the section opens; after the first real size nothing
     * resizes again, because resizing a canvas wipes what is on it.
     */
    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        const size = () => {
            const rect = canvas.getBoundingClientRect();

            if (sized.current || rect.width === 0 || rect.height === 0) {
                return;
            }

            const ctx = canvas.getContext('2d');

            if (!ctx) {
                return;
            }

            sized.current = true;

            const ratio = window.devicePixelRatio || 1;

            canvas.width = Math.floor(rect.width * ratio);
            canvas.height = Math.floor(rect.height * ratio);

            ctx.scale(ratio, ratio);
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#111827';
        };

        size();

        const observer = new ResizeObserver(size);
        observer.observe(canvas);

        return () => observer.disconnect();
    }, []);

    const pointAt = (event: React.PointerEvent<HTMLCanvasElement>) => {
        const rect = event.currentTarget.getBoundingClientRect();

        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };

    const start = (event: React.PointerEvent<HTMLCanvasElement>) => {
        if (disabled || decoding || uploadedName !== null) {
            return;
        }

        const ctx = canvasRef.current?.getContext('2d');

        if (!ctx) {
            return;
        }

        // Capture keeps strokes going if the finger leaves the canvas.
        event.currentTarget.setPointerCapture(event.pointerId);
        drawing.current = true;

        const { x, y } = pointAt(event);
        ctx.beginPath();
        ctx.moveTo(x, y);
    };

    const move = (event: React.PointerEvent<HTMLCanvasElement>) => {
        if (!drawing.current) {
            return;
        }

        const ctx = canvasRef.current?.getContext('2d');

        if (!ctx) {
            return;
        }

        const { x, y } = pointAt(event);
        ctx.lineTo(x, y);
        ctx.stroke();
        setHasMark(true);
    };

    const end = useCallback(() => {
        if (!drawing.current) {
            return;
        }

        drawing.current = false;

        const canvas = canvasRef.current;

        if (canvas) {
            onChange(canvas.toDataURL('image/png'), 'drawn');
        }
    }, [onChange]);

    /** Wipe the visible pad. The backing store is in device pixels. */
    const wipe = () => {
        const canvas = canvasRef.current;
        const ctx = canvas?.getContext('2d');

        if (!canvas || !ctx) {
            return;
        }

        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.restore();
    };

    const clear = () => {
        // Anything still decoding is now unwanted.
        loads.current += 1;
        setDecoding(false);

        wipe();
        setHasMark(false);
        setUploadedName(null);
        setUploadError(null);

        if (fileRef.current) {
            fileRef.current.value = '';
        }

        onChange(null, 'drawn');
    };

    const acceptImage = async (file: File | undefined) => {
        if (!file || disabled) {
            return;
        }

        setUploadError(null);

        if (!IMAGE_TYPES.includes(file.type)) {
            setUploadError(
                'Use a PNG, JPG or WebP picture of your signature. Other kinds of file cannot be used.',
            );

            return;
        }

        const load = ++loads.current;
        let picture: Picture | null = null;

        setDecoding(true);

        try {
            picture = await openPicture(file);

            // Cleared, or replaced by another picture, while this one decoded.
            if (load !== loads.current) {
                return;
            }

            const encoded = encodeWithinLimit(picture);

            if (encoded === null) {
                setUploadError(
                    'That picture is too detailed to use as a signature. Crop it closer to the signature, or use a smaller image.',
                );

                return;
            }

            // The PROCESSED canvas, not the original picture: the pad has to
            // show what will actually be sent, paper knocked out and all, or
            // the first time anybody sees the real mark is on the signed page.
            showOnPad(encoded.canvas);
            setHasMark(true);
            setUploadedName(file.name);
            onChange(encoded.dataUrl, 'uploaded');
        } catch {
            // Undecodable, or too big for the browser to redraw at all.
            if (load === loads.current) {
                setUploadError(
                    'That file could not be opened as a picture. Try saving it again as a PNG or JPG.',
                );
            }
        } finally {
            picture?.release();

            if (load === loads.current) {
                setDecoding(false);
            }
        }
    };

    /** Draw the prepared mark onto the visible pad, fitted inside it, centred. */
    const showOnPad = (source: HTMLCanvasElement) => {
        const canvas = canvasRef.current;
        const ctx = canvas?.getContext('2d');

        if (!canvas || !ctx) {
            return;
        }

        wipe();

        // The context is already scaled to CSS pixels, so fit in those.
        const rect = canvas.getBoundingClientRect();
        const padding = 8;
        const scale = Math.min(
            (rect.width - padding * 2) / source.width,
            (rect.height - padding * 2) / source.height,
            1,
        );
        const width = source.width * scale;
        const height = source.height * scale;

        ctx.drawImage(
            source,
            (rect.width - width) / 2,
            (rect.height - height) / 2,
            width,
            height,
        );
    };

    return (
        <div className="space-y-2">
            {/*
                preventDefault even while disabled: a file dropped here that
                the pad does not take must not fall through to the browser,
                which would open the image in place of the page and lose
                whatever else the form held.
            */}
            <div
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(!disabled);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragging(false);

                    if (!disabled) {
                        void acceptImage(event.dataTransfer.files[0]);
                    }
                }}
                className="relative"
            >
                <canvas
                    ref={canvasRef}
                    onPointerDown={start}
                    onPointerMove={move}
                    onPointerUp={end}
                    onPointerLeave={end}
                    onPointerCancel={end}
                    aria-label="Signature area. Draw your signature, or drop an image of it here."
                    className={`${height} w-full touch-none rounded-md border border-dashed bg-white transition dark:bg-neutral-100 ${
                        dragging ? 'border-2 border-brand bg-[#EEF4FD]' : ''
                    }`}
                />

                {dragging && (
                    <p className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm font-bold text-navy">
                        Drop your signature image here
                    </p>
                )}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2">
                {/*
                    A polite live region, so a screen-reader user hears that the
                    picture was taken -- the only other sign is on the canvas.
                */}
                <p
                    aria-live="polite"
                    className="min-w-0 flex-1 text-xs text-muted-foreground"
                >
                    {decoding ? (
                        'Opening the picture…'
                    ) : uploadedName !== null ? (
                        <>
                            Using{' '}
                            <span className="font-medium break-all text-navy">
                                {uploadedName}
                            </span>
                            . Press Clear to draw instead.
                        </>
                    ) : (
                        'Sign above using a finger, stylus or mouse — or drag and drop an image of your signature.'
                    )}
                </p>

                <div className="flex shrink-0 items-center gap-1">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={disabled}
                        onClick={() => fileRef.current?.click()}
                    >
                        <ImageUp className="size-4" />
                        Upload image
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={clear}
                        disabled={disabled || !hasMark}
                    >
                        Clear
                    </Button>
                </div>

                <input
                    ref={fileRef}
                    type="file"
                    accept={IMAGE_TYPES.join(',')}
                    className="sr-only"
                    tabIndex={-1}
                    aria-hidden="true"
                    onChange={(event) => {
                        void acceptImage(event.target.files?.[0]);

                        // Picking the same file again must still fire.
                        event.target.value = '';
                    }}
                />
            </div>

            {uploadError && (
                <p role="alert" className="text-xs text-danger">
                    {uploadError}
                </p>
            )}
        </div>
    );
}

/** A decoded picture, whichever way the browser could open it. */
type Picture = {
    source: CanvasImageSource;
    width: number;
    height: number;
    release: () => void;
};

/**
 * createImageBitmap where it exists and works; an <img> where it does not.
 *
 * No `imageOrientation` option: `from-image` -- turning a sideways phone photo
 * upright from its EXIF flag -- is the default, and naming it explicitly makes
 * the whole call throw on engines that only know the older values. An <img>
 * applies the EXIF flag by default too.
 */
async function openPicture(file: File): Promise<Picture> {
    if (typeof createImageBitmap === 'function') {
        try {
            const bitmap = await createImageBitmap(file);

            return {
                source: bitmap,
                width: bitmap.width,
                height: bitmap.height,
                release: () => bitmap.close(),
            };
        } catch {
            // Fall through to the <img> path below.
        }
    }

    const url = URL.createObjectURL(file);

    try {
        const image = new Image();
        image.src = url;
        await image.decode();

        return {
            source: image,
            width: image.naturalWidth,
            height: image.naturalHeight,
            release: () => URL.revokeObjectURL(url),
        };
    } catch (error) {
        URL.revokeObjectURL(url);

        throw error;
    }
}

/**
 * Redraw the picture as a PNG small enough for the server to accept.
 *
 * Starts at the largest box a signature needs -- never enlarging a small one
 * -- and shrinks only while the PNG is still over the byte ceiling. A clean
 * scan fits first time; a noisy phone photo of a signature on paper may take a
 * step or two. Null only when shrinking to stay under the ceiling would leave
 * it too small to read, which only an image that is mostly noise reaches. A
 * picture that is simply small is used as it is.
 */
function encodeWithinLimit(
    picture: Picture,
): { dataUrl: string; canvas: HTMLCanvasElement } | null {
    let scale = Math.min(
        MAX_UPLOAD_WIDTH / picture.width,
        MAX_UPLOAD_HEIGHT / picture.height,
        1,
    );

    for (let attempt = 0; attempt < 8; attempt++) {
        const width = Math.max(1, Math.round(picture.width * scale));
        const height = Math.max(1, Math.round(picture.height * scale));

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return null;
        }

        ctx.drawImage(picture.source, 0, 0, width, height);
        dropThePaper(ctx, width, height);

        const dataUrl = canvas.toDataURL('image/png');

        if (dataUrl.length <= MAX_DATA_URL_LENGTH) {
            return { dataUrl, canvas };
        }

        scale *= 0.75;

        // Too big AND about to become too small to read: give up rather than
        // sign with a smudge.
        if (Math.round(picture.width * scale) < MIN_SHRUNK_WIDTH) {
            return null;
        }
    }

    return null;
}

/**
 * Make the paper behind an uploaded signature transparent.
 *
 * WHY THIS EXISTS. A drawn mark is strokes on an empty canvas, so it is
 * already transparent. A photographed or scanned one is a white rectangle with
 * some ink in the middle, and since 2026-09-20 that rectangle gets printed
 * onto the document -- where it covers whatever it is placed over with a white
 * box. The client asked for a signature that looks like ink on the page; a
 * white patch punched through a table is the opposite of that.
 *
 * WHAT IT WILL NOT TOUCH. An image that already carries transparency is left
 * exactly as it is: somebody who took the trouble to cut their signature out
 * has already answered this question, and second-guessing them could only make
 * it worse.
 *
 * The ramp between the two thresholds matters more than it looks. A hard cut
 * leaves a hard white fringe around every stroke -- the anti-aliased edge
 * pixels, which are neither ink nor paper -- and that fringe is exactly what
 * makes a pasted signature look pasted.
 *
 * Only near-WHITE goes. A signature on cream or pale blue paper keeps its
 * background rather than being eaten away at a threshold nobody chose, which
 * is the visible, fixable failure; silently dissolving light pen strokes is
 * the invisible one.
 */
function dropThePaper(
    ctx: CanvasRenderingContext2D,
    width: number,
    height: number,
): void {
    /** At or above this, a pixel is paper. */
    const PAPER = 244;

    /** Below this, a pixel is ink and is never touched. */
    const INK = 208;

    let image: ImageData;

    try {
        image = ctx.getImageData(0, 0, width, height);
    } catch {
        // A tainted canvas. Cannot happen for a locally chosen file, but a
        // signature that is merely opaque beats no signature at all.
        return;
    }

    const pixels = image.data;

    // Already cut out? Then it is not ours to re-cut.
    for (let i = 3; i < pixels.length; i += 4) {
        if (pixels[i] < 250) {
            return;
        }
    }

    for (let i = 0; i < pixels.length; i += 4) {
        // Rec. 601 luma: a yellowed scan reads as paper, which it is.
        const luma =
            0.299 * pixels[i] + 0.587 * pixels[i + 1] + 0.114 * pixels[i + 2];

        if (luma >= PAPER) {
            pixels[i + 3] = 0;
        } else if (luma > INK) {
            pixels[i + 3] = Math.round(
                pixels[i + 3] * ((PAPER - luma) / (PAPER - INK)),
            );
        }
    }

    ctx.putImageData(image, 0, 0);
}
