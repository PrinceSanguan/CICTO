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
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
