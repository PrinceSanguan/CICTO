import { Link, usePage } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavSection } from '@/types';

/**
 * Renders the role's navigation. Spec §4 groups the panels under headings, so
 * this takes sections rather than a flat item list.
 */
export function NavMain({ sections = [] }: { sections: NavSection[] }) {
    const { isCurrentUrl } = useCurrentUrl();
    const page = usePage().props as unknown as {
        unreadNotifications?: number;
    };

    return (
        <>
            {sections.map((section, index) => (
                <SidebarGroup
                    key={section.label ?? `section-${index}`}
                    className="px-2 py-0"
                >
                    {section.label && (
                        <SidebarGroupLabel>{section.label}</SidebarGroupLabel>
                    )}
                    <SidebarMenu>
                        {section.items.map((item) => {
                            const badge = item.badgeKey
                                ? (page[item.badgeKey] ?? 0)
                                : 0;

                            return (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isCurrentUrl(item.href)}
                                        tooltip={{ children: item.title }}
                                    >
                                        <Link href={item.href} prefetch>
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                    {badge > 0 && (
                                        <SidebarMenuBadge>
                                            {badge > 99 ? '99+' : badge}
                                        </SidebarMenuBadge>
                                    )}
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
