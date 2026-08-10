import { cn } from '@/lib/utils';
import type { Tone } from '@/types';

/**
 * Tone comes from the PHP enum (DocumentStatus::tone, DueState::tone), so the
 * colour vocabulary lives in one place on the server rather than being
 * re-derived from status strings in each component.
 */
const TONE_CLASSES: Record<Tone, string> = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-600/20 dark:bg-slate-400/10 dark:text-slate-300 dark:ring-slate-400/30',
    amber: 'bg-amber-100 text-amber-800 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/30',
    sky: 'bg-sky-100 text-sky-800 ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-300 dark:ring-sky-400/30',
    orange: 'bg-orange-100 text-orange-800 ring-orange-600/20 dark:bg-orange-400/10 dark:text-orange-300 dark:ring-orange-400/30',
    red: 'bg-red-100 text-red-800 ring-red-600/20 dark:bg-red-400/10 dark:text-red-300 dark:ring-red-400/30',
    emerald:
        'bg-emerald-100 text-emerald-800 ring-emerald-600/20 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/30',
};

export function ToneBadge({
    tone,
    children,
    className,
}: {
    tone: Tone;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                TONE_CLASSES[tone] ?? TONE_CLASSES.slate,
                className,
            )}
        >
            {children}
        </span>
    );
}
