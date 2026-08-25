import type { ReactNode } from 'react';
import { HERO } from './content';
import { FeatureStrip } from './feature-strip';
import { HeroBackdrop, HeroScene } from './hero-scene';

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
 *   copy left inset      9.0% of width
 *   copy top inset       8.2% of width, measured from the nav's lower edge
 *
 * The last two were 13.8% and 1.6%, taken off the original Figma. The client
 * sent three fresh renders on 2026-08-25 and all three put the copy further
 * left and much further down: 8.7%/8.6%, 9.5%/8.4% and 9.0%/8.2%. Those are
 * ratios of the frame width, so they hold whatever scale each render was
 * captured at -- which is what makes them worth trusting over a single
 * measurement. The copy column's own internal rhythm was left alone: its
 * eyebrow-to-button height already measures within 3% of the renders.
 *
 * Below `lg` the whole thing collapses to ordinary stacked flow, since the
 * Figma only specifies this one desktop frame.
 */
export function Hero({
    authSlot,
    linked = false,
}: {
    /**
     * The call to action beneath the sub-line. Omitted entirely for a
     * signed-in visitor -- the client struck "Login to Your Account" from
     * that spot on 2026-08-26 -- and the wrapper goes with it rather than
     * leaving an `mt-8` hole where the button used to be.
     */
    authSlot?: ReactNode;
    /** Passed through to FeatureStrip; see the note there. */
    linked?: boolean;
}) {
    return (
        // `/srgb` matters: Tailwind v4 interpolates gradients in oklab by
        // default, but Figma works in sRGB. Without it the midtones drift away
        // from the client's sample.
        <section
            id="home"
            className="relative scroll-mt-20 overflow-x-clip bg-linear-to-b/srgb from-brand to-brand-soft"
        >
            {/*
              The nav used to be mounted HERE, inside the gradient. It moved out
              to pages/welcome.tsx because the page now picks between two of
              them: SiteNav's logo-only white bar for a visitor, and the app's
              own AppTopNav once somebody is signed in. Both are opaque, so the
              gradient reads the same either way -- it simply starts at full
              `from-brand` below the bar now instead of behind it.
            */}

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

                <div className="pt-4 lg:pt-[8.2vw] lg:pl-[9vw]">
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
                      link would stretch to the full 36% track and the blue
                      would read as a bar rather than a button.

                      Rendered only when there IS an action, so the signed-in
                      page closes on the sub-line rather than on 32px of empty
                      gradient.
                    */}
                    {authSlot !== undefined && authSlot !== null && (
                        <div className="mt-8 w-fit">{authSlot}</div>
                    )}
                </div>

                <HeroScene className="mt-10 w-full lg:mt-0" />
            </div>

            {/*
              The panel that overlaps the hero art. Full-bleed, and that is the
              alignment fix rather than a simplification: it used to be inset
              `4.1vw` on the left and bleed off the right, which left a rounded
              corner and ~60px of gradient on one side and a hard cut on the
              other. Worse, FeatureStrip centres a max-w-7xl row inside it, so
              an asymmetric panel pushed the three cards ~30px right of every
              other max-w-7xl row on the page -- the nav above them and "Why
              Choose CICTO" below. Spanning the frame puts the cards back on
              the page's grid and makes the band symmetric in one move.
            */}
            <div className="relative z-10 mt-6 bg-surface pt-10 pb-10 lg:mt-[-6vw] lg:pt-[6.5vw] lg:pb-[5vw]">
                <FeatureStrip linked={linked} />
            </div>

            {/* The gradient shows again beneath the panel before the next section. */}
            <div aria-hidden="true" className="hidden lg:block lg:h-[7.4vw]" />
        </section>
    );
}

/**
 * Shared styling for the hero action, so the Login and Logout states are
 * identical.
 *
 * Brand blue at a 6px radius, per the client's 2026-08-25 comp. It was
 * `--color-danger` at 3px -- the old Figma's nav-chip treatment, scaled up
 * when the button moved into the hero -- and red is now the one colour on the
 * page that appears nowhere in the comp. `--color-danger` itself stays in the
 * palette; it just no longer has a job on the landing page.
 *
 * It lives here, and not in site-nav.tsx, because the hero is what renders it
 * now. It is still exported rather than applied internally because the link
 * itself is built in pages/welcome.tsx -- see the note there.
 */
export const heroActionClass =
    'inline-flex items-center rounded-[6px] bg-brand px-7 py-3 text-[15px] font-semibold text-white shadow-[0_1px_2px_rgb(16_42_82/0.15)] transition-opacity hover:opacity-90';
