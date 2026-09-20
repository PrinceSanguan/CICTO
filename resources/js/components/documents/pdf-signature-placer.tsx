import { useCallback, useEffect, useRef, useState } from 'react';
import type { StampPlacement } from '@/lib/pdf-stamp';
import { pngAspectRatio } from '@/lib/pdf-stamp';

/** How wide a freshly dropped signature is, as a fraction of the page. */
const DEFAULT_WIDTH = 0.26;

/** Never so small it is a smudge, never so wide it covers the page. */
const MIN_WIDTH = 0.06;
const MAX_WIDTH = 0.9;

/** Pages this far outside the scroller are rendered ahead of being scrolled to. */
const PRERENDER_MARGIN = '600px';

type PageBox = { width: number; height: number };

/**
 * The document, rendered page by page, with the signature the signer is about
 * to apply sitting on it where they put it.
 *
 * WHY NOT THE BROWSER'S OWN PDF VIEWER. The `<iframe>` the rest of the app
 * uses is faster, scrolls better and costs nothing — and it is a black box.
 * There is no way to learn where inside it somebody clicked, which is the one
 * thing placing a signature needs. pdf.js draws the pages into canvases this
 * component owns, so a click has coordinates.
 *
 * Everything reported upward is a FRACTION of the page, never a pixel. The
 * canvas is whatever size the column happens to be, on a screen of unknown
 * density, and a signature recorded in screen pixels would land somewhere else
 * on anyone else's monitor.
 *
 * Pages render lazily, and the bytes are fetched exactly once: the same
 * ArrayBuffer is handed to pdf.js to draw and to pdf-lib to stamp, so the file
 * that gets signed is provably the file that was displayed.
 */
export function PdfSignaturePlacer({
    source,
    signaturePng,
    placement,
    onPlacementChange,
    onBytes,
    height = 'h-[26rem] @4xl:h-[32rem]',
    disabled = false,
}: {
    /** Same-origin URL of the version being signed. */
    source: string;

    /** The mark, as a PNG data URL, or null while the pad is empty. */
    signaturePng: string | null;

    placement: StampPlacement | null;
    onPlacementChange: (placement: StampPlacement | null) => void;

    /**
     * The fetched PDF, handed up so the caller can stamp it on submit without
     * downloading it a second time.
     */
    onBytes: (bytes: Uint8Array | null) => void;

    height?: string;
    disabled?: boolean;
}) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const canvasRefs = useRef<Array<HTMLCanvasElement | null>>([]);

    // The loaded pdf.js document. Not state: it is a handle with a destroy()
    // that must be called exactly once, and re-rendering must not replace it.
    const docRef = useRef<{
        numPages: number;
        getPage: (n: number) => Promise<unknown>;
    } | null>(null);
    const rendered = useRef<Set<number>>(new Set());

    const [pages, setPages] = useState<PageBox[]>([]);
    const [visible, setVisible] = useState<Set<number>>(new Set());
    const [scale, setScale] = useState(1);
    const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>(
        'loading',
    );
    const [error, setError] = useState<string | null>(null);
    const [aspect, setAspect] = useState(3);

    /*
     * Load once per source.
     *
     * `cancelled` rather than an AbortController on the fetch alone: pdf.js
     * carries on parsing after the await returns, and a placer unmounted
     * mid-parse would otherwise call setState on a dead component and leak a
     * worker.
     */
    useEffect(() => {
        let cancelled = false;

        // The LOADING TASK, not the document: destroy() lives on the task and
        // is what tears the worker down. Calling it is the only way to stop a
        // large parse that nobody is waiting for any more.
        let task: { destroy: () => Promise<void> } | null = null;

        const load = async () => {
            setStatus('loading');
            setError(null);

            try {
                const response = await fetch(source, {
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error(`preview responded ${response.status}`);
                }

                const buffer = new Uint8Array(await response.arrayBuffer());

                if (cancelled) {
                    return;
                }

                onBytes(buffer);

                const pdfjs = await import('pdfjs-dist');

                /*
                 * The worker is a bundled, same-origin asset. That matters:
                 * the app's CSP is `default-src 'self'` with no blob: in it,
                 * so pdf.js's fallback of building a worker from a blob URL
                 * would be blocked and it would silently fall back to parsing
                 * on the main thread.
                 */
                pdfjs.GlobalWorkerOptions.workerSrc = new URL(
                    'pdfjs-dist/build/pdf.worker.min.mjs',
                    import.meta.url,
                ).toString();

                // A copy, because pdf.js takes ownership of the buffer it is
                // given and detaches it — which would leave pdf-lib holding an
                // empty ArrayBuffer at stamping time.
                const loading = pdfjs.getDocument({ data: buffer.slice() });
                task = loading;

                const loaded = await loading.promise;

                if (cancelled) {
                    return;
                }

                docRef.current = loaded as never;

                const boxes: PageBox[] = [];

                for (let number = 1; number <= loaded.numPages; number++) {
                    const page = await loaded.getPage(number);
                    const viewport = page.getViewport({ scale: 1 });
                    boxes.push({
                        width: viewport.width,
                        height: viewport.height,
                    });
                }

                if (cancelled) {
                    return;
                }

                rendered.current = new Set();
                setPages(boxes);
                setStatus('ready');
            } catch {
                if (!cancelled) {
                    onBytes(null);
                    setStatus('failed');
                    setError(
                        'This document could not be opened for signing here. Use Full screen to read it, then download it, sign it and upload the signed copy as a new version.',
                    );
                }
            }
        };

        void load();

        return () => {
            cancelled = true;
            docRef.current = null;
            void task?.destroy();
        };
    }, [source, onBytes]);

    /** Fit the widest page to the column, and re-fit when the column moves. */
    useEffect(() => {
        const element = scrollRef.current;

        if (!element || pages.length === 0) {
            return;
        }

        const widest = Math.max(...pages.map((page) => page.width));

        const fit = () => {
            // 24px for the scrollbar and the page's own margin.
            const available = element.clientWidth - 24;

            if (available > 0) {
                setScale((current) => {
                    const next = available / widest;

                    // Re-rendering every canvas for a sub-pixel change is a lot
                    // of work for no visible difference.
                    return Math.abs(next - current) < 0.01 ? current : next;
                });
            }
        };

        fit();

        const observer = new ResizeObserver(fit);
        observer.observe(element);

        return () => observer.disconnect();
    }, [pages]);

    /** Anything that changes the drawn size invalidates every canvas. */
    useEffect(() => {
        rendered.current = new Set();
    }, [scale]);

    /** Which pages are worth drawing. */
    useEffect(() => {
        const element = scrollRef.current;

        if (!element || pages.length === 0) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                setVisible((current) => {
                    const next = new Set(current);

                    for (const entry of entries) {
                        const number = Number(
                            (entry.target as HTMLElement).dataset.pdfPage,
                        );

                        if (entry.isIntersecting) {
                            next.add(number);
                        }
                    }

                    return next.size === current.size ? current : next;
                });
            },
            { root: element, rootMargin: PRERENDER_MARGIN },
        );

        element
            .querySelectorAll<HTMLElement>('[data-pdf-page]')
            .forEach((page) => observer.observe(page));

        return () => observer.disconnect();
    }, [pages]);

    /** Draw the visible pages that have not been drawn at this scale yet. */
    useEffect(() => {
        const doc = docRef.current;

        if (!doc || pages.length === 0) {
            return;
        }

        let cancelled = false;

        const draw = async () => {
            for (const number of visible) {
                if (cancelled || rendered.current.has(number)) {
                    continue;
                }

                const canvas = canvasRefs.current[number - 1];

                if (!canvas) {
                    continue;
                }

                rendered.current.add(number);

                try {
                    const page = (await doc.getPage(number)) as {
                        getViewport: (options: { scale: number }) => {
                            width: number;
                            height: number;
                        };
                        render: (options: object) => { promise: Promise<void> };
                    };

                    // Device pixels, or the page is a blurry photocopy on any
                    // retina screen — and a blurry page is one nobody can
                    // place a signature accurately on.
                    const ratio = window.devicePixelRatio || 1;
                    const viewport = page.getViewport({ scale: scale * ratio });
                    const context = canvas.getContext('2d');

                    if (!context || cancelled) {
                        continue;
                    }

                    canvas.width = Math.floor(viewport.width);
                    canvas.height = Math.floor(viewport.height);

                    await page.render({
                        canvas,
                        canvasContext: context,
                        viewport,
                    }).promise;
                } catch {
                    // A page that will not draw stays blank rather than taking
                    // the whole panel down; the rest of the document is still
                    // readable and still signable.
                    rendered.current.delete(number);
                }
            }
        };

        void draw();

        return () => {
            cancelled = true;
        };
    }, [visible, scale, pages]);

    /** Keep the mark's proportions as drawn. */
    useEffect(() => {
        if (signaturePng === null) {
            return;
        }

        let cancelled = false;

        void pngAspectRatio(signaturePng).then((value) => {
            if (!cancelled) {
                setAspect(value);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [signaturePng]);

    /** A width fraction, and the height fraction that keeps it in proportion. */
    const heightFor = useCallback(
        (widthFraction: number, page: PageBox): number =>
            (widthFraction * page.width) / aspect / page.height,
        [aspect],
    );

    const place = (pageNumber: number, event: React.PointerEvent) => {
        if (disabled || signaturePng === null) {
            return;
        }

        const page = pages[pageNumber - 1];
        const rect = event.currentTarget.getBoundingClientRect();
        const width = placement?.width ?? DEFAULT_WIDTH;
        const boxHeight = heightFor(width, page);

        // Centred on the click: people aim at where the signature should SIT,
        // not at where its top-left corner should be.
        onPlacementChange({
            page: pageNumber,
            x: clamp(
                (event.clientX - rect.left) / rect.width - width / 2,
                0,
                1 - width,
            ),
            y: clamp(
                (event.clientY - rect.top) / rect.height - boxHeight / 2,
                0,
                1 - boxHeight,
            ),
            width,
            height: boxHeight,
        });
    };

    const drag = (event: React.PointerEvent, pageNumber: number) => {
        if (disabled || !placement) {
            return;
        }

        event.stopPropagation();
        event.preventDefault();

        const surface = event.currentTarget.parentElement;

        if (!surface) {
            return;
        }

        const rect = surface.getBoundingClientRect();
        const grabX = (event.clientX - rect.left) / rect.width - placement.x;
        const grabY = (event.clientY - rect.top) / rect.height - placement.y;
        const pointer = event.pointerId;

        event.currentTarget.setPointerCapture(pointer);

        const move = (moved: PointerEvent) => {
            onPlacementChange({
                ...placement,
                page: pageNumber,
                x: clamp(
                    (moved.clientX - rect.left) / rect.width - grabX,
                    0,
                    1 - placement.width,
                ),
                y: clamp(
                    (moved.clientY - rect.top) / rect.height - grabY,
                    0,
                    1 - placement.height,
                ),
            });
        };

        const done = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', done);
            window.removeEventListener('pointercancel', done);
        };

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', done);
        window.addEventListener('pointercancel', done);
    };

    const resize = (event: React.PointerEvent, pageNumber: number) => {
        if (disabled || !placement) {
            return;
        }

        event.stopPropagation();
        event.preventDefault();

        const surface = event.currentTarget.parentElement?.parentElement;

        if (!surface) {
            return;
        }

        const rect = surface.getBoundingClientRect();
        const page = pages[pageNumber - 1];

        event.currentTarget.setPointerCapture(event.pointerId);

        const move = (moved: PointerEvent) => {
            const width = clamp(
                (moved.clientX - rect.left) / rect.width - placement.x,
                MIN_WIDTH,
                Math.min(MAX_WIDTH, 1 - placement.x),
            );
            const boxHeight = heightFor(width, page);

            onPlacementChange({
                ...placement,
                width,
                height: boxHeight,
                y: Math.min(placement.y, 1 - boxHeight),
            });
        };

        const done = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', done);
            window.removeEventListener('pointercancel', done);
        };

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', done);
        window.addEventListener('pointercancel', done);
    };

    if (status === 'failed') {
        return (
            <div
                className={`flex items-center justify-center rounded-md border border-dashed bg-muted p-6 text-center ${height}`}
            >
                <p role="alert" className="max-w-md text-sm text-danger">
                    {error}
                </p>
            </div>
        );
    }

    return (
        <div
            ref={scrollRef}
            className={`relative overflow-auto rounded-md border bg-muted ${height}`}
        >
            {status === 'loading' && (
                <p className="p-6 text-center text-sm text-muted-foreground">
                    Opening the document…
                </p>
            )}

            {pages.map((page, index) => {
                const number = index + 1;
                const width = page.width * scale;
                const pageHeight = page.height * scale;
                const here = placement?.page === number ? placement : null;

                return (
                    <div
                        key={number}
                        /*
                            data-PDF-page, not data-page: Inertia puts its own
                            `data-page` on the root <div id="app">, so the plain
                            name matches that too. Nothing breaks today because
                            the query below is scoped to the scroller, but a
                            selector that quietly also means "the whole app" is
                            a trap for the next person to widen it.
                        */
                        data-pdf-page={number}
                        onPointerDown={(event) => place(number, event)}
                        style={{ width, height: pageHeight }}
                        className={`relative mx-auto my-3 bg-white shadow-sm ${
                            signaturePng !== null && !disabled
                                ? 'cursor-crosshair'
                                : ''
                        }`}
                    >
                        <canvas
                            ref={(element) => {
                                canvasRefs.current[index] = element;
                            }}
                            style={{ width, height: pageHeight }}
                            aria-label={`Page ${number} of ${pages.length}`}
                        />

                        <span className="pointer-events-none absolute top-1 right-1 rounded bg-navy/70 px-1.5 py-0.5 text-[10px] font-medium text-white">
                            {number}
                        </span>

                        {here && signaturePng !== null && (
                            <div
                                onPointerDown={(event) => drag(event, number)}
                                style={{
                                    left: `${here.x * 100}%`,
                                    top: `${here.y * 100}%`,
                                    width: `${here.width * 100}%`,
                                    height: `${here.height * 100}%`,
                                }}
                                className="absolute cursor-move touch-none rounded-sm ring-2 ring-brand/70 ring-offset-1"
                            >
                                <img
                                    src={signaturePng}
                                    alt="Your signature, positioned on the page"
                                    draggable={false}
                                    className="pointer-events-none h-full w-full object-contain select-none"
                                />

                                {!disabled && (
                                    <span
                                        onPointerDown={(event) =>
                                            resize(event, number)
                                        }
                                        role="presentation"
                                        className="absolute -right-1.5 -bottom-1.5 size-3.5 cursor-nwse-resize touch-none rounded-full border-2 border-white bg-brand shadow"
                                    />
                                )}
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function clamp(value: number, low: number, high: number): number {
    return Math.min(Math.max(value, low), Math.max(low, high));
}
