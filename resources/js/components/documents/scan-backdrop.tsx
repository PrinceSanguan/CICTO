/**
 * The floating paper / pin / cloud motifs behind the scan console.
 *
 * Purely decorative, so the whole layer is aria-hidden and pointer-events-none:
 * a screen reader announcing "document, document, map pin" over a scanning
 * screen is noise, and an invisible SVG must never eat a tap meant for the
 * viewfinder.
 *
 * Positions are percentages rather than fixed pixels so the arrangement holds
 * its composition at any viewport instead of clustering in a corner. The
 * motifs are hidden below `md` — on a phone the viewfinder should own the
 * screen, which is the one place this page is used one-handed.
 */

type Motif = {
    /** Left offset, percent. */
    x: number;
    /** Top offset, percent. */
    y: number;
    /** Rendered width in px at the `lg` breakpoint. */
    size: number;
    kind: 'page' | 'lines' | 'pin' | 'cloud' | 'pencil' | 'stack';
    opacity: number;
};

const MOTIFS: readonly Motif[] = [
    { x: 40, y: 14, size: 46, kind: 'lines', opacity: 0.5 },
    { x: 55, y: 16, size: 78, kind: 'cloud', opacity: 0.42 },
    { x: 64, y: 12, size: 34, kind: 'pin', opacity: 0.55 },
    { x: 81, y: 16, size: 44, kind: 'pencil', opacity: 0.5 },
    { x: 92, y: 11, size: 42, kind: 'page', opacity: 0.5 },
    { x: 8, y: 24, size: 44, kind: 'stack', opacity: 0.65 },
    { x: 3, y: 30, size: 30, kind: 'pin', opacity: 0.5 },
    { x: 95, y: 40, size: 38, kind: 'lines', opacity: 0.45 },
    { x: 96, y: 28, size: 30, kind: 'pin', opacity: 0.5 },
    { x: 3, y: 66, size: 40, kind: 'page', opacity: 0.45 },
    { x: 94, y: 62, size: 40, kind: 'lines', opacity: 0.4 },
];

function Glyph({ kind }: { kind: Motif['kind'] }) {
    switch (kind) {
        case 'cloud':
            return (
                <svg viewBox="0 0 64 40" fill="none">
                    <path
                        d="M16 34h32a11 11 0 0 0 1.6-21.9A15 15 0 0 0 21 10.4 10 10 0 0 0 16 34Z"
                        fill="#8FBFF5"
                    />
                </svg>
            );

        case 'pin':
            return (
                <svg viewBox="0 0 28 38" fill="none">
                    <path
                        d="M14 1c7.2 0 13 5.8 13 13 0 9.4-13 23-13 23S1 23.4 1 14C1 6.8 6.8 1 14 1Z"
                        fill="#FFFFFF"
                        stroke="#C7DDF7"
                        strokeWidth="1.5"
                    />
                    <circle cx="14" cy="14" r="4.5" fill="#BBD6F5" />
                </svg>
            );

        case 'pencil':
            return (
                <svg viewBox="0 0 44 52" fill="none">
                    <rect
                        x="1"
                        y="1"
                        width="34"
                        height="46"
                        rx="3"
                        fill="#FFFFFF"
                        stroke="#C7DDF7"
                        strokeWidth="1.6"
                    />
                    <path
                        d="M8 12h18M8 20h18M8 28h11"
                        stroke="#CBDFF7"
                        strokeWidth="2"
                        strokeLinecap="round"
                    />
                    <path
                        d="m41 22-13 13-4.6 1.6 1.6-4.6 13-13a2.3 2.3 0 0 1 3.3 3.3Z"
                        fill="#FFFFFF"
                        stroke="#B9D3F3"
                        strokeWidth="1.6"
                        strokeLinejoin="round"
                    />
                </svg>
            );

        case 'stack':
            return (
                <svg viewBox="0 0 48 44" fill="none">
                    <rect
                        x="1"
                        y="7"
                        width="32"
                        height="36"
                        rx="3"
                        fill="#FFF7E6"
                        stroke="#F0DDB4"
                        strokeWidth="1.6"
                    />
                    <rect
                        x="12"
                        y="1"
                        width="32"
                        height="36"
                        rx="3"
                        fill="#FFFDF7"
                        stroke="#F0DDB4"
                        strokeWidth="1.6"
                    />
                    <path
                        d="M18 10h20M18 17h20M18 24h13"
                        stroke="#EBD5A6"
                        strokeWidth="2"
                        strokeLinecap="round"
                    />
                </svg>
            );

        case 'page':
        case 'lines':
        default:
            return (
                <svg viewBox="0 0 40 50" fill="none">
                    <path
                        d="M1 4a3 3 0 0 1 3-3h21l14 14v31a3 3 0 0 1-3 3H4a3 3 0 0 1-3-3V4Z"
                        fill="#FFFFFF"
                        stroke="#C7DDF7"
                        strokeWidth="1.6"
                    />
                    <path
                        d="M25 1v11a3 3 0 0 0 3 3h11"
                        stroke="#C7DDF7"
                        strokeWidth="1.6"
                        fill="none"
                    />
                    <path
                        d="M8 24h24M8 31h24M8 38h15"
                        stroke="#CBDFF7"
                        strokeWidth="2.2"
                        strokeLinecap="round"
                    />
                </svg>
            );
    }
}

export function ScanBackdrop() {
    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 hidden overflow-hidden md:block"
        >
            {MOTIFS.map((motif, index) => (
                <div
                    key={index}
                    className="absolute -translate-x-1/2 -translate-y-1/2"
                    style={{
                        left: `${motif.x}%`,
                        top: `${motif.y}%`,
                        width: motif.size,
                        opacity: motif.opacity,
                    }}
                >
                    <Glyph kind={motif.kind} />
                </div>
            ))}
        </div>
    );
}
