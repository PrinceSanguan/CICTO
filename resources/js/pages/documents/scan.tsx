import { Head, router } from '@inertiajs/react';
import { Flashlight, Keyboard, Loader2, ScanLine } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ScanPhone } from '@/components/documents/scan-phone';
import { Input } from '@/components/ui/input';
import { cameraIsPossible, useQrScanner } from '@/hooks/use-qr-scanner';
import documents from '@/routes/documents';

/**
 * §7 staff scan console.
 *
 * Two input paths, and the order matters:
 *
 *  1. The USB keyboard-wedge scanner is the DEFAULT. It needs no library, no
 *     permission prompt and no HTTPS -- it types into whatever has focus. The
 *     cursor starts in that box and returns to it, because a counter clerk with
 *     a wired scanner should never have to touch this page.
 *  2. The camera is progressive enhancement. `getUserMedia` refuses to run on
 *     plain HTTP outside localhost, so on an LGU LAN deployment at
 *     http://192.168.x.x it is simply unavailable -- a browser rule, not a
 *     setting. The page says so rather than offering a button that cannot work.
 */
export default function ScanConsole() {
    const [token, setToken] = useState('');
    const [cameraRequested, setCameraRequested] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    // Read at render: this cannot change for the life of the page, so holding
    // it in state would only buy a second render.
    const cameraPossible = cameraIsPossible();

    const resolve = useCallback((value: string) => {
        const trimmed = value.trim();

        if (trimmed === '') {
            return;
        }

        // A wedge scanner types the whole URL that was encoded in the QR, and
        // the camera decodes that same URL, so accept either form.
        const parts = trimmed.split('/');
        const scanned = parts[parts.length - 1];

        router.get(`/s/${scanned}`);
    }, []);

    // The hook releases the camera itself before invoking this, so there is
    // nothing to tear down here.
    const scanner = useQrScanner(resolve);

    const {
        start,
        stop,
        state,
        videoRef,
        canvasRef,
        torchOn,
        torchAvailable,
        toggleTorch,
    } = scanner;

    // The wedge scanner types into whichever field has focus, so the cursor has
    // to start in the input -- unless the camera is the active path, where
    // stealing focus would pop the on-screen keyboard over the viewfinder.
    useEffect(() => {
        if (!cameraRequested) {
            inputRef.current?.focus();
        }
    }, [cameraRequested]);

    const startCamera = () => {
        setCameraRequested(true);
        void start();
    };

    const stopCamera = () => {
        setCameraRequested(false);
        stop();
    };

    return (
        <>
            <Head title="Scan QR Code" />

            {/*
                `max-w-5xl` on the row and `max-w-sm` on the column, measured
                off the client's 2026-08-26 comp.

                The row was the layout's full `max-w-7xl`, and `justify-between`
                across 1216px shoved the viewfinder against the left edge and
                the phone against the right, ~250px further apart than the comp
                draws them. Narrowing the ROW is what closes that: the two stay
                pinned to its edges, so the gap between them is whatever is left
                over, and there is no gap value to keep in sync with the column
                widths.

                The column was `max-w-md` -- 448px, against the comp's ~364.
                ScanFrame is `aspect-square w-full`, so those 64px came off the
                height twice over: once from the frame and once from everything
                the taller frame pushed down the page.
            */}
            <div className="mx-auto flex max-w-5xl flex-col items-center gap-10 lg:flex-row lg:items-start lg:justify-between lg:gap-16">
                <div className="w-full max-w-sm">
                    {/*
                        White, like every other heading in this shell. It was
                        `text-navy` on `text-navy-soft/70` -- navy on the TOP of
                        the hero gradient, which is `--color-brand` #2d6fcb, so
                        the title of the page was the least legible text on it.
                        Track Documents, Reports and Help all set their h1 white
                        here; this page was the one that did not.

                        The helper line further down stays muted and dark on
                        purpose: it sits low enough that the gradient has faded
                        to the pale ground, where white would vanish instead.
                    */}
                    <h1 className="text-4xl font-extrabold tracking-tight text-white sm:text-5xl">
                        Scan QR Code
                    </h1>
                    <p className="mt-2 text-sm font-medium text-white/90">
                        Align the QR code within the frame to scan.
                    </p>

                    <ScanFrame
                        videoRef={videoRef}
                        canvasRef={canvasRef}
                        active={cameraRequested && state === 'scanning'}
                        starting={cameraRequested && state === 'starting'}
                    />

                    {cameraPossible ? (
                        <CameraControls
                            requested={cameraRequested}
                            state={state}
                            torchOn={torchOn}
                            torchAvailable={torchAvailable}
                            onStart={startCamera}
                            onStop={stopCamera}
                            onToggleTorch={() => void toggleTorch()}
                        />
                    ) : (
                        <p className="mt-5 rounded-xl bg-white/70 px-4 py-3 text-center text-xs leading-relaxed text-navy-soft/80">
                            Camera scanning needs HTTPS — browsers block camera
                            access on insecure origins. Use a USB scanner or
                            type the code below.
                        </p>
                    )}

                    <p className="mt-4 text-center text-sm leading-relaxed text-navy-soft/70">
                        Position the QR code in the frame and it will be scanned
                        automatically.
                    </p>

                    <WedgeEntry
                        inputRef={inputRef}
                        token={token}
                        onChange={setToken}
                        onSubmit={() => resolve(token)}
                    />
                </div>

                <ScanPhone />
            </div>
        </>
    );
}

/** The dashed viewfinder. Shows live video once the camera is running. */
function ScanFrame({
    videoRef,
    canvasRef,
    active,
    starting,
}: {
    videoRef: React.RefObject<HTMLVideoElement | null>;
    canvasRef: React.RefObject<HTMLCanvasElement | null>;
    active: boolean;
    starting: boolean;
}) {
    return (
        <div className="relative mt-6 aspect-square w-full">
            {/* Dashed green bracket */}
            <div className="absolute inset-0 rounded-lg border-4 border-dashed border-[#7CC242]" />

            <div className="absolute inset-6 overflow-hidden rounded-md bg-white">
                <video
                    ref={videoRef}
                    muted
                    playsInline
                    className={`size-full object-cover ${active ? 'block' : 'hidden'}`}
                />
                <canvas ref={canvasRef} className="hidden" />

                {!active && (
                    <div className="flex size-full items-center justify-center p-4">
                        {starting ? (
                            <span className="flex items-center gap-2 text-sm text-navy-soft/70">
                                <Loader2 className="size-4 animate-spin" />
                                Starting the camera…
                            </span>
                        ) : (
                            <PlaceholderQr />
                        )}
                    </div>
                )}
            </div>

            {/* The sweeping laser line, only while actually scanning. */}
            {active && (
                <div className="pointer-events-none absolute inset-x-6 top-6 bottom-6 overflow-hidden rounded-md">
                    <div className="animate-scan-sweep h-0.5 w-full bg-[#8BE04A] shadow-[0_0_12px_4px_rgba(139,224,74,0.6)]" />
                </div>
            )}
        </div>
    );
}

/**
 * The idle graphic inside the frame. Not a real code: a scannable image in a
 * "point your camera here" illustration is a trap.
 */
function PlaceholderQr() {
    const cells = 25;
    const filled = (row: number, col: number) =>
        (row * 29 + col * 13 + row * col * 5) % 9 > 3;
    const finder = (row: number, col: number) =>
        (row < 7 && col < 7) ||
        (row < 7 && col >= cells - 7) ||
        (row >= cells - 7 && col < 7);

    return (
        <svg
            viewBox={`0 0 ${cells} ${cells}`}
            className="size-full max-h-full max-w-full"
            aria-hidden="true"
        >
            {Array.from({ length: cells }, (_, row) =>
                Array.from({ length: cells }, (_, col) =>
                    !finder(row, col) && filled(row, col) ? (
                        <rect
                            key={`${row}-${col}`}
                            x={col}
                            y={row}
                            width="1"
                            height="1"
                            fill="#111111"
                        />
                    ) : null,
                ),
            )}
            {[
                [0, 0],
                [0, cells - 7],
                [cells - 7, 0],
            ].map(([row, col]) => (
                <g key={`${row}-${col}`}>
                    <rect x={col} y={row} width="7" height="7" fill="#111111" />
                    <rect
                        x={col + 1}
                        y={row + 1}
                        width="5"
                        height="5"
                        fill="#FFFFFF"
                    />
                    <rect
                        x={col + 2}
                        y={row + 2}
                        width="3"
                        height="3"
                        fill="#111111"
                    />
                </g>
            ))}
        </svg>
    );
}

function CameraControls({
    requested,
    state,
    torchOn,
    torchAvailable,
    onStart,
    onStop,
    onToggleTorch,
}: {
    requested: boolean;
    state: ReturnType<typeof useQrScanner>['state'];
    torchOn: boolean;
    torchAvailable: boolean;
    onStart: () => void;
    onStop: () => void;
    onToggleTorch: () => void;
}) {
    if (!requested) {
        return (
            <button
                type="button"
                onClick={onStart}
                className="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-white/70 py-3.5 text-sm font-medium text-navy-soft transition hover:bg-white"
            >
                <ScanLine className="size-4" />
                Scan with the camera
            </button>
        );
    }

    if (state === 'denied') {
        return (
            <Notice onRetry={onStart}>
                Camera permission was refused. Allow it in your browser’s site
                settings, or use the scanner below.
            </Notice>
        );
    }

    if (state === 'unsupported' || state === 'error') {
        return (
            <Notice onRetry={onStart}>
                The camera could not be started on this device. Use a USB
                scanner or type the code below.
            </Notice>
        );
    }

    return (
        <div className="mt-5 space-y-2">
            <button
                type="button"
                onClick={onToggleTorch}
                disabled={!torchAvailable}
                className="flex w-full items-center justify-center gap-2 rounded-xl bg-white/70 py-3.5 text-sm font-medium text-navy-soft transition enabled:hover:bg-white disabled:cursor-not-allowed disabled:opacity-55"
            >
                <span className="flex size-6 items-center justify-center rounded-full bg-[#2F6FCB]">
                    <Flashlight className="size-3.5 text-white" />
                </span>
                {torchOn ? 'Turn off flashlight' : 'Turn on flashlight'}
            </button>

            {!torchAvailable && (
                <p className="text-center text-xs text-navy-soft/60">
                    This device has no controllable flashlight.
                </p>
            )}

            <button
                type="button"
                onClick={onStop}
                className="w-full rounded-xl py-2 text-xs font-medium text-navy-soft/70 underline-offset-2 hover:underline"
            >
                Stop the camera
            </button>
        </div>
    );
}

function Notice({
    children,
    onRetry,
}: {
    children: React.ReactNode;
    onRetry: () => void;
}) {
    return (
        <div className="mt-5 rounded-xl bg-white/75 px-4 py-3 text-center">
            <p className="text-xs leading-relaxed text-navy-soft/80">
                {children}
            </p>
            <button
                type="button"
                onClick={onRetry}
                className="mt-2 text-xs font-semibold text-link underline-offset-2 hover:underline"
            >
                Try again
            </button>
        </div>
    );
}

/** The always-available path: a wired scanner, or typing. */
function WedgeEntry({
    inputRef,
    token,
    onChange,
    onSubmit,
}: {
    inputRef: React.RefObject<HTMLInputElement | null>;
    token: string;
    onChange: (value: string) => void;
    onSubmit: () => void;
}) {
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit();
            }}
            className="mt-6 rounded-2xl bg-white/80 p-4 backdrop-blur-sm"
        >
            <label
                htmlFor="token"
                className="flex items-center gap-2 text-sm font-semibold text-navy"
            >
                <Keyboard className="size-4" />
                Scanner or manual entry
            </label>

            <Input
                id="token"
                ref={inputRef}
                value={token}
                onChange={(event) => onChange(event.target.value)}
                placeholder="Scan the label, or type the code…"
                autoComplete="off"
                spellCheck={false}
                className="mt-2 bg-white font-mono"
            />

            <p className="mt-2 text-xs text-navy-soft/70">
                A USB barcode scanner types into this box and submits by itself.
                Keep the cursor here.
            </p>

            <button
                type="submit"
                className="mt-3 h-12 w-full rounded-md bg-[#3B72C4] text-[15px] font-bold text-white transition hover:bg-[#31629F]"
            >
                Look up
            </button>
        </form>
    );
}

ScanConsole.layout = {
    breadcrumbs: [
        { title: 'Track Documents', href: documents.index() },
        { title: 'Scan QR Code', href: documents.scan() },
    ],
};
