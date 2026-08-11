import { useState } from 'react';

/**
 * "3 days ago", for the Last Login column on both user screens.
 *
 * Rendered client-side so each reader sees their own locale, and relative
 * because "when were they last here" is the question that column answers.
 *
 * "now" is captured in a lazy useState initializer rather than read during
 * render: Date.now() is impure, and calling it in the render body makes the
 * output depend on whenever React happens to re-render. Sampled once per mount
 * is both pure and accurate enough for a column measured in days.
 */
export function LastLogin({ iso }: { iso: string | null }) {
    const [now] = useState(() => Date.now());

    if (!iso) {
        return <span className="text-[#8A9AAE]">Never</span>;
    }

    const minutes = Math.round((now - new Date(iso).getTime()) / 60000);

    if (minutes < 1) {
        return <>Just now</>;
    }

    if (minutes < 60) {
        return (
            <>
                {minutes} minute{minutes === 1 ? '' : 's'} ago
            </>
        );
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return (
            <>
                {hours} hour{hours === 1 ? '' : 's'} ago
            </>
        );
    }

    const days = Math.round(hours / 24);

    if (days === 1) {
        return <>Yesterday</>;
    }

    if (days < 7) {
        return <>{days} days ago</>;
    }

    const weeks = Math.round(days / 7);

    if (weeks < 5) {
        return (
            <>
                {weeks} week{weeks === 1 ? '' : 's'} ago
            </>
        );
    }

    return <>{new Date(iso).toLocaleDateString()}</>;
}
