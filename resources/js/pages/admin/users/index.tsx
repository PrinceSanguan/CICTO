import { Head, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { LastLogin } from '@/components/admin/last-login';
import { PanelHeading } from '@/components/admin/panel-heading';
import admin from '@/routes/admin';
import type { Paginated, SelectOption } from '@/types';

type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    office: string | null;
    office_name: string | null;
    is_active: boolean;
    last_login_at: string | null;
};

type Filters = {
    q: string;
    role: string | null;
    status: string | null;
    sort: string;
};

type Props = {
    users: Omit<Paginated<UserRow>, 'links'>;
    filters: Filters;
    roles: SelectOption[];
};

/** §4's Users screen: who has access, in what role, and when they were last here. */
export default function AdminUsers({ users, filters, roles }: Props) {
    const [q, setQ] = useState(filters.q ?? '');

    // Read through a ref, not closed over: `filters` is a new object on every
    // response, so a useCallback keyed on it would produce a new `apply`, which
    // re-runs the debounce effect, which fires another request. That loop only
    // appears once the server starts echoing filters back.
    const latest = useRef(filters);

    useEffect(() => {
        latest.current = filters;
    }, [filters]);

    const apply = useCallback((next: Partial<Filters>) => {
        router.get(
            admin.users.index.url(),
            { ...latest.current, ...next, page: undefined },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['users', 'filters'],
            },
        );
    }, []);

    const mounted = useRef(false);

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;

            return;
        }

        const timer = setTimeout(() => apply({ q: q || undefined }), 300);

        return () => clearTimeout(timer);
    }, [q, apply]);

    return (
        <>
            <Head title="Users" />

            <PanelHeading />

            <section className="mt-6 rounded-xl bg-white p-6 shadow-sm">
                <h2 className="text-xl font-extrabold tracking-tight text-navy">
                    Users
                </h2>

                <div className="mt-4 rounded-xl border border-[#E4EAF3] p-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <div className="relative min-w-0 flex-1">
                            <Search
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#8A9AAE]"
                            />
                            <input
                                type="search"
                                value={q}
                                onChange={(event) => setQ(event.target.value)}
                                placeholder="Search"
                                aria-label="Search users by name or email"
                                className="h-10 w-full rounded-full border border-[#E4EAF3] pr-4 pl-9 text-sm text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                            />
                        </div>

                        <Filter
                            label="All Roles"
                            value={filters.role ?? ''}
                            onChange={(value) =>
                                apply({ role: value || undefined })
                            }
                            options={roles}
                        />

                        <Filter
                            label="All Status"
                            value={filters.status ?? ''}
                            onChange={(value) =>
                                apply({ status: value || undefined })
                            }
                            options={[
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Deactivated' },
                            ]}
                        />

                        <Filter
                            label="Last Login"
                            value={filters.sort}
                            onChange={(value) => apply({ sort: value })}
                            options={[
                                { value: 'last_login', label: 'Last Login' },
                                { value: 'name', label: 'Name' },
                                { value: 'email', label: 'Email' },
                                { value: 'role', label: 'Role' },
                            ]}
                            clearable={false}
                        />
                    </div>

                    {/* Phone: cards, because a six-column table on a 375px
                        screen hides Status and Last Login entirely. */}
                    <ul className="mt-4 divide-y divide-[#EEF2F7] md:hidden">
                        {users.data.length === 0 && (
                            <li className="py-10 text-center text-sm text-copy">
                                No users match these filters.
                            </li>
                        )}

                        {users.data.map((user) => (
                            <li key={user.id} className="py-4">
                                <p className="text-[15px] font-bold text-navy">
                                    {user.name}
                                </p>
                                <p className="text-sm break-all text-copy">
                                    {user.email}
                                </p>
                                <dl className="mt-2 space-y-1 text-sm">
                                    <Row label="Role">{user.role_label}</Row>
                                    <Row label="Office">
                                        {user.office_name ?? user.office ?? '—'}
                                    </Row>
                                    <Row label="Last Login">
                                        <LastLogin iso={user.last_login_at} />
                                    </Row>
                                </dl>
                                <div className="mt-2">
                                    <StatusPill active={user.is_active} />
                                </div>
                            </li>
                        ))}
                    </ul>

                    <div className="mt-4 hidden overflow-x-auto md:block">
                        <table className="w-full min-w-[760px] text-left">
                            <thead>
                                <tr className="border-b border-[#EEF2F7]">
                                    {[
                                        'Name',
                                        'Email',
                                        'Role',
                                        'Office',
                                        'Status',
                                        'Last Login',
                                    ].map((heading) => (
                                        <th
                                            key={heading}
                                            scope="col"
                                            className="px-3 py-3 text-sm font-bold text-navy"
                                        >
                                            {heading}
                                        </th>
                                    ))}
                                </tr>
                            </thead>

                            <tbody>
                                {users.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-3 py-10 text-center text-sm text-copy"
                                        >
                                            No users match these filters.
                                        </td>
                                    </tr>
                                )}

                                {users.data.map((user) => (
                                    <tr
                                        key={user.id}
                                        className="border-b border-[#EEF2F7] last:border-0"
                                    >
                                        <td className="px-3 py-4 text-sm font-semibold text-navy">
                                            {user.name}
                                        </td>
                                        <td className="px-3 py-4 text-sm text-copy">
                                            {user.email}
                                        </td>
                                        <td className="px-3 py-4 text-sm text-copy">
                                            {user.role_label}
                                        </td>
                                        <td className="px-3 py-4 text-sm">
                                            <OfficeCell user={user} />
                                        </td>
                                        <td className="px-3 py-4">
                                            <StatusPill
                                                active={user.is_active}
                                            />
                                        </td>
                                        <td className="px-3 py-4 text-sm whitespace-nowrap text-copy">
                                            <LastLogin
                                                iso={user.last_login_at}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {users.last_page > 1 && (
                        <nav
                            aria-label="Pagination"
                            className="mt-4 flex items-center justify-between gap-3"
                        >
                            <p className="text-xs text-copy">
                                Showing {users.from}–{users.to} of {users.total}
                            </p>
                            <div className="flex gap-1">
                                {Array.from(
                                    { length: users.last_page },
                                    (_, index) => index + 1,
                                ).map((page) => (
                                    <button
                                        key={page}
                                        type="button"
                                        aria-current={
                                            page === users.current_page
                                                ? 'page'
                                                : undefined
                                        }
                                        onClick={() =>
                                            router.get(
                                                admin.users.index.url(),
                                                { ...filters, page },
                                                {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                        className={`min-w-8 rounded-md px-2.5 py-1 text-sm font-medium transition ${
                                            page === users.current_page
                                                ? 'bg-[#3B72C4] text-white'
                                                : 'text-navy hover:bg-[#E8F0FB]'
                                        }`}
                                    >
                                        {page}
                                    </button>
                                ))}
                            </div>
                        </nav>
                    )}
                </div>

                {/*
                    Said plainly rather than left to be discovered. The screen
                    is read-only by design -- accounts are created and roles
                    changed from the console -- and an admin who expects an
                    "Add user" button should learn why it is absent here, not
                    from a support ticket.
                */}
                <p className="mt-4 text-xs leading-relaxed text-copy">
                    Accounts are created and roles changed from the server
                    console (
                    <code className="font-mono">php artisan cicto:user</code>),
                    which keeps privilege changes off the web session and inside
                    the audit log. Ask your system administrator to add or
                    deactivate an account.
                </p>
            </section>
        </>
    );
}

/**
 * Which office an account belongs to -- the client's ask of 2026-09-03.
 *
 * Code on top, full name under it: the code is the identifier that appears in
 * control numbers and on printed labels, the name is what makes it readable.
 *
 * On this screen an office admin only ever sees their own office, so the column
 * repeats one value down the page. That repetition is the point -- it is the
 * answer to "am I looking at my department or at everybody".
 */
function OfficeCell({
    user,
}: {
    user: Pick<UserRow, 'office' | 'office_name'>;
}) {
    if (!user.office && !user.office_name) {
        return <span className="text-copy">—</span>;
    }

    return (
        <>
            <span className="font-medium text-navy">{user.office ?? '—'}</span>
            {user.office_name && (
                <span className="block text-xs text-copy">
                    {user.office_name}
                </span>
            )}
        </>
    );
}

function StatusPill({ active }: { active: boolean }) {
    return (
        <span
            className={`inline-block rounded-md px-3 py-1 text-xs font-bold text-white ${
                active ? 'bg-[#5BC45B]' : 'bg-[#9AA3B4]'
            }`}
        >
            {active ? 'Active' : 'Deactivated'}
        </span>
    );
}

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-baseline gap-2">
            <dt className="text-copy">{label}</dt>
            <dd className="font-semibold text-navy">{children}</dd>
        </div>
    );
}

function Filter({
    label,
    value,
    onChange,
    options,
    clearable = true,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: SelectOption[];
    clearable?: boolean;
}) {
    return (
        <>
            <label className="sr-only" htmlFor={`filter-${label}`}>
                {label}
            </label>
            <select
                id={`filter-${label}`}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="h-10 rounded-lg border border-[#E4EAF3] bg-white px-3 text-sm font-medium text-navy focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none sm:w-40"
            >
                {clearable && <option value="">{label}</option>}
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </>
    );
}

AdminUsers.layout = {
    breadcrumbs: [{ title: 'Users', href: admin.users.index() }],
};
