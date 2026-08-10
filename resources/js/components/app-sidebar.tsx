import { Link, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { navFor } from '@/lib/nav';
import { dashboard, logout } from '@/routes';

/**
 * One sidebar component, three §4 panels.
 *
 * Three separate layout components would mean three places to fix every header
 * bug. The role picks the item list; the server still decides access.
 */
export function AppSidebar() {
    const { auth } = usePage().props;
    const nav = navFor(auth?.role);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {/*
                    §4's main navigation (Home, Track Documents, Reports, Help)
                    renders first, then the role's own panel. It was previously
                    computed and never rendered, which left three of the four
                    contractually named menu items with no way to reach them.
                */}
                <NavMain
                    sections={[
                        { label: null, items: nav.main },
                        ...nav.sidebar,
                    ]}
                />
            </SidebarContent>

            <SidebarFooter>
                {/*
                    §4's panel designs put Logout in the sidebar itself. It also
                    lives in the account menu below, and that duplication is
                    deliberate: signing out is the one action a shared
                    counter terminal needs to be one obvious click away, not
                    hidden behind an avatar.
                */}
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            tooltip={{ children: 'Logout' }}
                        >
                            <Link
                                href={logout()}
                                as="button"
                                className="w-full"
                            >
                                <LogOut />
                                <span>Logout</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
