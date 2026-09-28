import { SecurityPinIdleLock } from '@/components/security-pin/security-pin-idle-lock';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {/* The panels and Settings never show a document, but an unlocked
                session still has to lock when its owner walks away from one
                of them -- see SecurityPinIdleLock. */}
            <SecurityPinIdleLock />
            {children}
        </AppLayoutTemplate>
    );
}
