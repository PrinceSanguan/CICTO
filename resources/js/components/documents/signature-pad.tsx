import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';

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
 */
export function SignaturePad({
    onChange,
    disabled = false,
}: {
    onChange: (dataUrl: string | null) => void;
    disabled?: boolean;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const [hasMark, setHasMark] = useState(false);

    // Size the backing store to the display size × DPR, once mounted.
    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        const ratio = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();

        canvas.width = Math.floor(rect.width * ratio);
        canvas.height = Math.floor(rect.height * ratio);

        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#111827';
    }, []);

    const pointAt = (event: React.PointerEvent<HTMLCanvasElement>) => {
        const rect = event.currentTarget.getBoundingClientRect();

        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };

    const start = (event: React.PointerEvent<HTMLCanvasElement>) => {
        if (disabled) {
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
            onChange(canvas.toDataURL('image/png'));
        }
    }, [onChange]);

    const clear = () => {
        const canvas = canvasRef.current;
        const ctx = canvas?.getContext('2d');

        if (!canvas || !ctx) {
            return;
        }

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        setHasMark(false);
        onChange(null);
    };

    return (
        <div className="space-y-2">
            <canvas
                ref={canvasRef}
                onPointerDown={start}
                onPointerMove={move}
                onPointerUp={end}
                onPointerLeave={end}
                onPointerCancel={end}
                aria-label="Signature area"
                className="h-36 w-full touch-none rounded-md border border-dashed bg-white dark:bg-neutral-100"
            />
            <div className="flex items-center justify-between">
                <p className="text-xs text-muted-foreground">
                    Sign above using a finger, stylus or mouse.
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={clear}
                    disabled={!hasMark}
                >
                    Clear
                </Button>
            </div>
        </div>
    );
}
