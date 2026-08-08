import womanSrc from '@/assets/hero-woman-laptop.png';
import manSrc from '@/assets/hero-man-document.png';

/**
 * The hero illustration, rebuilt from the client's Figma.
 *
 * The whole scene is ONE svg with a fixed viewBox, and the two supplied person
 * PNGs are placed inside it as <image> elements rather than as separately
 * positioned DOM nodes.
 *
 * That choice is the point: the composition *is* the design -- the woman has to
 * straddle the large window's left edge and the man the small window's right
 * edge. Held in a single coordinate space the whole arrangement scales as one
 * unit and cannot drift apart at any viewport width. As separate absolutely
 * positioned elements those relationships would need re-tuning per breakpoint.
 *
 * Coordinate space is 430 x 300, matching the proportions measured off the
 * Figma export.
 */

/**
 * The pale scalloped shapes along the bottom-left of the hero.
 *
 * These sit behind the copy column in the Figma, i.e. outside the art's
 * coordinate space, so they are a separate full-width layer rather than part
 * of HeroScene.
 */
export function HeroBackdrop({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 420 150"
            preserveAspectRatio="xMinYMax meet"
            className={className}
            aria-hidden="true"
        >
            <g fill="#FFFFFF" opacity="0.13">
                <circle cx="38" cy="118" r="62" />
                <circle cx="128" cy="132" r="46" />
                <circle cx="196" cy="120" r="55" />
                <circle cx="272" cy="136" r="40" />
                <circle cx="338" cy="126" r="50" />
            </g>
            <g fill="#FFFFFF" opacity="0.1">
                <circle cx="86" cy="146" r="44" />
                <circle cx="238" cy="150" r="38" />
            </g>
        </svg>
    );
}

/** Placeholder lines inside a window body. */
function Lines({
    x,
    y,
    width,
    count,
    gap = 15,
}: {
    x: number;
    y: number;
    width: number;
    count: number;
    gap?: number;
}) {
    return (
        <g fill="#DDE3EA">
            {Array.from({ length: count }, (_, i) => (
                <rect
                    key={i}
                    x={x}
                    y={y + i * gap}
                    width={i === count - 1 ? width * 0.62 : width}
                    height="2.4"
                    rx="1.2"
                />
            ))}
        </g>
    );
}

/** The three small grey buttons along the bottom of a window. */
function Pills({ x, y, scale = 1 }: { x: number; y: number; scale?: number }) {
    return (
        <g transform={`translate(${x} ${y}) scale(${scale})`}>
            {[0, 26, 52].map((dx) => (
                <g key={dx}>
                    <rect
                        x={dx}
                        y="0"
                        width="21"
                        height="11"
                        rx="2"
                        fill="#FFFFFF"
                        stroke="#DDE3EA"
                        strokeWidth="1"
                    />
                    <rect
                        x={dx + 5}
                        y="4.6"
                        width="11"
                        height="2"
                        rx="1"
                        fill="#7A8493"
                    />
                </g>
            ))}
        </g>
    );
}

/** Outline document sheet, used for the floating decorations. */
function DocMark({
    x,
    y,
    scale = 1,
    opacity = 0.5,
}: {
    x: number;
    y: number;
    scale?: number;
    opacity?: number;
}) {
    return (
        <g
            transform={`translate(${x} ${y}) scale(${scale})`}
            opacity={opacity}
            fill="none"
            stroke="#FFFFFF"
            strokeWidth="1.6"
            strokeLinejoin="round"
        >
            <path d="M2 2h13l7 7v17H2z" />
            <path d="M15 2v7h7" />
            <path d="M6 14h12M6 19h12" strokeLinecap="round" />
        </g>
    );
}

/** White map marker. */
function PinMark({
    x,
    y,
    scale = 1,
}: {
    x: number;
    y: number;
    scale?: number;
}) {
    return (
        <g transform={`translate(${x} ${y}) scale(${scale})`} opacity="0.92">
            <path
                d="M9 0C4 0 0 4 0 9c0 6.6 9 15 9 15s9-8.4 9-15c0-5-4-9-9-9Z"
                fill="#FFFFFF"
            />
            <circle cx="9" cy="9" r="3.4" fill="#5E92D8" />
        </g>
    );
}

export function HeroScene({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 430 300"
            className={className}
            role="img"
            aria-label="Staff reviewing documents alongside the CICTO tracking interface"
        >
            {/* soft background shapes */}
            <g aria-hidden="true">
                <circle cx="30" cy="252" r="46" fill="#FFFFFF" opacity="0.09" />
                <circle cx="74" cy="268" r="30" fill="#FFFFFF" opacity="0.07" />
                <path
                    d="M148 78c0-11 9-20 20-20 4 0 8 1 11 3 4-8 12-13 21-13 13 0 24 11 24 24 0 2 0 4-1 6h4c8 0 14 6 14 14H148c-8 0-14-6-14-14s6-14 14-14Z"
                    fill="#FFFFFF"
                    opacity="0.13"
                />
            </g>

            {/* floating marks */}
            <g aria-hidden="true">
                <DocMark x={52} y={22} scale={0.85} opacity={0.55} />
                <DocMark x={10} y={150} scale={0.8} opacity={0.4} />
                <DocMark x={296} y={14} scale={0.95} opacity={0.6} />
                <DocMark x={368} y={44} scale={0.85} opacity={0.5} />
                <PinMark x={210} y={44} scale={0.85} />
                <PinMark x={396} y={148} scale={0.8} />
            </g>

            {/* large window, behind */}
            <g aria-hidden="true">
                <rect
                    x="80"
                    y="62"
                    width="250"
                    height="26"
                    rx="4"
                    fill="#2C6FCB"
                />
                <rect x="80" y="80" width="250" height="150" fill="#FFFFFF" />
                <rect
                    x="104"
                    y="112"
                    width="250"
                    height="128"
                    rx="3"
                    fill="#FFFFFF"
                />
                <Lines x={124} y={132} width={208} count={6} />
                <Pills x={124} y={214} />
            </g>

            {/* small window, in front */}
            <g aria-hidden="true">
                <rect
                    x="245"
                    y="132"
                    width="176"
                    height="24"
                    rx="4"
                    fill="#2C6FCB"
                />
                <rect x="245" y="150" width="176" height="128" fill="#FFFFFF" />
                <rect
                    x="262"
                    y="170"
                    width="148"
                    height="96"
                    rx="3"
                    fill="#FFFFFF"
                />
                {/* small blue icon block, top-right of the inner page */}
                <g>
                    <rect
                        x="356"
                        y="176"
                        width="12"
                        height="12"
                        rx="2"
                        fill="#2C6FCB"
                    />
                    <rect
                        x="373"
                        y="178"
                        width="30"
                        height="2.4"
                        rx="1.2"
                        fill="#DDE3EA"
                    />
                    <rect
                        x="373"
                        y="184"
                        width="22"
                        height="2.4"
                        rx="1.2"
                        fill="#DDE3EA"
                    />
                </g>
                <Lines x={272} y={200} width={130} count={4} gap={13} />
                <Pills x={272} y={246} scale={0.85} />
            </g>

            {/*
              People last so they sit above the windows, matching the Figma.
              preserveAspectRatio keeps their natural proportions inside the
              boxes below; the boxes are sized from the source aspect ratios
              (360x640 and 217x640).
            */}
            <image
                href={womanSrc}
                x="42"
                y="128"
                width="86"
                height="153"
                preserveAspectRatio="xMidYMax meet"
            />
            <image
                href={manSrc}
                x="368"
                y="150"
                width="45"
                height="133"
                preserveAspectRatio="xMidYMax meet"
            />
        </svg>
    );
}
