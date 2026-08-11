import { Link, usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useState } from 'react';
import { CictoLockup } from '@/components/auth/cicto-lockup';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { navFor } from '@/lib/nav';
import { logout } from '@/routes';

/**
 * §4's main navigation: Home, Track Documents, Reports, Help.
 *
 * The labels are contract acceptance criteria and come from `navFor`, the same
 * source the sidebar uses, so the two panels can never drift apart. Navigation
 * is a hint and never a gate -- a link hidden here is still refused with a 403
 * if the URL is typed.
 */
export function AppTopNav() {
    const { auth } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const [open, setOpen] = useState(false);

    const items = navFor(auth?.role).main;

    return (
        <header className="relative z-30 bg-white shadow-sm">
            <div className="mx-auto flex h-20 w-full max-w-7xl items-center gap-4 px-4 lg:px-8">
                <Link href="/" className="shrink-0" aria-label="CICTO home">
                    <CictoLockup className="origin-left scale-90" />
                </Link>

                <nav
                    aria-label="Main"
                    className="hidden flex-1 items-center justify-center gap-8 lg:flex xl:gap-14"
                >
                    {items.map((item) => {
                        const current = isCurrentUrl(item.href);

                        return (
                            <Link
                                key={item.title}
                                href={item.href}
                                aria-current={current ? 'page' : undefined}
                                className={`text-[15px] font-bold transition ${
                                    current
                                        ? 'text-link'
                                        : 'text-navy hover:text-link'
                                }`}
                            >
                                {item.title}
                            </Link>
                        );
                    })}
                </nav>

                <div className="ml-auto flex items-center gap-3 lg:ml-0">
                    <span
                        aria-hidden="true"
                        className="hidden h-8 w-px bg-hairline lg:block"
                    />

                    <Link
                        href={logout()}
                        as="button"
                        className="rounded-md bg-danger px-5 py-2 text-sm font-bold text-white transition hover:brightness-95"
                    >
                        Logout
                    </Link>

                    <button
                        type="button"
                        onClick={() => setOpen((previous) => !previous)}
                        aria-expanded={open}
                        aria-controls="main-nav-mobile"
                        aria-label={open ? 'Close menu' : 'Open menu'}
                        className="rounded-md p-2 text-navy lg:hidden"
                    >
                        {open ? (
                            <X className="size-5" />
                        ) : (
                            <Menu className="size-5" />
                        )}
                    </button>
                </div>
            </div>

            {/*
                The same items below `lg`, where they do not fit across the bar.
                Rendered rather than hidden so the links are not in the tab order
                while collapsed.
            */}
            {open && (
                <nav
                    id="main-nav-mobile"
                    aria-label="Main"
                    className="border-t border-hairline lg:hidden"
                >
                    <ul className="mx-auto max-w-7xl px-4 py-2">
                        {items.map((item) => (
                            <li key={item.title}>
                                <Link
                                    href={item.href}
                                    onClick={() => setOpen(false)}
                                    aria-current={
                                        isCurrentUrl(item.href)
                                            ? 'page'
                                            : undefined
                                    }
                                    className="block py-2.5 text-[15px] font-bold text-navy"
                                >
                                    {item.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            )}
        </header>
    );
}
