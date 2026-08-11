import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

/**
 * The hero every §23 sub-page carries: a back link, a white title and a line
 * of subtitle over the blue band.
 *
 * One component because the three sub-pages had drifted -- two said "Back to
 * Help" where the design says "Back", and the title sizes had diverged -- and
 * this header is the only thing telling a reader which of the three support
 * routes they took.
 */
export function HelpScene({
    back,
    title,
    subtitle,
    children,
}: {
    back: { href: NonNullable<InertiaLinkProps['href']>; label: string };
    title: string;
    subtitle?: string;
    children: React.ReactNode;
}) {
    return (
        <>
            <Link
                href={back.href}
                className="inline-flex items-center gap-1 text-sm font-bold text-white/90 no-underline transition hover:text-white"
            >
                <ChevronLeft aria-hidden="true" className="size-4" />
                {back.label}
            </Link>

            <h1 className="mt-3 text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                {title}
            </h1>

            {subtitle && (
                <p className="mt-2 max-w-prose text-[15px] text-white/90">
                    {subtitle}
                </p>
            )}

            <div className="mt-8">{children}</div>
        </>
    );
}
