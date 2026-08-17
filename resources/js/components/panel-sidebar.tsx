import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, LogOut } from 'lucide-react';
import type { ReactElement } from 'react';
import { CictoLockup } from '@/components/auth/cicto-lockup';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    useSidebar,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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
 *
 * COLLAPSED STATE. `collapsible="icon"` shrinks the column to 3rem, but the
 * primitive only narrows its own container -- it does not clip, and none of the
 * hand-written markup below is a SidebarMenuButton, so nothing shrank with it.
 * The lockup and the Logout label simply overflowed and painted themselves
 * across the page content. Every `group-data-[collapsible=icon]:` rule here is
 * what makes the rail an actual rail: the mark shrinks, the words become
 * screen-reader-only (hidden, not deleted -- the links keep their names), and
 * `overflow-hidden` on the container is the backstop for anything missed.
 */
export function PanelSidebar() {
    const { auth } = usePage().props;
    const items = panelNavFor(auth?.role);
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="overflow-hidden border-0"
        >
            <div className="flex h-full flex-col bg-gradient-to-b from-[#7FA9E0] via-[#A8C6EC] to-[#DCE8F8]">
                <SidebarHeader className="p-0">
                    {/* Slightly deeper than the column so the lockup reads as a
                        masthead rather than the first menu item. */}
                    <RailTip label="Back to Home">
                        <Link
                            href={dashboard()}
                            prefetch
                            className="block bg-[#6E9CD8]/60 px-5 py-6 transition group-data-[collapsible=icon]:px-2 group-data-[collapsible=icon]:py-3 hover:bg-[#6E9CD8]/80"
                        >
                            <CictoLockup
                                className="group-data-[collapsible=icon]:gap-0 [&_span]:text-navy"
                                markClassName="group-data-[collapsible=icon]:w-8"
                                wordmarkClassName="group-data-[collapsible=icon]:sr-only"
                            />
                            <span className="sr-only">Back to Home</span>
                        </Link>
                    </RailTip>
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
                                        <RailTip label={item.title}>
                                            <Link
                                                href={item.href}
                                                prefetch
                                                aria-current={
                                                    active ? 'page' : undefined
                                                }
                                                className={cn(
                                                    'flex items-center gap-3 px-5 py-4 text-[15px] font-semibold transition',
                                                    'group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:gap-0 group-data-[collapsible=icon]:px-0',
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
                                                <span className="flex-1 group-data-[collapsible=icon]:sr-only">
                                                    {item.title}
                                                </span>
                                                {active && (
                                                    <ChevronRight
                                                        aria-hidden="true"
                                                        className="size-4 shrink-0 group-data-[collapsible=icon]:hidden"
                                                    />
                                                )}
                                            </Link>
                                        </RailTip>
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
                    <RailTip label="Logout">
                        <Link
                            href={logout()}
                            as="button"
                            className="flex w-full items-center gap-3 px-5 py-4 text-[15px] font-semibold text-navy transition group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:gap-0 group-data-[collapsible=icon]:px-0 hover:bg-white/25"
                        >
                            <LogOut
                                aria-hidden="true"
                                className="size-6 shrink-0"
                            />
                            <span className="group-data-[collapsible=icon]:sr-only">
                                Logout
                            </span>
                        </Link>
                    </RailTip>
                </SidebarFooter>
            </div>
        </Sidebar>
    );
}

/**
 * The label a rail item loses when the column collapses, given back on hover.
 *
 * Shown only while collapsed on desktop. Expanded, the label is already on
 * screen and a tooltip repeating it is noise; on mobile the sidebar is a full
 * width sheet with no collapsed state at all, and touch has no hover.
 *
 * The wrapper is rendered UNCONDITIONALLY and only the content is hidden, which
 * is how SidebarMenuButton does it. Returning bare `children` when expanded
 * instead would put a different element TYPE in the same tree position, so
 * React would unmount and rebuild every link on each toggle -- and Cmd/Ctrl+B
 * toggles from anywhere, including with the keyboard focus sitting on one of
 * these links, which would then be thrown back to <body>.
 */
function RailTip({
    label,
    children,
}: {
    label: string;
    children: ReactElement;
}) {
    const { state, isMobile } = useSidebar();

    return (
        <Tooltip>
            <TooltipTrigger asChild>{children}</TooltipTrigger>
            <TooltipContent
                side="right"
                align="center"
                hidden={isMobile || state !== 'collapsed'}
            >
                {label}
            </TooltipContent>
        </Tooltip>
    );
}
