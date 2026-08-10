import { Link } from '@inertiajs/react';
import { Crown, ShieldCheck, User } from 'lucide-react';
import { login } from '@/routes';
import {
    admin as adminLogin,
    superAdmin as superAdminLogin,
} from '@/routes/login';

/**
 * §3's "separate login entry points for User, Admin, and Super Admin".
 *
 * These are three URLs rendering ONE page against ONE guard and ONE POST
 * target. The chip you pick changes the heading and nothing else: it is never
 * posted, never reaches Auth::attempt, and never influences authorization. The
 * role comes from the database row after the credentials check.
 *
 * Signing in at /login/admin with a clerk account lands you on the clerk
 * dashboard rather than being rejected, and that is deliberate -- refusing the
 * mismatch would tell an attacker which addresses belong to admins.
 */

const CHIPS = [
    {
        key: 'user',
        label: 'User',
        caption: 'Access as User',
        href: () => login.url(),
        icon: User,
        surface: 'bg-[#C9DDF6] text-[#17325C] hover:bg-[#B9D3F3]',
    },
    {
        key: 'admin',
        label: 'Admin',
        caption: 'Access as Admin',
        href: () => adminLogin.url(),
        icon: ShieldCheck,
        surface: 'bg-[#CFEBC7] text-[#1F5136] hover:bg-[#BFE3B5]',
    },
    {
        key: 'super-admin',
        label: 'Super Admin',
        caption: 'Access as Super Admin',
        href: () => superAdminLogin.url(),
        icon: Crown,
        surface: 'bg-[#D8CDF5] text-[#3D2C74] hover:bg-[#CBBCF1]',
    },
] as const;

export function PortalChips({ current }: { current: string }) {
    return (
        <div>
            <div className="flex items-center gap-3">
                <span className="h-px flex-1 bg-[#E3E8EF]" />
                <span className="text-sm text-copy">or</span>
                <span className="h-px flex-1 bg-[#E3E8EF]" />
            </div>

            <p className="mt-4 text-center text-sm font-bold text-navy">
                Login as:
            </p>

            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                {CHIPS.map((chip) => {
                    const active = chip.key === current;

                    return (
                        <Link
                            key={chip.key}
                            href={chip.href()}
                            aria-current={active ? 'page' : undefined}
                            className={`rounded-lg px-2 py-3 text-center transition ${chip.surface} ${
                                active ? 'ring-2 ring-navy ring-offset-1' : ''
                            }`}
                        >
                            {/*
                                whitespace-nowrap on the label: "Super Admin"
                                wrapped to two lines in a 125px column, which
                                made that chip taller than the other two.
                            */}
                            <span className="flex items-center justify-center gap-1.5 text-sm font-bold whitespace-nowrap">
                                <chip.icon className="size-4 shrink-0" />
                                {chip.label}
                            </span>
                            <span className="mt-0.5 block text-[11px] leading-tight">
                                {chip.caption}
                            </span>
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}
