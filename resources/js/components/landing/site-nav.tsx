import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { BrandLogo } from './brand-logo';
import { NAV } from './content';

/**
 * The nav sits directly on the hero gradient, exactly as the Figma has it --
 * no white bar. Link colours are the sampled values: the current item is
 * #1079CF and the rest are #182663.
 *
 * `authSlot` is passed in from the page rather than built here because
 * Chisel's registration markers are keyed to pages/welcome.tsx; a `register()`
 * call in this file would survive `php artisan chisel` and leave a dangling
 * import. See the note in welcome.tsx.
 */
export function SiteNav({ authSlot }: { authSlot: ReactNode }) {
    return (
        <header className="relative z-20">
            <div className="mx-auto flex max-w-7xl flex-col gap-3 px-6 py-3 lg:flex-row lg:items-center lg:gap-8">
                <a href="#home" className="shrink-0 self-start lg:self-auto">
                    {/*
                      The lockup is square with a stacked wordmark, so it needs
                      real height for "BALIWAG" to stay legible.
                    */}
                    <BrandLogo className="h-11 lg:h-12" />
                </a>

                <nav
                    aria-label="Main"
                    className="flex flex-1 flex-wrap items-center justify-start gap-x-6 gap-y-2 lg:justify-center lg:gap-x-14"
                >
                    {NAV.map((item) => (
                        <a
                            key={item.label}
                            href={item.href}
                            aria-current={item.current ? 'page' : undefined}
                            className={cn(
                                'text-[13px] font-medium whitespace-nowrap transition-opacity hover:opacity-70',
                                item.current
                                    ? 'text-link-active'
                                    : 'text-navy-soft',
                            )}
                        >
                            {item.label}
                        </a>
                    ))}
                </nav>

                <div className="flex shrink-0 items-center gap-4">
                    <span
                        aria-hidden="true"
                        className="hidden h-6 w-px bg-white/45 lg:block"
                    />
                    {authSlot}
                </div>
            </div>
        </header>
    );
}

/** Shared styling for the red action button, so both states are identical. */
export const navActionClass =
    'inline-flex items-center rounded-[3px] bg-danger px-4 py-1.5 text-[13px] font-semibold text-white transition-opacity hover:opacity-90';
