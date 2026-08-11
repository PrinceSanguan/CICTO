import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, LogOut } from 'lucide-react';
import { CictoLockup } from '@/components/auth/cicto-lockup';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { panelNavFor } from '@/lib/nav';
import { cn, toUrl } from '@/lib/utils';
import { dashboard, logout } from '@/routes';

/**
 * §4's Admin and Super Admin panel sidebar, to the client's design.
 *
 * The design is a single blue-gradient column: the CICTO lockup at the top,
 * then only the panel's own items -- Document Management, Users, Reports,
 * Settings -- and Logout at the foot. It carries none of the clerk navigation
 * the top layout provides, which is the point: the panel is a place you go to
 * administer, not the shell the whole app lives in.
 *
 * That leaves one usability problem the design does not answer, since an admin
 * who lands here would otherwise have no route back to Track Documents. The
 * lockup is the answer: it links Home, the same way a masthead does everywhere
 * else, so the way out exists without adding an item the design does not show.
 */
export function PanelSidebar() {
    const { auth } = usePage().props;
    const items = panelNavFor(auth?.role);
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="border-0 [&>[data-slot=sidebar-container]]:border-0"
        >
            <div className="flex h-full flex-col bg-gradient-to-b from-[#7FA9E0] via-[#A8C6EC] to-[#DCE8F8]">
                <SidebarHeader className="p-0">
                    {/* Slightly deeper than the column so the lockup reads as a
                        masthead rather than the first menu item. */}
                    <Link
                        href={dashboard()}
                        prefetch
                        className="block bg-[#6E9CD8]/60 px-5 py-6 transition hover:bg-[#6E9CD8]/80"
                    >
                        <CictoLockup className="[&_span]:text-navy" />
                        <span className="sr-only">Back to Home</span>
                    </Link>
                </SidebarHeader>

                <SidebarContent className="gap-0 overflow-x-hidden">
                    <nav aria-label="Admin panel">
                        <ul>
                            {items.map((item) => {
                                const active = isCurrentOrParentUrl(
                                    toUrl(item.href),
                                );
                                const Icon = item.icon;

                                return (
                                    <li key={toUrl(item.href)}>
                                        <Link
                                            href={item.href}
                                            prefetch
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                            className={cn(
                                                'flex items-center gap-3 px-5 py-4 text-[15px] font-semibold transition',
                                                active
                                                    ? 'bg-[#6E9CD8]/70 text-white'
                                                    : 'text-navy hover:bg-white/25',
                                            )}
                                        >
                                            {Icon && (
                                                <Icon
                                                    aria-hidden="true"
                                                    className="size-6 shrink-0"
                                                />
                                            )}
                                            <span className="flex-1">
                                                {item.title}
                                            </span>
                                            {active && (
                                                <ChevronRight
                                                    aria-hidden="true"
                                                    className="size-4 shrink-0"
                                                />
                                            )}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>
                </SidebarContent>

                <SidebarFooter className="mt-auto p-0">
                    {/*
                        Logout sits in the sidebar itself, as the design shows,
                        and is a button rather than a link because signing out
                        is a POST -- a GET logout is a one-pixel image away from
                        being triggered by any page a clerk visits.
                    */}
                    <Link
                        href={logout()}
                        as="button"
                        className="flex w-full items-center gap-3 px-5 py-4 text-[15px] font-semibold text-navy transition hover:bg-white/25"
                    >
                        <LogOut aria-hidden="true" className="size-6" />
                        Logout
                    </Link>
                </SidebarFooter>
            </div>
        </Sidebar>
    );
}
