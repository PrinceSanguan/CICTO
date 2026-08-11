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

                <main className="relative z-10 mx-auto w-full max-w-7xl flex-1 px-4 py-8 lg:px-8">
                    {children}
                </main>

                <Skyline />
            </div>
        </div>
    );
}
