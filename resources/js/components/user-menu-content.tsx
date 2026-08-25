import { Link, router } from '@inertiajs/react';
import { Home, LogOut, Settings } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { dashboard, logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                {/*
                    §18's dashboard. It is here because the top-nav shell has
                    no sidebar and, since 2026-08-26, no longer reaches it
                    through Home either -- Home is the landing page for both
                    audiences now (see lib/nav.ts). Without this entry a plain
                    user on Track Documents or Reports would have had no way
                    back to their own dashboard at all. The label matches the
                    one the sidebar already uses.
                */}
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={dashboard()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Home className="mr-2" />
                        My Dashboard
                    </Link>
                </DropdownMenuItem>

                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        Settings
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
