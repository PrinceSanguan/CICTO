/**
 * The decorative city band that closes the page.
 *
 * Drawn as an SVG <pattern> with NO viewBox, so one user unit equals one CSS
 * pixel and the tile repeats at its authored size across any viewport --
 * no stretching, no per-width cropping, no distorted buildings. The tile is
 * deliberately wide (900px) and irregular so the repeat does not read.
 *
 * Geometry follows the client's office-buildings asset: flat rounded towers
 * with a grid of square windows and a rounded door at the base.
 */

type Tower = {
    x: number;
    w: number;
    h: number;
    /** Window grid columns. */
    cols: number;
    /** Antenna mast on the roof. */
    mast?: boolean;
};

const GROUND = 300;

/**
 * Far layer: taller, more saturated. Packed with only a few pixels between
 * towers -- the Figma's skyline reads as a dense continuous city, not as
 * separated buildings.
 */
const BACK: readonly Tower[] = [
    { x: 0, w: 62, h: 150, cols: 3 },
    { x: 68, w: 46, h: 214, cols: 2, mast: true },
    { x: 120, w: 58, h: 122, cols: 3 },
    { x: 184, w: 72, h: 170, cols: 3 },
    { x: 262, w: 42, h: 234, cols: 2, mast: true },
    { x: 310, w: 68, h: 140, cols: 3 },
    { x: 384, w: 52, h: 196, cols: 2 },
    { x: 442, w: 78, h: 126, cols: 4 },
    { x: 526, w: 46, h: 248, cols: 2, mast: true },
    { x: 578, w: 62, h: 160, cols: 3 },
    { x: 646, w: 52, h: 202, cols: 2 },
    { x: 704, w: 72, h: 136, cols: 3 },
    { x: 782, w: 48, h: 182, cols: 2 },
    { x: 836, w: 64, h: 144, cols: 3 },
];

/** Near layer: shorter and lighter, overlapping into a continuous mass. */
const FRONT: readonly Tower[] = [
    { x: 0, w: 76, h: 96, cols: 3 },
    { x: 64, w: 62, h: 118, cols: 3 },
    { x: 116, w: 84, h: 82, cols: 4 },
    { x: 190, w: 68, h: 108, cols: 3 },
    { x: 248, w: 74, h: 88, cols: 3 },
    { x: 312, w: 62, h: 122, cols: 3 },
    { x: 364, w: 88, h: 94, cols: 4 },
    { x: 442, w: 68, h: 112, cols: 3 },
    { x: 500, w: 78, h: 86, cols: 4 },
    { x: 568, w: 64, h: 104, cols: 3 },
    { x: 622, w: 82, h: 90, cols: 4 },
    { x: 694, w: 70, h: 116, cols: 3 },
    { x: 754, w: 76, h: 88, cols: 4 },
    { x: 820, w: 80, h: 100, cols: 4 },
];

const WINDOW = 6;
const GAP = 7;

function TowerShape({ tower, fill }: { tower: Tower; fill: string }) {
    const top = GROUND - tower.h;
    const spanW = tower.cols * WINDOW + (tower.cols - 1) * GAP;
    const startX = tower.x + (tower.w - spanW) / 2;
    const rows = Math.max(1, Math.floor((tower.h - 34) / (WINDOW + GAP)));

    return (
        <g>
            {tower.mast ? (
                <rect
                    x={tower.x + tower.w / 2 - 1.5}
                    y={top - 16}
                    width="3"
                    height="16"
                    fill={fill}
                />
            ) : null}

            <rect
                x={tower.x}
                y={top}
                width={tower.w}
                height={tower.h}
                rx="3"
                fill={fill}
            />

            <g fill="#FFFFFF" opacity="0.75">
                {Array.from({ length: rows }, (_, r) =>
                    Array.from({ length: tower.cols }, (_, c) => (
                        <rect
                            key={`${r}-${c}`}
                            x={startX + c * (WINDOW + GAP)}
                            y={top + 12 + r * (WINDOW + GAP)}
                            width={WINDOW}
                            height={WINDOW}
                            rx="1"
                        />
                    )),
                )}
            </g>

            {/* doorway */}
            <rect
                x={tower.x + tower.w / 2 - 6}
                y={GROUND - 18}
                width="12"
                height="18"
                rx="5"
                fill="#FFFFFF"
                opacity="0.6"
            />
        </g>
    );
}

export function Skyline() {
    return (
        <div
            aria-hidden="true"
            className="relative h-[150px] w-full overflow-hidden bg-surface sm:h-[210px] lg:h-[300px]"
        >
            <svg
                width="100%"
                height={GROUND}
                preserveAspectRatio="none"
                className="absolute bottom-0 left-0"
            >
                <defs>
                    <pattern
                        id="cicto-skyline"
                        patternUnits="userSpaceOnUse"
                        width="900"
                        height={GROUND}
                    >
                        {BACK.map((tower) => (
                            <TowerShape
                                key={`b-${tower.x}`}
                                tower={tower}
                                fill="#93C5FF"
                            />
                        ))}
                        {FRONT.map((tower) => (
                            <TowerShape
                                key={`f-${tower.x}`}
                                tower={tower}
                                fill="#B5D6FC"
                            />
                        ))}
                    </pattern>
                </defs>
                <rect width="100%" height={GROUND} fill="url(#cicto-skyline)" />
            </svg>
        </div>
    );
}
