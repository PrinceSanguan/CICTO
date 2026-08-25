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
    /**
     * Hidden from `lg` up, where the card claims the left half of the screen
     * and these would be sliced in half by its edge -- which reads as a
     * rendering fault rather than as decoration.
     */
    leftOfCard?: boolean;
};

const MOTIFS: readonly Motif[] = [
    { x: 4, y: 7, size: 46, kind: 'pencil', leftOfCard: true },
    { x: 4, y: 40, size: 40, kind: 'faded', leftOfCard: true },
    // Kept clear of the title block, which spans roughly y 10-35% on the right
    // half. A motif sitting on the word "Document" reads as a rendering fault.
    { x: 63, y: 4, size: 34, kind: 'pin' },
    { x: 73, y: 3, size: 34, kind: 'lines' },
    { x: 92, y: 7, size: 40, kind: 'page' },
    { x: 96, y: 20, size: 30, kind: 'pin' },
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
                        className={`absolute -translate-x-1/2 -translate-y-1/2 ${
                            motif.leftOfCard ? 'lg:hidden' : ''
                        }`}
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

            {/*
                `mx-auto w-fit` centres the title BLOCK in the panel while its
                lines stay left-aligned to each other, which is how the comp
                sets it. It used to be `pl-4 xl:pl-10`, pinning the block to the
                panel's left edge -- so on a wide screen it sat hard against the
                card with the whole right half of the panel empty, and the
                client marked it up on 2026-08-25: "dapat nasa center o katapat
                mismo nito". `w-fit` takes its width from "CICTO Document", the
                longest line, so the centring is measured off the title mass
                rather than off the panel.
            */}
            <div className="relative z-10 mx-auto w-fit px-4 pt-28">
                {/*
                    "Welcome to" is deliberately smaller than the three lines
                    under it -- roughly three quarters -- and the whole block is
                    set tight, so the title reads as one mass rather than four
                    separate sentences.
                */}
                {/*
                    No `xl:` step up any more. The card is 600px from `lg` up
                    rather than 480px, which leaves this panel about 464px
                    inside the max-w-6xl row; "CICTO Document" measures ~400px
                    at text-5xl and ~500px at text-6xl, so the old xl bump would
                    have wrapped the title the moment the card grew. The comp
                    sets it at text-5xl at every width, so matching it and
                    fixing the overflow are the same edit.
                */}
                <p className="text-4xl font-extrabold tracking-tight text-navy">
                    Welcome to
                </p>

                <p className="mt-1 text-5xl leading-[1.05] font-extrabold tracking-tight">
                    {/* Grey, not a translucent white -- against the gradient a
                        70% white reads as pale blue rather than silver. */}
                    <span className="text-[#9AA3B4]">CICTO</span>{' '}
                    <span className="text-[#F0B94A]">Document</span>
                </p>
                <p className="text-5xl leading-[1.05] font-extrabold tracking-tight text-[#F0B94A]">
                    Tracking System
                </p>

                {/*
                    Centred within its own column rather than against the panel:
                    the design balances the two lines with each other while the
                    block stays under the left of the title.
                */}
                <p className="mt-6 max-w-[19rem] text-center text-base leading-relaxed font-semibold text-white xl:text-lg">
                    Track, Manage and Monitor Documents Efficiently.
                </p>
            </div>

            {/*
                Anchored to the panel's bottom-LEFT corner, mirrored, with a
                CAPPED height. Sizing purely in percent made the same figure a
                different size on every screen, because the panel's height is
                driven by whichever form sits beside it.
            */}
            {/*
                `-scale-x-100` and the move from `right-0` to `left-0` are one
                change, not two. The artwork has her turned to the viewer's
                right with the laptop out on that side, so at the panel's right
                edge she faced the empty margin and read as walking off the
                page -- "nakaharap rin dapat 'to sa may verification" on the
                client's markup. Mirrored at the left edge she stands beside
                the card and looks into it.

                The flip is applied here rather than to the file because
                hero-scene.tsx uses the same asset on the landing page, where
                she faces the document art on her right and is already correct.
            */}
            {/*
                Sizing has to account for the ASSET, not just the box: the PNG
                is 360x640 with the figure occupying only rows 85-574, so she
                fills 76.4% of whatever height is set here and the rest is
                transparent. That is why `max-h-[460px]` drew a 351px woman
                rather than a 460px one. 72% of the panel the taller card now
                drives to ~700px is ~503px of box and ~384px of figure, which
                is what the comp measures; the cap is 520 so the longer forms
                (register, reset) grow her rather than clipping.

                `-bottom-14` cancels that same transparency at the foot -- the
                asset leaves ~10% of its height empty below her shoes, so
                without it she floats above the ground band instead of standing
                on it.

                `-left-8` is the comp's overlap: the row already puts a 24px
                gap between the card and this panel, so -32px leaves her
                standing 8px in FRONT of the card's right edge -- well inside
                the card's own 48px gutter, so she can never cover a field.
            */}
            <img
                src={womanSrc}
                alt=""
                aria-hidden="true"
                className="pointer-events-none absolute -bottom-14 -left-8 z-10 h-[72%] max-h-[520px] min-h-[300px] w-auto -scale-x-100 object-contain object-bottom"
            />
        </div>
    );
}
