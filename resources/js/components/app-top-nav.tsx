import { Link, usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useState } from 'react';
import { CictoLockup } from '@/components/auth/cicto-lockup';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { navFor, panelHomeFor } from '@/lib/nav';
import { login, logout } from '@/routes';

/**
 * §4's main navigation: Track Documents, Reports, Help -- with Home carried by
 * the lockup rather than by a link of its own.
 *
 * The labels are contract acceptance criteria and come from `navFor`, the same
 * source the sidebar uses, so the two panels can never drift apart. Navigation
 * is a hint and never a gate -- a link hidden here is still refused with a 403
 * if the URL is typed.
 *
 * Admins additionally get a button back into their panel. It sits beside
 * Logout rather than in the row, so §4's named set is still exactly the items
 * it lists -- see `panelHomeFor` for why that mattered.
 */
export function AppTopNav() {
    const { auth } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const [open, setOpen] = useState(false);

    /*
        Rendered as they come, with no filtering left to do.

        Two rules used to live here. The first rewrote Home's href to '/' for a
        signed-out visitor, back when `Home` meant the dashboard to one audience
        and the landing page to the other; lib/nav.ts settled that by pointing
        both at '/'. The second dropped Home from the row while you were ON
        Home, so the bar showed three items there and four everywhere else.

        The client removed the Home link outright on 2026-08-27 -- the logo is
        the way home now -- which retires both. The row is the same three items
        on every screen, and the destination is not lost: the lockup below
        links '/' and names itself for a screen reader.
    */
    const items = navFor(auth?.role).main;
    /*
        The contextual button beside Logout: an Admin or Super Admin gets their
        panel, everybody else gets nothing. A plain user briefly got a "My
        Dashboard" button here so §18 stayed reachable once Home stopped
        pointing at it; the client struck that on 2026-08-26, asking for the
        main items and nothing else.
    */
    const panel = panelHomeFor(auth?.role);
    const PanelIcon = panel?.icon;

    return (
        <header className="relative z-30 bg-white shadow-sm">
            <div className="mx-auto flex h-20 w-full max-w-7xl items-center gap-4 px-4 lg:px-8">
                {/*
                    `group` so the lockup INSIDE can animate: the scale lives on
                    CictoLockup (it is already `scale-90` to fit the h-20 bar),
                    and a second scale utility on the same element is what a
                    hover variant overrides cleanly. Scaling the <Link> instead
                    would compound the two transforms.

                    0.9 -> 0.945 is deliberately small. This is the only way
                    home now that §4's Home link is gone, so it has to read as a
                    control -- but it is a masthead, not a button, and anything
                    larger made the bar look like it was breathing.
                */}
                <Link
                    href="/"
                    className="group shrink-0"
                    aria-label="CICTO home"
                >
                    <CictoLockup className="origin-left scale-90 transition-transform duration-300 ease-out motion-safe:group-hover:scale-[0.945] motion-safe:group-active:scale-[0.875]" />
                </Link>

                {/*
                    gap-6 at `lg`, not gap-8: the Admin Panel button in the
                    right-hand cluster costs ~180px, and at exactly 1024px the
                    old spacing left "Track Documents" wrapping inside the
                    fixed h-20 bar. The gaps are a FLOOR, not the spacing --
                    `justify-evenly` shares out whatever is left over.

                    Evenly, not centred. Centring packs the items at their gap
                    width in the middle of the track and dumps the remainder in
                    one lump against the divider -- at three items that lump was
                    ~310px and the nav read as left-aligned with a hole in it.
                    Every comp the client has sent spaces these ~150px apart
                    with a comparable gap before the divider, which is what
                    evenly produces, without a per-count width to maintain.
                */}
                <nav
                    aria-label="Main"
                    className="hidden flex-1 items-center justify-evenly gap-6 lg:flex xl:gap-14"
                >
                    {items.map((item) => {
                        const current = isCurrentUrl(item.href);

                        return (
                            /*
                                The hover TARGET never moves; only what is
                                painted inside it does. That split is the whole
                                reason for the inner <span>.

                                The lift used to sit on the <Link> itself, which
                                made the boundary chase the cursor: hover moved
                                the box up 2px, a pointer near the bottom edge
                                fell outside it, :hover dropped, the box fell
                                back under the pointer, and it started again --
                                several times a second, which is the stutter the
                                client caught mid-wipe. With no vertical padding
                                on these links that unstable edge sat directly
                                under the word, so it was easy to land on.

                                `py-3` widens the target as well, so the whole
                                word plus a comfortable margin is one steady
                                box. It costs no layout: the links are flex
                                items in an `items-center` row inside a fixed
                                h-20 bar, so 42px of item still centres exactly
                                where 18px did.
                            */
                            <Link
                                key={item.title}
                                href={item.href}
                                aria-current={current ? 'page' : undefined}
                                className={`group relative block py-3 text-[15px] font-bold whitespace-nowrap transition-colors duration-200 ease-out ${
                                    current
                                        ? 'text-link'
                                        : 'text-navy hover:text-link'
                                }`}
                            >
                                <span className="block transition-transform duration-200 ease-out group-active:scale-[0.97] motion-safe:group-hover:-translate-y-0.5">
                                    {item.title}
                                </span>

                                {/*
                                    Drawn for the page you are ON, and drawn on
                                    hover for the ones you are not. The client
                                    asked for the current item to carry the rule
                                    on 2026-08-27, so blue text is no longer the
                                    only marker -- which is the stronger signal
                                    anyway, since `text-link` on white is easy
                                    to miss at a glance.

                                    The two states share one element on purpose.
                                    The nav does NOT remount between pages, so
                                    when `current` moves the class flips from
                                    scale-x-0 to scale-x-100 on the item you
                                    picked and back on the one you left, and the
                                    transition below animates both -- the rule
                                    wipes out from under the old item and in
                                    under the new one for free. Rendering it
                                    conditionally instead would just pop.

                                    Absolute, so the rule cannot add height to a
                                    bar whose h-20 is load-bearing, and
                                    `origin-left` so it wipes out from the start
                                    of the word rather than growing from the
                                    middle. Transform only -- animating `width`
                                    would relayout the flex row on every hover.

                                    `bottom-1.5` sits it INSIDE the link's new
                                    py-3, which lands it in exactly the same
                                    place `-bottom-1.5` did when there was no
                                    padding -- 4px under the word -- but stops
                                    it hanging outside the box. An absolutely
                                    positioned descendant still triggers :hover
                                    on its ancestor, so a rule below the link
                                    was a second edge that appeared and vanished
                                    as it wiped in: more of the same stutter.
                                */}
                                <span
                                    aria-hidden="true"
                                    className={`absolute bottom-1.5 left-0 h-0.5 w-full origin-left rounded-full bg-link transition-transform duration-300 ease-out motion-reduce:transition-none ${
                                        current
                                            ? 'scale-x-100'
                                            : 'scale-x-0 group-hover:scale-x-100'
                                    }`}
                                />
                            </Link>
                        );
                    })}
                </nav>

                <div className="ml-auto flex items-center gap-3 lg:ml-0">
                    <span
                        aria-hidden="true"
                        className="hidden h-8 w-px bg-hairline lg:block"
                    />

                    {/*
                        Hidden below `lg` because the bar has no room for it
                        there; the mobile menu below carries it instead.

                        No aria-current: this bar is the CLERK shell, and the
                        panel it points at renders under the sidebar shell
                        instead, so the link is never the current page here.
                    */}
                    {panel && (
                        <Link
                            href={panel.href}
                            // py-1.5 + the 2px border, not py-2: that lands the
                            // outlined button on Logout's 36px so the pair sits
                            // on one baseline.
                            className="group hidden items-center gap-2 rounded-md border-2 border-[#3B72C4] px-4 py-1.5 text-sm font-bold whitespace-nowrap text-[#3B72C4] transition duration-200 ease-out hover:bg-[#3B72C4] hover:text-white hover:shadow-md active:scale-[0.97] motion-safe:hover:-translate-y-0.5 lg:flex"
                        >
                            {PanelIcon && (
                                <PanelIcon
                                    aria-hidden="true"
                                    className="size-4 transition-transform duration-200 ease-out motion-safe:group-hover:-rotate-6"
                                />
                            )}
                            {panel.title}
                        </Link>
                    )}

                    {/*
                        Logout for a session, Login for a guest. A guest reaches
                        this shell through the public Help pages, and offering
                        them a Logout is the same lie the error page was fixed
                        for -- see STANDALONE_PAGES in app.tsx.
                    */}
                    {auth?.user ? (
                        <Link
                            href={logout()}
                            as="button"
                            className={topActionClass}
                        >
                            Logout
                        </Link>
                    ) : (
                        <Link href={login()} className={topActionClass}>
                            Login
                        </Link>
                    )}

                    <button
                        type="button"
                        onClick={() => setOpen((previous) => !previous)}
                        aria-expanded={open}
                        aria-controls="main-nav-mobile"
                        aria-label={open ? 'Close menu' : 'Open menu'}
                        className="rounded-md p-2 text-navy transition duration-200 ease-out hover:bg-[#EEF4FD] hover:text-link active:scale-90 lg:hidden"
                    >
                        {/*
                            Keyed on `open` so React swaps the NODE rather than
                            the component's props -- that is what lets each icon
                            play its own entrance instead of the pair
                            cross-fading in place.
                        */}
                        {open ? (
                            <X
                                key="close"
                                className="size-5 motion-safe:animate-in motion-safe:duration-200 motion-safe:spin-in-90"
                            />
                        ) : (
                            <Menu
                                key="open"
                                className="size-5 motion-safe:animate-in motion-safe:duration-200 motion-safe:fade-in"
                            />
                        )}
                    </button>
                </div>
            </div>

            {/*
                The same items below `lg`, where they do not fit across the bar.
                Rendered rather than hidden so the links are not in the tab order
                while collapsed.
            */}
            {open && (
                <nav
                    id="main-nav-mobile"
                    aria-label="Main"
                    className="border-t border-hairline motion-safe:animate-in motion-safe:duration-200 motion-safe:ease-out motion-safe:fade-in motion-safe:slide-in-from-top-2 lg:hidden"
                >
                    <ul className="mx-auto max-w-7xl px-4 py-2">
                        {items.map((item) => {
                            const current = isCurrentUrl(item.href);

                            return (
                                <li key={item.title}>
                                    <Link
                                        href={item.href}
                                        onClick={() => setOpen(false)}
                                        aria-current={
                                            current ? 'page' : undefined
                                        }
                                        /*
                                            The same "you are here" marker as
                                            the bar above, in the form this
                                            layout can carry: a rule to the LEFT
                                            of a stacked item rather than under
                                            it, since underlining one row of a
                                            list reads as a divider.
                                        */
                                        className={`block border-l-2 py-2.5 pl-3 text-[15px] font-bold transition duration-200 ease-out active:scale-[0.98] motion-safe:hover:translate-x-1 ${
                                            current
                                                ? 'border-link text-link'
                                                : 'border-transparent text-navy hover:text-link'
                                        }`}
                                    >
                                        {item.title}
                                    </Link>
                                </li>
                            );
                        })}

                        {panel && (
                            <li>
                                <Link
                                    href={panel.href}
                                    onClick={() => setOpen(false)}
                                    className="flex items-center gap-2 border-l-2 border-transparent py-2.5 pl-3 text-[15px] font-bold text-[#3B72C4] transition duration-200 ease-out active:scale-[0.98] motion-safe:hover:translate-x-1"
                                >
                                    {PanelIcon && (
                                        <PanelIcon
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    )}
                                    {panel.title}
                                </Link>
                            </li>
                        )}
                    </ul>
                </nav>
            )}
        </header>
    );
}

/**
 * The red action in the top-right of the bar. Shared so the Logout and Login
 * states are the same control with a different word, exactly as the hero's
 * `heroActionClass` does for the landing page.
 */
const topActionClass =
    'rounded-md bg-danger px-5 py-2 text-sm font-bold text-white transition duration-200 ease-out hover:brightness-95 hover:shadow-md active:scale-[0.97] motion-safe:hover:-translate-y-0.5';
