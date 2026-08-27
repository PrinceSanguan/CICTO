import {
    Archive as ArchiveIcon,
    BarChart3,
    Bell,
    Crown,
    FileSearch,
    Files,
    FolderOpen,
    LifeBuoy,
    ScanLine,
    Settings,
    ShieldCheck,
    SlidersHorizontal,
    TrendingUp,
    UserCog,
    Users,
} from 'lucide-react';
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
 * Three items, not four. §4's `Home` is now carried by the LOCKUP rather than
 * by a link in the row -- the client asked on 2026-08-27 for the home button
 * to come out of the navbar and for the logo to be the way home. §4's
 * destination is still there and still named: AppTopNav's lockup links `/`
 * and labels itself "CICTO home", which is the masthead convention the panel
 * sidebar already followed (see `panelHomeFor`). What went is the duplicate,
 * not the destination.
 *
 * Dropping it also retires the special case AppTopNav used to carry: `Home`
 * was hidden from the row while you were ON Home, so the bar silently changed
 * width between pages. Three items on every screen is one behaviour instead of
 * two.
 *
 * NOTE for the client (see docs/implementation/client-questions.md): §4's main
 * navigation names no Submit Document and no Dashboard entry, yet both are
 * billable features (#1/#2 and #14). Reading those as the shared top bar and
 * giving each role a sidebar for its own work is the interpretation that makes
 * the section coherent -- it needs written confirmation.
 */
const mainNav: NavItem[] = [
    /*
        No Home entry. It pointed at `/` for everybody -- the landing page,
        which the client made the signed-in home on 2026-08-26 -- and the
        lockup beside this row has always pointed at the same URL. The logo is
        the one that stayed.

        §18's dashboard is still absent too: it was briefly a button beside
        Logout so it stayed reachable, and the client struck it on 2026-08-26.
        Its route still resolves for anyone who types it.
    */
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
            /*
                No dashboard entry. §18's screen was removed from every menu on
                2026-08-26 -- the client asked for exactly four destinations,
                "Home, Track Documents, Reports, Help", and nothing else. The
                route and the page are untouched; only the ways in are gone.
            */
            { label: 'Workspace', items: workspace },
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

/**
 * The way BACK into a role's panel from the clerk shell.
 *
 * The panel sidebar's lockup links Home, so an admin can always leave the
 * panel -- but nothing led back. §4's main navigation is Track Documents,
 * Reports and Help for everybody (plus Home on the lockup), so /admin/dashboard
 * was reachable only by signing in again or typing the URL. The client asked
 * where it had gone.
 *
 * Kept OUT of `mainNav` deliberately. §4's labels are contract acceptance
 * criteria and another entry would change the set it names; AppTopNav renders
 * this as its own button beside Logout instead, which is both truer to the
 * spec and harder to miss than one more link in the row.
 *
 * Null for a plain user, who has no panel. Hiding it is presentation only --
 * EnsureRole still answers 403 to anyone who types the URL.
 */
const PANEL_HOME: Record<Role, NavItem | null> = {
    user: null,
    admin: { title: 'Admin Panel', href: admin.dashboard(), icon: ShieldCheck },
    super_admin: {
        title: 'Super Admin Panel',
        href: superAdmin.dashboard(),
        icon: Crown,
    },
};

export function panelHomeFor(role: Role | null | undefined): NavItem | null {
    return PANEL_HOME[role ?? 'user'] ?? null;
}
