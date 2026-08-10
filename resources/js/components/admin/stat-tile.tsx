import type { LucideIcon } from 'lucide-react';

/**
 * The four coloured tiles at the top of the Admin Panel.
 *
 * Colour is never the only signal: each tile carries its own label and icon, so
 * the four are still distinguishable in greyscale, on a projector, and to a
 * colour-blind reader. That matters here because the palette leans on
 * red-versus-green, the most common confusion.
 */

export type TileTone = 'total' | 'pending' | 'approved' | 'rejected';

const TONES: Record<TileTone, { surface: string; text: string }> = {
    total: { surface: 'bg-[#7BAE9E]', text: 'text-white' },
    pending: { surface: 'bg-[#EFC65B]', text: 'text-[#4A3A0B]' },
    approved: { surface: 'bg-[#2F7BE0]', text: 'text-white' },
    rejected: { surface: 'bg-[#D5342A]', text: 'text-white' },
};

export function StatTile({
    tone,
    label,
    value,
    icon: Icon,
    active = false,
    onSelect,
}: {
    tone: TileTone;
    label: string;
    value: number;
    icon: LucideIcon;
    active?: boolean;
    onSelect?: () => void;
}) {
    const palette = TONES[tone];

    const body = (
        <>
            <span className="flex items-start gap-2">
                <Icon className="mt-0.5 size-4 shrink-0 opacity-90" />
                <span className="text-sm leading-tight font-medium">
                    {label}
                </span>
            </span>
            <span className="mt-auto text-3xl font-bold tabular-nums">
                {value.toLocaleString()}
            </span>
        </>
    );

    const shell = `flex h-32 flex-col rounded-xl p-4 text-left shadow-sm ${palette.surface} ${palette.text}`;

    // A tile with no filter behind it must not look or behave like a button.
    if (!onSelect) {
        return <div className={shell}>{body}</div>;
    }

    return (
        <button
            type="button"
            onClick={onSelect}
            aria-pressed={active}
            className={`${shell} transition hover:brightness-105 focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:outline-none ${
                active ? 'ring-2 ring-navy ring-offset-2' : ''
            }`}
        >
            {body}
        </button>
    );
}
