import type jsQRType from 'jsqr';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Camera QR scanning for the staff scan console.
 *
 * Two decoders, deliberately:
 *
 *  1. `BarcodeDetector` when the browser has it. Native, hardware-accelerated,
 *     zero bytes downloaded. This is the path Android Chrome takes, which is
 *     what an LGU field user actually holds.
 *  2. `jsQR`, imported LAZILY, only when the native API is missing. It lands in
 *     its own chunk, so Safari and Firefox users pay for it and nobody else
 *     does — and a deployment where nobody opens the camera never fetches it.
 *
 * The camera is NOT the primary input. A USB keyboard-wedge scanner needs no
 * permission, no HTTPS and no decoder; it just types. This hook exists for the
 * phone-in-the-corridor case, and every failure below degrades back to typing.
 */

type ScannerState =
    | 'idle'
    | 'starting'
    | 'scanning'
    | 'denied'
    | 'insecure'
    | 'unsupported'
    | 'error';

type BarcodeDetectorLike = {
    detect: (source: CanvasImageSource) => Promise<{ rawValue: string }[]>;
};

type WindowWithDetector = Window & {
    BarcodeDetector?: {
        new (options?: { formats?: string[] }): BarcodeDetectorLike;
        getSupportedFormats?: () => Promise<string[]>;
    };
};

/**
 * getUserMedia is unavailable on insecure origins. That is a browser rule, not
 * a setting: on an LGU LAN deployment at http://192.168.x.x there is no
 * workaround, and saying so plainly beats a permission prompt that never comes.
 */
export function cameraIsPossible(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return (
        window.isSecureContext && Boolean(navigator.mediaDevices?.getUserMedia)
    );
}

export function useQrScanner(onScan: (value: string) => void) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const frameRef = useRef<number | null>(null);

    // Held in a ref so the scan loop never restarts when the callback identity
    // changes -- a re-created closure would otherwise tear down the camera.
    // Synced in an effect rather than during render, which would be a render
    // side effect.
    const onScanRef = useRef(onScan);

    useEffect(() => {
        onScanRef.current = onScan;
    }, [onScan]);

    const [state, setState] = useState<ScannerState>('idle');
    const [torchOn, setTorchOn] = useState(false);
    const [torchAvailable, setTorchAvailable] = useState(false);

    const release = useCallback(() => {
        if (frameRef.current !== null) {
            cancelAnimationFrame(frameRef.current);
            frameRef.current = null;
        }

        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;

        if (videoRef.current) {
            videoRef.current.srcObject = null;
        }
    }, []);

    const stop = useCallback(() => {
        release();
        setTorchOn(false);
        setTorchAvailable(false);
        setState('idle');
    }, [release]);

    const start = useCallback(async () => {
        if (!cameraIsPossible()) {
            setState('insecure');

            return;
        }

        setState('starting');

        let stream: MediaStream;

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                // The rear camera is the one pointed at the folder.
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
        } catch (error) {
            const name = (error as DOMException)?.name;

            setState(
                name === 'NotAllowedError' || name === 'SecurityError'
                    ? 'denied'
                    : 'error',
            );

            return;
        }

        streamRef.current = stream;

        const video = videoRef.current;

        if (!video) {
            stream.getTracks().forEach((track) => track.stop());
            setState('error');

            return;
        }

        video.srcObject = stream;
        // iOS Safari refuses to play an inline video without this attribute set
        // as a property as well as in the markup.
        video.setAttribute('playsinline', 'true');

        try {
            await video.play();
        } catch {
            setState('error');

            return;
        }

        const track = stream.getVideoTracks()[0];
        const capabilities = track?.getCapabilities?.() as
            { torch?: boolean } | undefined;

        setTorchAvailable(Boolean(capabilities?.torch));

        // Resolve a decoder once, outside the frame loop.
        const detectorCtor = (window as WindowWithDetector).BarcodeDetector;
        let detector: BarcodeDetectorLike | null = null;
        let jsQR: typeof jsQRType | null = null;

        if (detectorCtor) {
            try {
                detector = new detectorCtor({ formats: ['qr_code'] });
            } catch {
                detector = null;
            }
        }

        if (!detector) {
            try {
                jsQR = (await import('jsqr')).default;
            } catch {
                setState('unsupported');

                return;
            }
        }

        setState('scanning');

        let settled = false;

        const succeed = (value: string) => {
            if (settled) {
                return;
            }

            settled = true;

            // Release the hardware BEFORE handing control to the caller. The
            // caller navigates away, and a camera left running keeps the
            // recording indicator lit on the way out.
            release();
            onScanRef.current(value);
        };

        const tick = async () => {
            if (settled || !videoRef.current || !canvasRef.current) {
                return;
            }

            const element = videoRef.current;
            const canvas = canvasRef.current;

            if (element.readyState === element.HAVE_ENOUGH_DATA) {
                canvas.width = element.videoWidth;
                canvas.height = element.videoHeight;

                const context = canvas.getContext('2d', {
                    willReadFrequently: true,
                });

                if (context) {
                    context.drawImage(
                        element,
                        0,
                        0,
                        canvas.width,
                        canvas.height,
                    );

                    try {
                        if (detector) {
                            const found = await detector.detect(canvas);

                            if (found[0]?.rawValue) {
                                succeed(found[0].rawValue);

                                return;
                            }
                        } else if (jsQR) {
                            const image = context.getImageData(
                                0,
                                0,
                                canvas.width,
                                canvas.height,
                            );
                            const found = jsQR(
                                image.data,
                                image.width,
                                image.height,
                                { inversionAttempts: 'dontInvert' },
                            );

                            if (found?.data) {
                                succeed(found.data);

                                return;
                            }
                        }
                    } catch {
                        // A single bad frame is not a failure. Keep looking.
                    }
                }
            }

            frameRef.current = requestAnimationFrame(() => void tick());
        };

        frameRef.current = requestAnimationFrame(() => void tick());
    }, [release]);

    const toggleTorch = useCallback(async () => {
        const track = streamRef.current?.getVideoTracks()[0];

        if (!track) {
            return;
        }

        const next = !torchOn;

        try {
            // `torch` is real on Android Chrome but is not in the DOM typings,
            // so the constraint has to be built untyped.
            await track.applyConstraints({
                advanced: [{ torch: next }],
            } as unknown as MediaTrackConstraints);
            setTorchOn(next);
        } catch {
            setTorchAvailable(false);
        }
    }, [torchOn]);

    // Releasing the camera on unmount is not optional: the recording indicator
    // stays lit and the device stays warm otherwise.
    useEffect(() => stop, [stop]);

    return {
        videoRef,
        canvasRef,
        state,
        start,
        stop,
        torchOn,
        torchAvailable,
        toggleTorch,
    };
}
