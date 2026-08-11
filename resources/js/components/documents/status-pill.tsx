import { cn } from '@/lib/utils';
import type { Tone } from '@/types';

/**
 * The solid status pill from the client's Track Documents design.
 *
 * Distinct from `ToneBadge`, which is the app's small tinted chip used inside
 * dense tables and the timeline. This one is the large filled block the
 * mockups put in the Status column, and it exists separately so restyling the
 * table cannot quietly restyle every badge in the system.
 *
 * `tone` still comes from the PHP enum (DocumentStatus::tone), so the mapping
 * from a workflow state to a colour stays on the server in one place.
 *
 * Colour is never the only signal: the label is always rendered, so the four
 * states remain distinguishable in greyscale and to a colour-blind reader.
 */
const TONE_CLASSES: Record<Tone, string> = {
    slate: 'bg-[#9AA7B8] text-white',
    amber: 'bg-[#EFC65B] text-[#4A3A0B]',
    sky: 'bg-[#7BAE9E] text-white',
    orange: 'bg-[#DD7A4E] text-white',
    red: 'bg-[#D5342A] text-white',
    emerald: 'bg-[#7BAE9E] text-white',
};

export function StatusPill({
    tone,
    label,
    className,
}: {
    tone: Tone;
    label: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-block min-w-[9rem] rounded-md px-4 py-2.5 text-center text-sm font-bold',
                TONE_CLASSES[tone] ?? TONE_CLASSES.slate,
                className,
            )}
        >
            {label}
        </span>
    );
}
