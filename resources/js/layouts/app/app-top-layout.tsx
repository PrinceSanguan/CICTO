import { AppTopNav } from '@/components/app-top-nav';
import { DocumentMotifs } from '@/components/documents/document-motifs';
import { Skyline } from '@/components/landing/skyline';

/**
 * The staff-facing shell: white nav bar, blue gradient body, city skyline.
 *
 * §4 names one main navigation -- Home, Track Documents, Reports, Help -- and
 * the client's designs put it across the top for the pages a clerk uses all
 * day. The role PANELS (Admin, Super Admin) keep the sidebar shell instead;
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
    return (
        <div className="flex min-h-svh flex-col bg-surface">
            <AppTopNav />

            <div className="relative flex flex-1 flex-col overflow-x-hidden bg-linear-to-b/srgb from-brand to-brand-soft">
                <DocumentMotifs />

                {/*
                    `lg:py-5` rather than a flat `py-8`. The nav above is a
                    fixed h-20, so on a laptop viewport this container has
                    ~715px and every one of these 32px gutters is a third of
                    what the shortest page needs to claw back to fit the fold.
                    Phones keep py-8: they scroll anyway.
                */}
                <main className="relative z-10 mx-auto w-full max-w-7xl flex-1 px-4 py-8 lg:px-8 lg:py-5">
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
