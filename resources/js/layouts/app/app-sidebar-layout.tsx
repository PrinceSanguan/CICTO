import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { PanelSidebar } from '@/components/panel-sidebar';
import type { AppLayoutProps } from '@/types';

/**
 * The shell behind §4's Admin and Super Admin panels.
 *
 * Only the panels use it -- everything a clerk touches renders under
 * app-top-layout, per the client's designs. The sidebar it mounts is the
 * panel's own, not the general application menu.
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <PanelSidebar />
            {/* `clip`, not `hidden`: see app-top-layout for the second
                scrollbar `hidden` produces on a flex item. */}
            <AppContent variant="sidebar" className="overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />

                {/*
                    Padding lives here rather than in each page.
                    /admin/dashboard carried its own wrapper and the other six
                    panel screens carried none, so their headings sat flush
                    against the sidebar -- and were clipped off the left edge
                    entirely at 375px.
                */}
                <div className="flex flex-1 flex-col gap-6 p-4 lg:p-6">
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
