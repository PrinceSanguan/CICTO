import womanSrc from '@/assets/hero-woman-laptop.png';

/**
 * The decorative half of the auth screens: floating stationery, the pale ground
 * band, the cloud bank, and the woman with the laptop.
 *
 * All of it is aria-hidden and pointer-events-none. A screen reader announcing
 * "document, document, map pin, woman with laptop" ahead of a login form is
 * noise, and an absolutely positioned illustration must never swallow a tap
 * meant for the email field.
 *
 * The welcome wordmark is the one part that is NOT decorative -- it names the
 * system, so it stays readable text rather than becoming part of the art.
 */

type Motif = {
    /** Percent offsets, so the arrangement holds its composition at any width. */
    x: number;
    y: number;
    size: number;
    kind: 'pencil' | 'lines' | 'page' | 'pin' | 'faded';
};

const MOTIFS: readonly Motif[] = [
    { x: 4, y: 7, size: 46, kind: 'pencil' },
    { x: 4, y: 40, size: 40, kind: 'faded' },
    { x: 71, y: 13, size: 34, kind: 'pin' },
    { x: 74, y: 21, size: 36, kind: 'lines' },
    { x: 90, y: 15, size: 40, kind: 'page' },
    { x: 94, y: 25, size: 30, kind: 'pin' },
];

function Glyph({ kind }: { kind: Motif['kind'] }) {
    if (kind === 'pin') {
        return (
            <svg viewBox="0 0 28 38" fill="none">
                <path
                    d="M14 1c7.2 0 13 5.8 13 13 0 9.4-13 23-13 23S1 23.4 1 14C1 6.8 6.8 1 14 1Z"
                    fill="#FFFFFF"
                />
                <circle cx="14" cy="14" r="4.5" fill="#C7DDF7" />
            </svg>
        );
    }

    if (kind === 'pencil') {
        return (
            <svg viewBox="0 0 44 52" fill="none">
                <path
                    d="M2 3a2 2 0 0 1 2-2h20l10 10v38a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V3Z"
                    stroke="#FFFFFF"
                    strokeWidth="2"
                />
                <path d="M24 1v10h10" stroke="#FFFFFF" strokeWidth="2" />
                <path
                    d="M9 20h14M9 27h14"
                    stroke="#FFFFFF"
                    strokeWidth="2"
                    strokeLinecap="round"
                />
                <path
                    d="m40 30-12 12-4.5 1.5L25 39l12-12a2.1 2.1 0 0 1 3 3Z"
                    stroke="#FFFFFF"
                    strokeWidth="2"
                    strokeLinejoin="round"
                />
            </svg>
        );
    }

    // Paper. `faded` is the same sheet washed out against the panel edge.
    const stroke = kind === 'faded' ? '#C9DCF3' : '#FFFFFF';
    const fill = kind === 'lines' ? 'rgba(255,255,255,0.16)' : 'none';

    return (
        <svg viewBox="0 0 40 50" fill="none">
            <path
                d="M2 4a3 3 0 0 1 3-3h19l14 14v31a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V4Z"
                fill={fill}
                stroke={stroke}
                strokeWidth="2"
            />
            <path
                d="M24 1v11a3 3 0 0 0 3 3h11"
                stroke={stroke}
                strokeWidth="2"
            />
            <path
                d="M9 24h22M9 31h22M9 38h13"
                stroke={stroke}
                strokeWidth="2.2"
                strokeLinecap="round"
            />
        </svg>
    );
}

/** The soft cloud bank the figure stands in front of. */
function CloudBank() {
    return (
        <svg
            viewBox="0 0 600 200"
            preserveAspectRatio="xMidYMax meet"
            className="absolute right-0 bottom-0 h-[38%] w-full"
            aria-hidden="true"
        >
            <g fill="#FFFFFF" opacity="0.28">
                <circle cx="120" cy="190" r="86" />
                <circle cx="250" cy="200" r="104" />
                <circle cx="392" cy="188" r="80" />
                <circle cx="520" cy="200" r="96" />
            </g>
        </svg>
    );
}

export function AuthScene() {
    return (
        <>
            {/*
                Floating stationery, hidden on phones where the card owns the
                screen.

                Positioned inside the SAME max-w-6xl column as the content, not
                against the raw viewport: percentage offsets on the viewport
                drifted away from the card on wide screens and landed underneath
                it on narrow ones.
            */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-0 top-0 bottom-0 mx-auto hidden max-w-6xl overflow-hidden px-4 opacity-70 md:block lg:px-8"
            >
                {MOTIFS.map((motif, index) => (
                    <div
                        key={index}
                        className="absolute -translate-x-1/2 -translate-y-1/2"
                        style={{
                            left: `${motif.x}%`,
                            top: `${motif.y}%`,
                            width: motif.size,
                        }}
                    >
                        <Glyph kind={motif.kind} />
                    </div>
                ))}

                {/* The single cloud that sits behind the top of the card. */}
                <svg
                    viewBox="0 0 120 60"
                    className="absolute top-0 left-[46%] w-28"
                    fill="none"
                >
                    <path
                        d="M20 56h80a18 18 0 0 0 2-35.8A24 24 0 0 0 28 16 16 16 0 0 0 20 56Z"
                        fill="#FFFFFF"
                        opacity="0.22"
                    />
                </svg>
            </div>
        </>
    );
}

/**
 * The right-hand welcome panel. Hidden below `lg`, where the form is the only
 * thing that matters and the illustration would push it off the fold.
 */
export function AuthWelcome() {
    return (
        <div
            className="relative hidden min-h-[520px] flex-1 lg:block"
            data-testid="auth-welcome"
        >
            <CloudBank />

            <div className="relative z-10 pt-16 pl-4 xl:pl-10">
                <p className="text-3xl font-extrabold tracking-tight text-navy xl:text-4xl">
                    Welcome to
                </p>
                <p className="mt-1 text-4xl font-extrabold tracking-tight xl:text-5xl">
                    {/* Grey in the mockup, not a translucent white -- against
                        the gradient a 70% white reads as pale blue. */}
                    <span className="text-[#A9B2C4]">CICTO</span>{' '}
                    <span className="text-[#F0B94A]">Document</span>
                </p>
                <p className="text-4xl font-extrabold tracking-tight text-[#F0B94A] xl:text-5xl">
                    Tracking System
                </p>
                <p className="mt-4 max-w-xs text-sm leading-relaxed font-semibold text-white">
                    Track, Manage and Monitor Documents Efficiently.
                </p>
            </div>

            {/*
                Anchored to the panel's bottom-right corner with a CAPPED
                height. Sizing purely in percent made the same figure a
                different size on every screen, because the panel's height is
                driven by whichever form sits beside it.
            */}
            <img
                src={womanSrc}
                alt=""
                aria-hidden="true"
                className="pointer-events-none absolute right-0 -bottom-8 z-10 h-[70%] max-h-[420px] min-h-[280px] w-auto object-contain object-bottom xl:right-6"
            />
        </div>
    );
}
