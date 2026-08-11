import {
    Archive as ArchiveIcon,
    BarChart3,
    Bell,
    FileSearch,
    Files,
    FolderOpen,
    Home,
    LifeBuoy,
    ScanLine,
    Settings,
    SlidersHorizontal,
    TrendingUp,
    UserCog,
    Users,
} from 'lucide-react';
import { dashboard } from '@/routes';
import admin from '@/routes/admin';
import archive from '@/routes/archive';
import documents from '@/routes/documents';
import help from '@/routes/help';
import notifications from '@/routes/notifications';
import reports from '@/routes/reports';
import superAdmin from '@/routes/super-admin';
import type { Role } from '@/types/auth';
import type { NavItem, RoleNav } from '@/types/navigation';

/**
 * Spec §4's navigation, verbatim.
 *
 * The labels below are contract acceptance criteria. Do not "improve" them.
 *
 * Derived client-side from auth.role rather than shipped as a per-request
 * payload: Wayfinder already gives typed URLs, and the server still enforces
 * access through EnsureRole and the policies. Navigation is a hint, never the
 * gate -- a link that is hidden here is still refused with a 403 if typed.
 */

/**
 * §4: "Main navigation: Home, Track Documents, Reports, Help."
 *
 * NOTE for the client (see docs/implementation/client-questions.md): §4's main
 * navigation names no Submit Document and no Dashboard entry, yet both are
 * billable features (#1/#2 and #14). Reading those four as the shared top bar
 * and giving each role a sidebar for its own work is the interpretation that
 * makes the section coherent -- it needs written confirmation.
 */
const mainNav: NavItem[] = [
    { title: 'Home', href: dashboard(), icon: Home },
    { title: 'Track Documents', href: documents.index(), icon: FileSearch },
    { title: 'Reports', href: reports.index(), icon: BarChart3 },
    { title: 'Help', href: help.index(), icon: LifeBuoy },
];

const workspace: NavItem[] = [
    { title: 'Submit Document', href: documents.create(), icon: FolderOpen },
    { title: 'Scan QR Code', href: documents.scan(), icon: ScanLine },
    {
        title: 'Notifications',
        href: notifications.index(),
        icon: Bell,
        badgeKey: 'unreadNotifications',
    },
];

export const NAV_BY_ROLE: Record<Role, RoleNav> = {
    user: {
        main: mainNav,
        sidebar: [
            {
                label: 'Workspace',
                items: [
                    { title: 'My Dashboard', href: dashboard(), icon: Home },
                    ...workspace,
                ],
            },
        ],
    },

    // §4: "Admin Panel sidebar: Document Management, Users, Reports, Settings."
    admin: {
        main: mainNav,
        sidebar: [
            { label: 'Workspace', items: workspace },
            {
                label: 'Administration',
                items: [
                    {
                        title: 'Document Management',
                        href: admin.dashboard(),
                        icon: FolderOpen,
                    },
                    { title: 'Users', href: admin.users.index(), icon: Users },
                    {
                        title: 'Archive',
                        href: archive.index(),
                        icon: ArchiveIcon,
                    },
                    {
                        title: 'Reports',
                        href: admin.reports.index(),
                        icon: BarChart3,
                    },
                    {
                        title: 'Settings',
                        href: admin.settings.edit(),
                        icon: Settings,
                    },
                ],
            },
        ],
    },

    // §4: "Super Admin Panel sidebar: Manage Users, All Documents,
    // Reports & Analytics, System Settings."
    super_admin: {
        main: mainNav,
        sidebar: [
            { label: 'Workspace', items: workspace },
            {
                label: 'System',
                items: [
                    {
                        title: 'Manage Users',
                        href: superAdmin.users.index(),
                        icon: UserCog,
                    },
                    {
                        title: 'All Documents',
                        href: superAdmin.dashboard(),
                        icon: Files,
                    },
                    {
                        title: 'Archive',
                        href: archive.index(),
                        icon: ArchiveIcon,
                    },
                    {
                        title: 'Reports & Analytics',
                        href: reports.index(),
                        icon: TrendingUp,
                    },
                    {
                        title: 'System Settings',
                        href: superAdmin.settings.edit(),
                        icon: SlidersHorizontal,
                    },
                ],
            },
        ],
    },
};

export function navFor(role: Role | null | undefined): RoleNav {
    return NAV_BY_ROLE[role ?? 'user'] ?? NAV_BY_ROLE.user;
}

/**
 * The panel sidebar's own items, exactly as §4 and the client's design name
 * them -- four for Admin, four for Super Admin, none for a plain user.
 *
 * Kept separate from NAV_BY_ROLE.sidebar because that structure is grouped and
 * labelled for the general shell, while the panel design is one flat column
 * with no group headings at all.
 */
const PANEL_NAV: Record<Role, NavItem[]> = {
    user: [],

    admin: [
        {
            title: 'Document Management',
            href: admin.dashboard(),
            icon: FolderOpen,
        },
        { title: 'Users', href: admin.users.index(), icon: Users },
        { title: 'Reports', href: admin.reports.index(), icon: BarChart3 },
        { title: 'Settings', href: admin.settings.edit(), icon: Settings },
    ],

    super_admin: [
        {
            title: 'Manage Users',
            href: superAdmin.users.index(),
            icon: UserCog,
        },
        { title: 'All Documents', href: superAdmin.dashboard(), icon: Files },
        {
            title: 'Reports & Analytics',
            href: reports.index(),
            icon: TrendingUp,
        },
        {
            title: 'System Settings',
            href: superAdmin.settings.edit(),
            icon: SlidersHorizontal,
        },
    ],
};

export function panelNavFor(role: Role | null | undefined): NavItem[] {
    return PANEL_NAV[role ?? 'user'] ?? PANEL_NAV.user;
}
