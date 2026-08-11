import { Form, Head, router } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { LastLogin } from '@/components/admin/last-login';
import { PanelHeading } from '@/components/admin/panel-heading';
import InputError from '@/components/input-error';
import superAdmin from '@/routes/super-admin';
import type { Paginated, SelectOption } from '@/types';

type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    office: string | null;
    is_active: boolean;
    last_login_at: string | null;
};

type Props = {
    users: Omit<Paginated<UserRow>, 'links'>;
    filters: { q: string };
    roles: SelectOption[];
    offices: SelectOption[];
};

/**
 * §4's Manage Users screen.
 *
 * The only screen in the system that can create an account, which is why the
 * form states plainly that the password is handed over by hand: this
 * deployment has no outgoing mail, so nothing is emailed to the new user.
 */
export default function ManageUsers({ users, filters, roles, offices }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [adding, setAdding] = useState(false);

    // Read through a ref rather than closed over: `filters` is a new object on
    // every response, so a useCallback keyed on it would rebuild `apply`, which
    // re-runs the debounce effect, which fires another request -- a loop that
    // only appears once the server echoes filters back.
    const latest = useRef(filters);

    useEffect(() => {
        latest.current = filters;
    }, [filters]);

    const apply = useCallback((next: Partial<{ q: string }>) => {
        router.get(
            superAdmin.users.index.url(),
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
            <Head title="Manage Users" />

            <PanelHeading />

            <h2 className="mt-6 text-2xl font-extrabold tracking-tight text-navy">
                Manage Users
            </h2>

            <section className="mt-4 rounded-xl border border-[#E4EAF3] bg-white p-6 shadow-sm">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div className="relative min-w-0 flex-1">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#8A9AAE]"
                        />
                        <input
                            type="search"
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder="Search Admins.."
                            aria-label="Search accounts by name or email"
                            className="h-12 w-full rounded-full border border-[#E4EAF3] pr-4 pl-12 text-[15px] text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                        />
                    </div>

                    <button
                        type="button"
                        onClick={() => setAdding((open) => !open)}
                        aria-expanded={adding}
                        className="flex items-center justify-center gap-2 rounded-lg bg-[#3B72C4] px-7 py-3 text-[15px] font-bold text-white shadow-lg transition hover:bg-[#31629F]"
                    >
                        <Plus aria-hidden="true" className="size-5" />
                        Add New Admin
                    </button>
                </div>

                {adding && (
                    <AddAccountForm
                        roles={roles}
                        offices={offices}
                        onDone={() => setAdding(false)}
                    />
                )}

                {/* Phone: cards, because a five-column table at 375px hides
                    Status and Last Login entirely. */}
                <ul className="mt-6 divide-y divide-[#EEF2F7] md:hidden">
                    {users.data.length === 0 && (
                        <li className="py-10 text-center text-sm text-copy">
                            No accounts match that search.
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
                            <p className="mt-1 text-sm text-copy">
                                {user.role_label}
                                {user.office && ` · ${user.office}`}
                            </p>
                            <div className="mt-2 flex items-center gap-3">
                                <StatusPill active={user.is_active} />
                                <span className="text-sm text-copy">
                                    <LastLogin iso={user.last_login_at} />
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>

                <div className="mt-6 hidden overflow-x-auto md:block">
                    <table className="w-full min-w-[680px] text-left">
                        <thead>
                            <tr>
                                {[
                                    'Name',
                                    'Email',
                                    'Role',
                                    'Status',
                                    'Last Login',
                                ].map((heading) => (
                                    <th
                                        key={heading}
                                        scope="col"
                                        className="px-3 pb-4 text-[15px] font-bold text-navy"
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
                                        colSpan={5}
                                        className="px-3 py-10 text-center text-sm text-copy"
                                    >
                                        No accounts match that search.
                                    </td>
                                </tr>
                            )}

                            {users.data.map((user) => (
                                <tr key={user.id}>
                                    <td className="px-3 py-4 text-sm text-navy">
                                        {user.name}
                                    </td>
                                    <td className="px-3 py-4 text-sm text-copy">
                                        {user.email}
                                    </td>
                                    <td className="px-3 py-4 text-sm text-copy">
                                        {user.role_label}
                                    </td>
                                    <td className="px-3 py-4">
                                        <StatusPill active={user.is_active} />
                                    </td>
                                    <td className="px-3 py-4 text-sm whitespace-nowrap text-copy">
                                        <LastLogin iso={user.last_login_at} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {users.last_page > 1 && (
                    <nav
                        aria-label="Pagination"
                        className="mt-4 flex flex-wrap items-center justify-between gap-3"
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
                                            superAdmin.users.index.url(),
                                            { ...filters, page },
                                            {
                                                preserveState: true,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                    className={`min-w-9 rounded-md px-3 py-1.5 text-sm font-medium transition ${
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
            </section>
        </>
    );
}

function AddAccountForm({
    roles,
    offices,
    onDone,
}: {
    roles: SelectOption[];
    offices: SelectOption[];
    onDone: () => void;
}) {
    const [role, setRole] = useState('admin');

    return (
        <Form
            {...superAdmin.users.store.form()}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            resetOnSuccess
            className="mt-6 rounded-xl border border-[#E4EAF3] bg-[#F7FAFF] p-5"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-lg font-extrabold tracking-tight text-navy">
                        Add a new account
                    </h3>

                    {/*
                        Said before the form is filled in, not after it is
                        submitted. Nothing is emailed to the new user on this
                        deployment, so whoever creates the account has to know
                        they are responsible for handing over the password.
                    */}
                    <p className="mt-1 max-w-prose text-xs leading-relaxed text-copy">
                        The account can sign in immediately. Outgoing mail is
                        not configured on this server, so no invitation is sent
                        — give the person their password yourself, and ask them
                        to change it from Settings once they are in.
                    </p>

                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Full name"
                            name="name"
                            autoComplete="off"
                            error={errors.name}
                        />
                        <Field
                            label="Email address"
                            name="email"
                            type="email"
                            autoComplete="off"
                            error={errors.email}
                        />

                        <div>
                            <Label htmlFor="role">Role</Label>
                            <select
                                id="role"
                                name="role"
                                value={role}
                                onChange={(event) =>
                                    setRole(event.target.value)
                                }
                                className="mt-2 h-11 w-full rounded-lg border border-[#E3E8EF] bg-white px-3 text-[15px] text-navy focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                            >
                                {roles.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.role} />
                        </div>

                        <div>
                            <Label htmlFor="office_id">Office</Label>
                            <select
                                id="office_id"
                                name="office_id"
                                // A Super Admin has no office -- seeing past
                                // office scoping is what the role is for -- so
                                // the field goes away rather than being sent
                                // and ignored.
                                disabled={role === 'super_admin'}
                                className="mt-2 h-11 w-full rounded-lg border border-[#E3E8EF] bg-white px-3 text-[15px] text-navy focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none disabled:bg-[#EEF2F7] disabled:text-copy"
                            >
                                <option value="">
                                    {role === 'super_admin'
                                        ? 'Not applicable'
                                        : 'Select an office'}
                                </option>
                                {offices.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.office_id} />
                        </div>

                        <Field
                            label="Password"
                            name="password"
                            type="password"
                            autoComplete="new-password"
                            error={errors.password}
                        />
                        <Field
                            label="Confirm password"
                            name="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            error={errors.password_confirmation}
                        />
                    </div>

                    <div className="mt-5 flex justify-end gap-3">
                        <button
                            type="button"
                            onClick={onDone}
                            className="rounded-lg border border-[#D8E3F2] bg-white px-6 py-2.5 text-sm font-bold text-navy transition hover:bg-[#F2F6FC]"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-lg bg-[#3B72C4] px-6 py-2.5 text-sm font-bold text-white transition hover:bg-[#31629F] disabled:opacity-60"
                        >
                            {processing ? 'Creating…' : 'Create account'}
                        </button>
                    </div>
                </>
            )}
        </Form>
    );
}

function Label({
    htmlFor,
    children,
}: {
    htmlFor: string;
    children: React.ReactNode;
}) {
    return (
        <label htmlFor={htmlFor} className="block text-sm font-bold text-navy">
            {children}
        </label>
    );
}

function Field({
    label,
    name,
    error,
    type = 'text',
    ...props
}: {
    label: string;
    name: string;
    error?: string;
    type?: string;
} & React.InputHTMLAttributes<HTMLInputElement>) {
    return (
        <div>
            <Label htmlFor={name}>{label}</Label>
            <input
                id={name}
                name={name}
                type={type}
                aria-invalid={error ? true : undefined}
                className="mt-2 h-11 w-full rounded-lg border border-[#E3E8EF] bg-white px-3 text-[15px] text-navy focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                {...props}
            />
            <InputError message={error} />
        </div>
    );
}

function StatusPill({ active }: { active: boolean }) {
    return (
        <span
            className={`inline-block rounded-md px-4 py-1 text-xs font-bold text-white ${
                active ? 'bg-[#5BC45B]' : 'bg-[#9AA3B4]'
            }`}
        >
            {active ? 'Active' : 'Deactivated'}
        </span>
    );
}

ManageUsers.layout = {
    breadcrumbs: [{ title: 'Manage Users', href: superAdmin.users.index() }],
};
