import { usePage } from '@inertiajs/react';
import { AppTopNav } from '@/components/app-top-nav';
import { DocumentMotifs } from '@/components/documents/document-motifs';
import { Skyline } from '@/components/landing/skyline';

/**
 * The staff-facing shell: white nav bar, blue gradient body, city skyline.
 *
 * §4 names one main navigation -- Track Documents, Reports, Help, with Home on
 * the lockup -- and the client's designs put it across the top for the pages a
 * clerk uses all day. The role PANELS (Admin, Super Admin) keep the sidebar shell instead;
 * they are a different job with a different menu.
 *
 * The skyline is anchored to the bottom of the page rather than the viewport,
 * so a long document list scrolls past it instead of being pinned above it.
 */
export default function AppTopLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    /*
        The page's own entrance, replayed by remounting <main> under a new key.

        Inertia swaps the CHILDREN of <main> on navigation and leaves the
        element itself alone, so a CSS animation declared on it fires once at
        boot and never again. A changing key is what makes React drop the node
        and build a fresh one, which restarts the animation.

        Keyed on `component`, NOT on `url`, and that distinction is load-bearing.
        documents/index and admin/users/index filter with `preserveState: true`
        partial reloads: the URL changes on every keystroke while the component
        stays put, so a url-keyed <main> would tear down the search field
        mid-word and throw the caret away. The component name only changes when
        you actually move to another screen -- which is the moment the animation
        is for.
    */
    const { component } = usePage();

    return (
        <div className="flex min-h-svh flex-col bg-surface">
            <AppTopNav />

            {/*
                `overflow-x-clip`, NOT `overflow-x-hidden`. They clip
                identically; only one of them makes this div a scroll container,
                and that difference was the second scrollbar.

                `overflow-x: hidden` forces the other axis from `visible` to
                `auto` (CSS Overflow 3 -- `clip` is the one exception the rule
                carves out). That has a knock-on in flexbox: a flex item's
                automatic minimum size applies only while its overflow in the
                main axis is `visible`, so `min-height: auto` collapsed to 0
                here. This div is `flex-1` -- `flex: 1 1 0%` -- so with nothing
                holding its floor it sized to the free space in the `min-h-svh`
                column, i.e. exactly the viewport minus the nav, and any page
                taller than that scrolled INSIDE it. The document scrolled too,
                so a long page drew two bars side by side.

                With `clip` the axis stays `visible`, the div is no longer a
                scroll container, `min-height: auto` is content-based again and
                the div grows with the page. One scrollbar, on the document,
                which is the one `html { overflow-y: scroll }` in app.css keeps
                reserved.
            */}
            <div className="relative flex flex-1 flex-col overflow-x-clip bg-linear-to-b/srgb from-brand to-brand-soft">
                <DocumentMotifs />

                {/*
                    Two rules here, both from the client dropping "the pages
                    must fit the fold" on 2026-08-27.

                    A flat `py-8` at every width. It was `lg:py-5` on a laptop,
                    where the fixed h-20 nav leaves this container ~715px and
                    each 32px gutter is a third of what the shortest page had to
                    claw back to clear the fold. With the requirement gone the
                    clawback goes with it and the gutters are the design's on
                    every screen -- see knowledge-base.tsx, squeezed hardest and
                    now back on its comp's rhythm.

                    `min-h-[calc(100svh-3rem)]` then pushes the other way, and
                    guarantees every page in this shell overflows the fold by
                    ~32px: the nav is a fixed 5rem, so a floor of `100svh - 3rem`
                    puts the document 2rem past the viewport even when the
                    content is short. Help fits the fold whole and the client
                    wanted it to scroll; setting the floor HERE rather than
                    padding the Help page keeps that true at any viewport height
                    and on the other short screens too, and 32px is small enough
                    that the skyline (absolute to the bottom of the container
                    above) only drops by that much.

                    It also finishes what `html { overflow-y: scroll }` in
                    app.css started: that stopped the nav jumping between pages
                    by reserving the gutter, and this puts a live bar in it
                    instead of an empty track.
                */}
                <main
                    key={component}
                    className="relative z-10 mx-auto min-h-[calc(100svh-3rem)] w-full max-w-7xl flex-1 px-4 py-8 duration-500 ease-out motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-3 lg:px-8"
                >
                    {children}
                </main>

                {/*
                    Positioned, not in flow. Both of the client's screens run
                    the rooflines BEHIND the bottom card, which is why `main`
                    carries z-10 -- but a `-mt-12` block only overlapped 48px of
                    it and still added its own ~240px to the page. That was the
                    whole of the Knowledge Base scrollbar: its content ends
                    almost exactly at the fold, and the band underneath pushed
                    it over. Taking the art out of flow removes that height and
                    lets the panel sit on the skyline the way the Figma draws
                    it.

                    `bottom-0` on THIS container, not on the viewport, so the
                    original guarantee holds: the container is `flex-1` of a
                    `min-h-svh` column, so it is the taller of the viewport and
                    the content. A long document list still scrolls past the
                    rooflines rather than being pinned above them.
                */}
                <Skyline className="absolute inset-x-0 bottom-0" />
            </div>
        </div>
    );
}
