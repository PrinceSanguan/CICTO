import type { ReactNode } from 'react';
import { HERO } from './content';
import { FeatureStrip } from './feature-strip';
import { HeroBackdrop, HeroScene } from './hero-scene';
import { SiteNav } from './site-nav';

/**
 * Hero band, built to the proportions measured off the client's Figma.
 *
 * Those ratios, relative to the frame width, are what the vw-based sizing
 * below is reproducing:
 *
 *   hero height          0.700 x width
 *   art width            0.645 x width, bleeding to the right edge
 *   panel top            62.4% down the hero
 *   panel bottom         89.5% down the hero  (the gradient shows again below
 *                        it -- the panel is a floating card, not the next
 *                        section arriving early)
 *   copy left inset      13.8% of width
 *
 * Below `lg` the whole thing collapses to ordinary stacked flow, since the
 * Figma only specifies this one desktop frame.
 */
export function Hero({ authSlot }: { authSlot: ReactNode }) {
    return (
        // `/srgb` matters: Tailwind v4 interpolates gradients in oklab by
        // default, but Figma works in sRGB. Without it the midtones drift away
        // from the client's sample.
        <section
            id="home"
            className="relative scroll-mt-20 overflow-x-clip bg-linear-to-b/srgb from-brand to-brand-soft"
        >
            <SiteNav />

            {/*
              No max-width container here. In the Figma the art runs to the
              frame's right edge and the copy is inset by a share of the frame
              width, so both are expressed against the viewport directly --
              that is simpler and more faithful than fighting a centred
              container with negative margins.
            */}
            <div className="relative grid items-start px-6 lg:grid-cols-[36%_64%] lg:px-0">
                {/*
                  Anchored to the bottom of the art row so it lands just above
                  the panel, which is where the Figma puts it. Inside the grid
                  and first in DOM order, so the copy paints over it.
                */}
                <HeroBackdrop className="pointer-events-none absolute bottom-0 left-0 hidden w-[46%] lg:block" />

                <div className="pt-4 lg:pt-[1.6vw] lg:pl-[13.8vw]">
                    <p className="text-[13px] font-medium text-white">
                        {HERO.eyebrow}
                    </p>

                    {/*
                      Sized so "Your Documents" holds one line and spans ~23%
                      of the viewport, matching the Figma.
                    */}
                    <h1 className="mt-3 text-[clamp(1.55rem,2.25vw,2rem)] leading-[1.18] font-bold text-white">
                        {HERO.headingLine1}
                        <br />
                        {HERO.headingLine2}
                    </h1>

                    <p className="mt-6 text-[14px] leading-relaxed text-white">
                        {HERO.subLine1}
                        <br className="hidden sm:inline" /> {HERO.subLine2}
                    </p>

                    {/*
                      The auth action, which the client asked to sit here
                      rather than in the nav. This is the only thing in the
                      copy column below the sub-line, so it lands in the empty
                      band between the copy and the floating panel -- the area
                      circled on the screenshot.

                      `w-fit` because the column is a grid cell: without it the
                      link would stretch to the full 36% track and the red
                      would read as a bar rather than a button.
                    */}
                    <div className="mt-8 w-fit">{authSlot}</div>
                </div>

                <HeroScene className="mt-10 w-full lg:mt-0" />
            </div>

            {/* Floating panel. Inset on the left only; bleeds off the right. */}
            <div className="relative z-10 mt-6 ml-0 rounded-tl-[28px] bg-surface pt-10 pb-10 lg:mt-[-6vw] lg:ml-[4.1vw] lg:rounded-tl-[52px] lg:pt-[6.5vw] lg:pb-[5vw]">
                <FeatureStrip />
            </div>

            {/* The gradient shows again beneath the panel before the next section. */}
            <div aria-hidden="true" className="hidden lg:block lg:h-[7.4vw]" />
        </section>
    );
}

/**
 * Shared styling for the red action, so the Login and Logout states are
 * identical. This is the Figma's nav-chip treatment (#df1c12 at a 3px radius)
 * scaled up for its new home: at hero size the 13px chip read as a stray
 * control rather than the page's primary call to action.
 *
 * It lives here, and not in site-nav.tsx, because the hero is what renders it
 * now. It is still exported rather than applied internally because the link
 * itself is built in pages/welcome.tsx -- see the note there.
 */
export const heroActionClass =
    'inline-flex items-center rounded-[3px] bg-danger px-6 py-2.5 text-[14px] font-semibold text-white transition-opacity hover:opacity-90';
