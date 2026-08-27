import { useEffect } from 'react';
import { AuthScene, AuthWelcome } from '@/components/auth/auth-scene';
import { CictoLockup } from '@/components/auth/cicto-lockup';
import { refreshTheme } from '@/hooks/use-appearance';
import type { AuthLayoutProps } from '@/types';

/**
 * The shell behind every Fortify screen: log in, register, forgot and reset
 * password, verify email, two-factor challenge and password confirmation.
 *
 * One component rather than a per-page background, because these six screens
 * are the only pages an unauthenticated visitor ever sees and they have to look
 * like one system. The starter kit's centred dark card was replaced wholesale.
 *
 * It used to paint itself RED behind the Super Admin login, to warn whoever
 * reached that URL by accident. That went with the portals themselves on
 * 2026-08-17: there is one login screen now, and it cannot know which role is
 * about to sign in -- nothing does, until after the credentials check.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    // The client's auth design has no dark variant, and these screens are shown
    // before anyone has an account, let alone an appearance preference.
    //
    // Blade already stamps `data-force-light` on a full page load. This covers
    // the CLIENT-SIDE arrival -- signing out of a dark session, say -- and
    // removes it again on the way out so the app keeps whatever the user chose.
    // The flag is the single source of truth: applyTheme() reads it, so the
    // system-preference listener cannot re-darken the page underneath us.
    useEffect(() => {
        const root = document.documentElement;
        const alreadyFlagged = root.hasAttribute('data-force-light');

        if (!alreadyFlagged) {
            root.setAttribute('data-force-light', '');
            refreshTheme();
        }

        return () => {
            if (!alreadyFlagged) {
                root.removeAttribute('data-force-light');
                refreshTheme();
            }
        };
    }, []);

    return (
        <div
            // overflow-x only: clipping the Y axis made the tallest form
            // (Register) unreachable below its own height.
            //
            // `clip` rather than `hidden` for the same reason app-top-layout
            // uses it -- `hidden` forces the Y axis to `auto` and quietly turns
            // this into a scroll container, which is how a second scrollbar
            // appears next to the document's. `clip` leaves Y `visible`.
            className="cicto-auth relative flex min-h-svh flex-col overflow-x-clip bg-linear-to-b/srgb from-brand to-brand-soft"
        >
            {/*
                The pale ground band the figure stands on. Sits behind
                everything, at the height the client's mockups put it.
            */}
            <div
                aria-hidden="true"
                className="absolute inset-x-0 bottom-0 h-[32%] bg-surface"
            />

            <AuthScene />

            <div className="relative z-10 mx-auto flex w-full max-w-6xl flex-1 items-stretch gap-6 px-4 py-8 lg:px-8">
                {/*
                    max-w-[600px] from `sm` up, not only at `lg`. Without it the
                    card grew to the full container between 768px and 1023px and
                    then snapped back at a single pixel of resize.

                    600 rather than the comp's measured ~648: the row is
                    max-w-6xl, so every pixel the card takes comes off the
                    welcome panel beside it, and "CICTO Document" needs ~400px
                    on one line. 600 leaves the panel ~464px -- comfortable --
                    where 648 leaves ~416px and puts the title one font-metric
                    difference away from wrapping. The card still grows 25% and
                    reads as the comp's dominant element.
                */}
                <main className="mx-auto flex w-full flex-col justify-center sm:max-w-[600px] lg:mx-0 lg:w-[600px] lg:shrink-0">
                    {/*
                        The card FILLS the row's height and centres its content,
                        rather than sizing to its content inside fat padding.

                        That padding is what broke the login screen: the comp
                        for the verify page has ~108px above the lockup and
                        ~158px below the last line, and reproducing it literally
                        made every card 266px taller than its content. Verify
                        has ~474px of content and survived it; login has ~534px
                        and the card ran off the bottom of an 800px viewport
                        with a scrollbar. There is no fixed padding that can
                        satisfy both -- the comp's own numbers do not fit the
                        login form at the height the comp was drawn at.

                        Filling the height satisfies both at once. A short form
                        gets the comp's generous card, because the leftover
                        space becomes padding automatically; a long form gets
                        the same card with the space squeezed out, and it always
                        fits the viewport exactly. The comp agrees: its card is
                        760px in an 854px frame, which is the full height less
                        the row's own py-8.

                        `flex-1` cannot clip anything. Flex items default to
                        `min-height: auto`, so the card grows past the row when
                        the content genuinely needs it -- the tallest form
                        (Register) on a short phone still scrolls rather than
                        losing its top edge.
                    */}
                    <div className="flex flex-1 flex-col justify-center rounded-2xl bg-white px-6 py-10 shadow-xl sm:px-12 sm:py-14">
                        {/*
                            Centred and scaled up, per the comp. It was
                            left-aligned at its natural size, which read as a
                            letterhead rather than as the head of a centred
                            card -- every other thing in here is centred.
                            `scale-125` is the same idiom app-top-nav.tsx uses
                            to take it the other way (`scale-90`), and it does
                            not affect layout: the 56px mark still reserves
                            56px, and the 70px it paints eats into the gap
                            below, which is why that gap grew to `mt-12`.
                        */}
                        <CictoLockup className="scale-125 justify-center" />

                        {/*
                            Rendered only when a page supplies one. The login
                            screen used to omit it and render its own heading,
                            because the title varied with §3's three portals and
                            a static layout prop cannot. With one login screen it
                            no longer varies, so login passes a title like every
                            other page here. The two-factor challenge is the one
                            page left with none, deliberately: it renders its own
                            centred block and a heading above it would repeat it.
                        */}
                        {title && (
                            <div className="mt-16 text-center">
                                <h1 className="text-2xl font-bold text-navy">
                                    {title}
                                </h1>
                                {description && (
                                    <p className="mt-1 text-sm text-copy">
                                        {description}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className={title ? 'mt-16' : 'mt-20'}>
                            {children}
                        </div>
                    </div>
                </main>

                <AuthWelcome />
            </div>
        </div>
    );
}
