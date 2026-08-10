import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Key of a numeric counter in shared props, e.g. unread notifications. */
    badgeKey?: 'unreadNotifications';
};

export type NavSection = {
    /** Rendered as a SidebarGroupLabel; null renders no heading. */
    label: string | null;
    items: NavItem[];
};

/** Spec §4 names three distinct panels, so navigation is keyed by role. */
export type RoleNav = {
    main: NavItem[];
    sidebar: NavSection[];
};
