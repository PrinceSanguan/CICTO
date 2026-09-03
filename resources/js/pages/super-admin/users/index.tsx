import { Form, Head, router } from '@inertiajs/react';
import { AlertTriangle, KeyRound, Plus, Search } from 'lucide-react';
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
    office_name: string | null;
    is_active: boolean;
    last_login_at: string | null;
    /** Whether a password reset alone would still leave them locked out. */
    has_two_factor: boolean;
    passkeys: number;
};

type Props = {
    users: Omit<Paginated<UserRow>, 'links'>;
    filters: { q: string };
    roles: SelectOption[];
    offices: SelectOption[];
    /**
     * The signed-in Super Admin.
     *
     * Their row is still LISTED -- they are an account like any other and
     * belong in the register. What is omitted is the control: your own
     * password is changed under Settings > Security, where the current one has
     * to be typed, and the action refuses this route for yourself anyway.
     */
    viewerId: number;
};

/**
 * §4's Manage Users screen.
 *
 * The only screen in the system that can create an account, which is why the
 * form states plainly that the password is handed over by hand: nothing is
 * ever emailed to a new user.
 *
 * It is also the only screen that can set somebody ELSE's password. That is
 * client question B3's answer built: CICTO declined to supply SMTP credentials
 * and asked instead for "a module that allows the system administrator to reset
 * the password of any user registered in your application". With no mail there
 * is no reset link, so this is the whole recovery path for a forgotten
 * password.
 */
export default function ManageUsers({
    users,
    filters,
    roles,
    offices,
    viewerId,
}: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [adding, setAdding] = useState(false);

    // The row whose password is being set, if any. One panel rather than a
    // form per row: at fifteen rows a page that is fifteen collapsed forms in
    // the DOM, and only ever one of them is wanted at a time.
    const [resetting, setResetting] = useState<UserRow | null>(null);

    const openReset = useCallback((user: UserRow | null) => {
        setAdding(false);
        setResetting(user);
    }, []);

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

            <PanelHeading title="Super Admin Panel" />

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
                        onClick={() => {
                            // One panel at a time. Both are rendered in the same
                            // place and both are a form full of password boxes;
                            // two of them stacked is how somebody sets the wrong
                            // account's password.
                            setResetting(null);
                            setAdding((open) => !open);
                        }}
                        aria-expanded={adding}
                        aria-controls="add-account-panel"
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

                {resetting && (
                    <ResetPasswordForm
                        key={resetting.id}
                        user={resetting}
                        onDone={() => setResetting(null)}
                    />
                )}

                {/* Phone: cards, because a seven-column table at 375px hides
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
                                {user.office_name && ` (${user.office_name})`}
                            </p>
                            <div className="mt-2 flex items-center gap-3">
                                <StatusPill active={user.is_active} />
                                <span className="text-sm text-copy">
                                    <LastLogin iso={user.last_login_at} />
                                </span>
                            </div>
                            {user.id !== viewerId && (
                                <ResetButton
                                    user={user}
                                    open={resetting?.id === user.id}
                                    onOpen={openReset}
                                    className="mt-3"
                                />
                            )}
                        </li>
                    ))}
                </ul>

                <div className="mt-6 hidden overflow-x-auto md:block">
                    <table className="w-full min-w-[820px] text-left">
                        <thead>
                            <tr>
                                {[
                                    'Name',
                                    'Email',
                                    'Role',
                                    'Office',
                                    'Status',
                                    'Last Login',
                                    'Password',
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
                                        colSpan={7}
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
                                    <td className="px-3 py-4 text-sm">
                                        <OfficeCell user={user} />
                                    </td>
                                    <td className="px-3 py-4">
                                        <StatusPill active={user.is_active} />
                                    </td>
                                    <td className="px-3 py-4 text-sm whitespace-nowrap text-copy">
                                        <LastLogin iso={user.last_login_at} />
                                    </td>
                                    <td className="px-3 py-4">
                                        {/* Absent, not disabled, on the
                                            viewer's own row: their own password
                                            is changed under Settings >
                                            Security, where the current one has
                                            to be typed, and the action refuses
                                            this route for themselves anyway. */}
                                        {user.id !== viewerId && (
                                            <ResetButton
                                                user={user}
                                                open={resetting?.id === user.id}
                                                onOpen={openReset}
                                            />
                                        )}
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

function ResetButton({
    user,
    open,
    onOpen,
    className = '',
}: {
    user: UserRow;
    open: boolean;
    onOpen: (user: UserRow | null) => void;
    className?: string;
}) {
    return (
        <button
            type="button"
            onClick={() => onOpen(open ? null : user)}
            aria-expanded={open}
            aria-controls="reset-password-panel"
            className={`flex items-center gap-2 rounded-lg border border-[#D8E3F2] bg-white px-4 py-2 text-sm font-bold whitespace-nowrap text-navy transition hover:bg-[#F2F6FC] ${className}`}
        >
            <KeyRound aria-hidden="true" className="size-4" />
            {/* Named for the person it is about. Fifteen buttons all reading
                "Reset" is fifteen chances to reset the wrong row. */}
            <span>
                Set password
                <span className="sr-only"> for {user.name}</span>
            </span>
        </button>
    );
}

/**
 * Client question B3's module: set a password on somebody else's behalf.
 *
 * Two things it does that the create form does not, both because this account
 * already exists and may already be in trouble:
 *
 *  - it asks for the ADMINISTRATOR's own password. The house pattern for a
 *    high-consequence action is the password.confirm middleware, but that
 *    redirects an Inertia POST to a separate page and the typed password is
 *    gone when it comes back. Asking in the form buys the same property -- an
 *    unattended signed-in browser cannot take over an account -- and keeps what
 *    was typed;
 *  - it offers to remove second factors, but only when there are any. An
 *    account whose owner forgot a password wants them left alone; an account in
 *    somebody else's hands does not, and a password reset that leaves a passkey
 *    in place has revoked nothing at all.
 */
function ResetPasswordForm({
    user,
    onDone,
}: {
    user: UserRow;
    onDone: () => void;
}) {
    /*
        Bring the panel to the administrator.

        The list paginates at fifteen and the panel renders above the table, so
        pressing "Set password" on a row near the bottom mounted a form entirely
        off-screen AND pushed every row down by its height -- the row under the
        cursor became a different person, with nothing visible to explain it.
        Measured on a 1366x768 laptop: the panel opened ~517px above the
        viewport. Scrolling to it and putting the caret in the first field means
        the button does what pressing a button should.
    */
    // The ref is on the heading, not the <Form>: Inertia's Form forwards a
    // FormComponentRef (reset, setError, ...), never the DOM node.
    const heading = useRef<HTMLHeadingElement | null>(null);

    useEffect(() => {
        heading.current?.scrollIntoView({
            block: 'center',
            behavior: 'smooth',
        });

        document
            .querySelector<HTMLInputElement>('#reset-password')
            ?.focus({ preventScroll: true });
    }, []);

    const secondFactors = [
        user.has_two_factor ? 'two-factor' : null,
        user.passkeys > 0
            ? `${user.passkeys} passkey${user.passkeys === 1 ? '' : 's'}`
            : null,
    ].filter(Boolean) as string[];

    return (
        <Form
            {...superAdmin.users.password.form(user.id)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            resetOnSuccess
            id="reset-password-panel"
            aria-labelledby="reset-password-heading"
            className="mt-6 rounded-xl border border-[#E4EAF3] bg-[#F7FAFF] p-5"
        >
            {({ processing, errors }) => (
                <>
                    <h3
                        ref={heading}
                        id="reset-password-heading"
                        className="text-lg font-extrabold tracking-tight text-navy"
                    >
                        Set a new password for {user.name}
                    </h3>
                    <p className="mt-1 text-sm break-all text-copy">
                        {user.email}
                        {user.office && ` · ${user.office}`}
                    </p>

                    {/*
                        Said before the form is filled in. Every one of these is
                        something the administrator has to act on afterwards,
                        and finding out about it in a toast is too late to plan
                        the phone call.
                    */}
                    <p className="mt-4 max-w-prose text-xs leading-relaxed text-copy">
                        Nothing is emailed — give the password to them yourself
                        and ask them to change it under Settings &gt; Security.
                        They will be signed out on every device they are
                        currently signed in on.
                    </p>

                    {!user.is_active && (
                        <p
                            role="status"
                            className="mt-4 flex gap-2 rounded-lg bg-[#FDF3DC] px-4 py-3 text-xs leading-relaxed font-semibold text-[#7A5B12]"
                        >
                            <AlertTriangle
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0"
                            />
                            <span>
                                This account is deactivated. A new password will
                                not let them in until somebody reactivates it.
                            </span>
                        </p>
                    )}

                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <Field
                            label="New password"
                            name="password"
                            id="reset-password"
                            type="password"
                            autoComplete="new-password"
                            error={errors.password}
                        />
                        <Field
                            label="Confirm new password"
                            name="password_confirmation"
                            id="reset-password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            error={errors.password_confirmation}
                        />
                    </div>

                    <div className="mt-4 sm:max-w-[calc(50%-0.5rem)]">
                        <Field
                            label="Your own password"
                            name="your_password"
                            id="reset-your_password"
                            type="password"
                            autoComplete="current-password"
                            error={errors.your_password}
                        />
                        <p className="mt-1.5 text-xs text-copy">
                            Confirms it is you, not a browser somebody left
                            signed in.
                        </p>
                    </div>

                    {secondFactors.length > 0 && (
                        <label className="mt-5 flex max-w-prose gap-3 rounded-lg border border-[#E4EAF3] bg-white p-4">
                            <input
                                type="checkbox"
                                name="revoke_second_factors"
                                value="1"
                                className="mt-0.5 size-4 shrink-0 rounded border-[#C7D4E6] text-[#3B72C4] focus-visible:ring-2 focus-visible:ring-[#3B72C4]"
                            />
                            <span className="text-xs leading-relaxed text-copy">
                                <span className="block text-sm font-bold text-navy">
                                    Also remove {secondFactors.join(' and ')}
                                </span>
                                Leave this off if they simply forgot their
                                password. Tick it if the account may be in
                                somebody else&apos;s hands.
                                {user.passkeys > 0 &&
                                    ' A passkey signs them in without the password ever being asked for, so a reset on its own would revoke nothing.'}
                                {user.has_two_factor &&
                                    ' Tick it too if they have lost the phone that holds their two-factor codes, or they still will not get in.'}
                            </span>
                        </label>
                    )}

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
                            {processing ? 'Setting…' : 'Set password'}
                        </button>
                    </div>
                </>
            )}
        </Form>
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
            id="add-account-panel"
            className="mt-6 rounded-xl border border-[#E4EAF3] bg-[#F7FAFF] p-5"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-lg font-extrabold tracking-tight text-navy">
                        Add a new account
                    </h3>

                    {/*
                        Said before the form is filled in, not after it is
                        submitted. This application never emails a credential to
                        anybody -- configured mail or not, because a password in
                        an inbox outlives every use of it -- so whoever creates
                        the account is responsible for handing it over.
                    */}
                    <p className="mt-1 max-w-prose text-xs leading-relaxed text-copy">
                        The account can sign in immediately. No invitation is
                        emailed — give the person their password yourself, and
                        ask them to change it from Settings once they are in.
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

/**
 * `id` is separate from `name` because two panels on this page can be open at
 * once and both have a field called `password`.
 *
 * A duplicate id is not cosmetic here: `label[for]` resolves to the FIRST match
 * in the document, so with the Add-account form open above it, clicking the
 * reset panel's "New password" label put the caret in the Add form's box. The
 * administrator then types a password into the wrong form and submits a reset
 * with an empty one. The posted field is keyed on `name`, which stays as it is.
 */
function Field({
    label,
    name,
    id,
    error,
    type = 'text',
    ...props
}: {
    label: string;
    name: string;
    id?: string;
    error?: string;
    type?: string;
} & React.InputHTMLAttributes<HTMLInputElement>) {
    const fieldId = id ?? name;

    return (
        <div>
            <Label htmlFor={fieldId}>{label}</Label>
            <input
                id={fieldId}
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

/**
 * Which office an account belongs to -- the client's ask of 2026-09-03.
 *
 * Code on top, full name under it. The code is what appears in control numbers
 * and on printed labels, so it is the identifier somebody is matching against;
 * the name is what makes it readable to anyone who has not memorised thirty
 * abbreviations.
 *
 * A Super Admin genuinely has no office, so the em dash is the truth rather
 * than missing data.
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
