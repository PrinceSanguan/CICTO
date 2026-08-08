import type { ComponentType } from 'react';
import type { FeatureIcon } from './content';

/**
 * The Figma's feature icons, rebuilt as inline SVG.
 *
 * These are filled, multicoloured and illustrative -- a four-colour pie chart,
 * a two-tone folder, a shield with a knocked-out padlock -- which lucide's
 * single-stroke set cannot express, so they are drawn here. Inline SVG is also
 * what the rest of this repo already does for logos and marks (see
 * components/app-logo-icon.tsx).
 *
 * All are decorative: the adjacent card title carries the meaning, so each is
 * marked aria-hidden by the consuming card.
 */

type IconProps = { className?: string };

/** Green map pin standing on an isometric stack of paper. */
function TrackingIcon({ className }: IconProps) {
    return (
        <svg viewBox="0 0 48 48" className={className} aria-hidden="true">
            {/* paper stack, back to front */}
            <path d="M24 28 4 36l20 8 20-8-20-8Z" fill="#E4EAF2" />
            <path d="M24 22 8 28.5 24 35l16-6.5L24 22Z" fill="#F2F5FA" />
            <path
                d="M24 22 8 28.5 24 35l16-6.5L24 22Z"
                fill="none"
                stroke="#CBD5E3"
                strokeWidth="1"
            />
            {/* pin */}
            <path
                d="M24 4c-5 0-9 4-9 9 0 6.6 9 17 9 17s9-10.4 9-17c0-5-4-9-9-9Z"
                fill="#2E8150"
            />
            <circle cx="24" cy="13" r="3.4" fill="#FFFFFF" />
        </svg>
    );
}

/**
 * QR mark. A fixed module map -- the finder squares are correct, the payload
 * field is decorative. It is an icon, not a scannable code.
 */
const QR_ROWS = [
    '1111111010110101111111',
    '1000001011010101000001',
    '1011101000101101011101',
    '1011101011011001011101',
    '1011101001100101011101',
    '1000001010011001000001',
    '1111111010101011111111',
    '0000000011001000000000',
    '1101101101011010110110',
    '0100110010110101001101',
    '1110011011001010110011',
    '0011100100110101100101',
    '1101011011010010011010',
    '0010110100101101101001',
    '1100101011010110010110',
    '0000000011010010110101',
    '1111111001011011001011',
    '1000001010110100110100',
    '1011101011001011010011',
    '1011101000110110101100',
    '1000001011010011011010',
    '1111111010101100110101',
];

/** Built once at module scope -- static data, and keeps render pure. */
const QR_PATH = QR_ROWS.flatMap((row, y) =>
    row
        .split('')
        .map((bit, x) => (bit === '1' ? `M${x} ${y}h1v1h-1z` : ''))
        .filter(Boolean),
).join('');

function QrIcon({ className }: IconProps) {
    return (
        <svg
            viewBox={`0 0 ${QR_ROWS.length} ${QR_ROWS.length}`}
            className={className}
            shapeRendering="crispEdges"
            aria-hidden="true"
        >
            <path d={QR_PATH} fill="#111111" />
        </svg>
    );
}

/** Blue bar chart on a baseline. */
function ReportsIcon({ className }: IconProps) {
    const bars = [
        { x: 2, h: 18 },
        { x: 11, h: 30 },
        { x: 20, h: 12 },
        { x: 29, h: 34 },
        { x: 38, h: 22 },
    ];

    return (
        <svg viewBox="0 0 48 48" className={className} aria-hidden="true">
            {bars.map((bar) => (
                <rect
                    key={bar.x}
                    x={bar.x}
                    y={40 - bar.h}
                    width="7"
                    height={bar.h}
                    rx="1"
                    fill="#0D7AD4"
                />
            ))}
            <rect x="0" y="41" width="48" height="2" rx="1" fill="#0D7AD4" />
        </svg>
    );
}

/** Two-tone folder with a circular back-arrow badge. */
function FolderIcon({ className }: IconProps) {
    return (
        <svg viewBox="0 0 48 48" className={className} aria-hidden="true">
            <path
                d="M4 12a3 3 0 0 1 3-3h11l4 5h15a3 3 0 0 1 3 3v4H4v-9Z"
                fill="#5FA3E8"
            />
            <path
                d="M4 18h36a3 3 0 0 1 3 3v14a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V18Z"
                fill="#BBD6F5"
            />
            <circle cx="34" cy="34" r="8" fill="#1B4F8A" />
            <path
                d="M36 30l-4 4 4 4"
                fill="none"
                stroke="#FFFFFF"
                strokeWidth="2.2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

/** Blue shield with an inner keyline and a knocked-out padlock. */
function ShieldIcon({ className }: IconProps) {
    return (
        <svg viewBox="0 0 48 48" className={className} aria-hidden="true">
            <path
                d="M24 3 6 10v13c0 11 7.6 19.4 18 22 10.4-2.6 18-11 18-22V10L24 3Z"
                fill="#1E8BE0"
            />
            <path
                d="M24 8.2 11 13.3v9.7c0 8.6 5.7 15.3 13 17.6 7.3-2.3 13-9 13-17.6v-9.7L24 8.2Z"
                fill="none"
                stroke="#FFFFFF"
                strokeWidth="1.6"
            />
            <rect x="17" y="22" width="14" height="11" rx="2" fill="#FFFFFF" />
            <path
                d="M20 22v-3.2a4 4 0 0 1 8 0V22"
                fill="none"
                stroke="#FFFFFF"
                strokeWidth="2.4"
            />
            <circle cx="24" cy="27" r="1.9" fill="#1E8BE0" />
            <rect x="23.1" y="27" width="1.8" height="4" fill="#1E8BE0" />
        </svg>
    );
}

/** Four-segment pie chart. */
function AnalyticsIcon({ className }: IconProps) {
    return (
        <svg viewBox="0 0 48 48" className={className} aria-hidden="true">
            {/* upper-left wedge */}
            <path d="M24 24 6 20A18.5 18.5 0 0 1 24 5.5V24Z" fill="#EE4E34" />
            <path d="M24 24V5.5A18.5 18.5 0 0 1 33 8L24 24Z" fill="#8CBF3F" />
            <path
                d="M24 24 33 8a18.5 18.5 0 0 1 8.9 13.4L24 24Z"
                fill="#2C9A9A"
            />
            <path
                d="M24 24l17.9-2.6A18.5 18.5 0 1 1 6 20l18 4Z"
                fill="#2B3A4A"
            />
        </svg>
    );
}

const REGISTRY: Record<FeatureIcon, ComponentType<IconProps>> = {
    tracking: TrackingIcon,
    qr: QrIcon,
    reports: ReportsIcon,
    folder: FolderIcon,
    shield: ShieldIcon,
    analytics: AnalyticsIcon,
};

export function FeatureGlyph({
    name,
    className,
}: {
    name: FeatureIcon;
    className?: string;
}) {
    const Glyph = REGISTRY[name];

    return <Glyph className={className} />;
}
