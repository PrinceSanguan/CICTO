/**
 * The floating stationery behind the document screens.
 *
 * Decorative, so the whole layer is aria-hidden and pointer-events-none: an
 * absolutely positioned SVG must never swallow a click meant for a table row,
 * and a screen reader announcing "document, cloud, map pin" over a work queue
 * is noise.
 *
 * Positioned inside the SAME max-w-7xl column as the content rather than
 * against the viewport, so the arrangement keeps its relationship to the cards
 * instead of drifting to the edges on a wide monitor. Hidden below `md`, where
 * the content needs the width.
 */

type Motif = {
    /** Percent offsets within the content column. */
    x: number;
    y: number;
    size: number;
    kind: 'lines' | 'page' | 'pin' | 'cloud';
};

const MOTIFS: readonly Motif[] = [
    { x: 40, y: 8, size: 40, kind: 'lines' },
    { x: 55, y: 9, size: 86, kind: 'cloud' },
    { x: 65, y: 6, size: 30, kind: 'pin' },
    { x: 94, y: 5, size: 44, kind: 'page' },
    { x: 2, y: 26, size: 30, kind: 'pin' },
    { x: 97, y: 30, size: 36, kind: 'lines' },
    { x: 3, y: 56, size: 38, kind: 'page' },
    { x: 96, y: 52, size: 36, kind: 'lines' },
];

function Glyph({ kind }: { kind: Motif['kind'] }) {
    if (kind === 'cloud') {
        return (
            <svg viewBox="0 0 120 60" fill="none">
                <path
                    d="M20 56h80a18 18 0 0 0 2-35.8A24 24 0 0 0 28 16 16 16 0 0 0 20 56Z"
                    fill="#FFFFFF"
                    opacity="0.25"
                />
            </svg>
        );
    }

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

    const fill = kind === 'lines' ? 'rgba(255,255,255,0.18)' : 'none';

    return (
        <svg viewBox="0 0 40 50" fill="none">
            <path
                d="M2 4a3 3 0 0 1 3-3h19l14 14v31a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V4Z"
                fill={fill}
                stroke="#FFFFFF"
                strokeWidth="2"
            />
            <path
                d="M24 1v11a3 3 0 0 0 3 3h11"
                stroke="#FFFFFF"
                strokeWidth="2"
            />
            <path
                d="M9 24h22M9 31h22M9 38h13"
                stroke="#FFFFFF"
                strokeWidth="2.2"
                strokeLinecap="round"
            />
        </svg>
    );
}

export function DocumentMotifs() {
    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 mx-auto hidden max-w-7xl overflow-hidden px-4 opacity-70 md:block lg:px-8"
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
        </div>
    );
}
