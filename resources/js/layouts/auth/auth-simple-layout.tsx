import { usePage } from '@inertiajs/react';
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
 * The Super Admin portal is RED. That is not decoration -- §3 gives that role
 * system-wide access across every office, and someone who reaches that URL by
 * accident should be able to tell before they type a password.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    // Read from the page rather than threaded through props: `portal` is a
    // server prop on the login pages, and the layout is mounted by Inertia
    // without seeing them.
    const portal = (usePage().props as { portal?: string }).portal;
    const danger = portal === 'super-admin';

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
            className={`cicto-auth relative flex min-h-svh flex-col overflow-x-hidden ${
                danger
                    ? 'cicto-auth-danger bg-linear-to-b/srgb from-[#E8544A] to-[#F7C9C4]'
                    : 'bg-linear-to-b/srgb from-brand to-brand-soft'
            }`}
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
                    max-w-[480px] from `sm` up, not only at `lg`. Without it the
                    card grew to the full container between 768px and 1023px and
                    then snapped back to 480px at a single pixel of resize.
                */}
                <main className="mx-auto flex w-full flex-col justify-center sm:max-w-[480px] lg:mx-0 lg:w-[480px] lg:shrink-0">
                    <div className="rounded-2xl bg-white px-6 py-10 shadow-xl sm:px-10 sm:py-14">
                        <CictoLockup />

                        {/*
                            Rendered only when a page supplies one. The login
                            screen omits it and renders its own heading, because
                            its title depends on which of §3's three portals was
                            opened and this static layout prop cannot vary.
                        */}
                        {title && (
                            <div className="mt-8 text-center">
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

                        <div className={title ? 'mt-6' : 'mt-10'}>
                            {children}
                        </div>
                    </div>
                </main>

                <AuthWelcome />
            </div>
        </div>
    );
}
