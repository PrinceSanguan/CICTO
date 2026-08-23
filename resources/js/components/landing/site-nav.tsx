import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { BrandLogo } from './brand-logo';
import { NAV } from './content';

/**
 * The nav sits directly on the hero gradient, exactly as the Figma has it --
 * no white bar. Link colours are the sampled values: the current item is
 * #1079CF and the rest are #182663.
 *
 * The nav carries no auth action. The client asked for the Login button to sit
 * in the hero copy, under the "Welcome to CICTO Document Tracking System"
 * line, so `Hero` renders `authSlot` there instead of handing it to this
 * component. See hero.tsx.
 *
 * That is also why the row is a three-column grid from `lg` up rather than a
 * flex row: the Figma centres the links against the frame, and it only looked
 * centred before because the logo on the left and the Login button on the
 * right happened to weigh the same. With the button gone, `justify-center`
 * inside a single flexible track would push the links ~56px to the right of
 * true centre. `1fr auto 1fr` with an empty third column centres them against
 * the frame whatever the logo measures.
 */
export function SiteNav() {
    return (
        <header className="relative z-20">
            <div className="mx-auto flex max-w-7xl flex-col gap-3 px-6 py-3 lg:grid lg:grid-cols-[1fr_auto_1fr] lg:items-center lg:gap-8">
                <a
                    href="#home"
                    className="shrink-0 self-start lg:self-auto lg:justify-self-start"
                >
                    {/*
                      The lockup is square with a stacked wordmark, so it needs
                      real height for "BALIWAG" to stay legible.

                      `lg:justify-self-start` is load-bearing, not tidying. In
                      the grid the anchor is a blockified grid item, so it
                      stretches across the whole 1fr track -- ~390px at 1440 --
                      while the image inside stays 48px wide at the left edge.
                      That looks identical in a screenshot but leaves the rest
                      of the track as an invisible #home click target with the
                      focus ring drawn around all of it. `shrink-0` does not
                      prevent it: flex-shrink is inert under grid.
                    */}
                    <BrandLogo className="h-11 lg:h-12" />
                </a>

                <nav
                    aria-label="Main"
                    className="flex flex-wrap items-center justify-start gap-x-6 gap-y-2 lg:gap-x-14"
                >
                    {NAV.map((item) => {
                        const className = cn(
                            'text-[13px] font-medium whitespace-nowrap transition-opacity hover:opacity-70',
                            item.current
                                ? 'text-link-active'
                                : 'text-navy-soft',
                        );

                        /*
                            An in-page anchor stays a plain <a>: Inertia would
                            treat "#home" as a visit to a URL rather than a
                            scroll. The three feature items are real screens, so
                            they are Inertia visits -- the same as the Login
                            action in the hero below, which is what proves the
                            landing page's `cicto-landing` cleanup runs on the
                            way out.
                        */
                        return item.kind === 'anchor' ? (
                            <a
                                key={item.label}
                                href={item.href}
                                aria-current={item.current ? 'page' : undefined}
                                className={className}
                            >
                                {item.label}
                            </a>
                        ) : (
                            <Link
                                key={item.label}
                                href={item.href}
                                className={className}
                            >
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>
            </div>
        </header>
    );
}
