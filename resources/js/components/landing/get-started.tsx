import type { ReactNode } from 'react';
import { GET_STARTED } from './content';
import { Skyline } from './skyline';

/**
 * The band that closes the landing page: "Ready to Get Started?" over a
 * centred action, with the skyline painted behind both.
 *
 * The page used to end on a bare `<Skyline />`. The client's 2026-08-25 comp
 * puts this call to action on top of it, and the two overlap rather than
 * stack: the heading clears the tallest roofline and sits on the surface
 * ground, while the button lands among the towers. That is why the art is
 * positioned rather than laid out -- the section's own padding sets its height
 * and the image is pinned to the bottom edge underneath.
 *
 * `Skyline` is used as-is, with no changes, because AppTopLayout renders the
 * same component to close every signed-in screen and must keep getting the
 * plain band. Everything specific to this section is applied from here.
 *
 * The art is `-z-10` against an `isolate` section rather than a plain lower
 * `z-index`: without the new stacking context that negative layer would paint
 * behind `body` rather than behind this section's siblings, taking the surface
 * background with it.
 *
 * The action arrives as a slot for the same reason the hero's does -- so the
 * signed-in and signed-out branches are decided once, in pages/welcome.tsx,
 * and the Chisel registration constraint documented there keeps holding.
 */
export function GetStarted({ actionSlot }: { actionSlot: ReactNode }) {
    return (
        <section className="relative isolate overflow-hidden bg-surface">
            {/*
              Bottom-pinned so the ground strip closes the page exactly as it
              did when this was the last element in the document. `w-full` is
              on Skyline already; `inset-x-0` is what stops the absolute box
              from shrink-wrapping the image at its intrinsic width.
            */}
            <Skyline className="absolute inset-x-0 bottom-0 -z-10" />

            <div className="px-6 pt-14 pb-[150px] text-center sm:pb-[190px] lg:pt-16 lg:pb-[13vw]">
                <h2 className="text-[clamp(1.4rem,2.6vw,2rem)] leading-tight font-bold text-balance text-navy">
                    {GET_STARTED.heading}
                </h2>

                {/*
                  The slot centres on the parent's `text-center` because the
                  action is an inline-flex link. Leaving it to text alignment
                  rather than a flex row keeps this block indifferent to
                  whether welcome.tsx hands it a link or a button.
                */}
                <div className="mt-6">{actionSlot}</div>
            </div>
        </section>
    );
}

/**
 * Shared styling for the closing action, so Login and Logout match.
 *
 * Identical to `heroActionClass` apart from the horizontal padding: the comp
 * draws both buttons in brand blue at the same height and radius, but gives
 * this one a wider gutter because it stands alone in its band rather than at
 * the foot of a column of copy that already carries the eye.
 */
export const getStartedActionClass =
    'inline-flex items-center rounded-[6px] bg-brand px-10 py-3 text-[15px] font-semibold text-white shadow-[0_1px_2px_rgb(16_42_82/0.15)] transition-opacity hover:opacity-90';
