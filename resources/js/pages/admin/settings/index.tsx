import { Form, Head, Link } from '@inertiajs/react';
import { Settings as SettingsIcon } from 'lucide-react';
import { PanelHeading } from '@/components/admin/panel-heading';
import admin from '@/routes/admin';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { SelectOption } from '@/types';

type Props = {
    options: {
        language: SelectOption[];
        timezone: SelectOption[];
        date_format: SelectOption[];
    };
    timeouts: { value: number; label: string }[];
    settings: {
        language: string;
        timezone: string;
        date_format: string;
        session_timeout: number;
    };
    account: {
        name: string;
        email: string;
        two_factor_enabled: boolean;
    };
};

/**
 * §4's Settings screen.
 *
 * General Settings is the only group that stores anything here. Account and
 * Security link into the existing profile and security screens rather than
 * reimplementing email changes, password changes and two-factor enrolment
 * inside the panel -- a second, less-tested path to those three operations is
 * exactly what an attacker would hope for.
 */
export default function AdminSettings({
    options,
    timeouts,
    settings,
    account,
}: Props) {
    return (
        <>
            <Head title="Settings" />

            <PanelHeading />

            <h2 className="mt-6 text-2xl font-extrabold tracking-tight text-navy">
                Settings
            </h2>

            <Form
                {...admin.settings.update.form()}
                options={{ preserveScroll: true }}
                className="mt-4"
            >
                {({ processing }) => (
                    <>
                        <Card title="General Settings">
                            <div className="space-y-4">
                                <Field
                                    label="Language"
                                    name="language"
                                    defaultValue={settings.language}
                                    options={options.language}
                                />
                                <Field
                                    label="Time Zone"
                                    name="timezone"
                                    defaultValue={settings.timezone}
                                    options={options.timezone}
                                />
                                <Field
                                    label="Date Format"
                                    name="date_format"
                                    defaultValue={settings.date_format}
                                    options={options.date_format}
                                />
                            </div>

                            <div className="mt-6 flex justify-end">
                                <SaveButton processing={processing} />
                            </div>
                        </Card>

                        <Card title="Account Settings" className="mt-6">
                            <div className="grid gap-6 sm:grid-cols-2">
                                <Action
                                    label="Change Email"
                                    detail={account.email}
                                    href={editProfile()}
                                    cta="Change Email"
                                />
                                <Action
                                    label="Change Name"
                                    detail={account.name}
                                    href={editProfile()}
                                    cta="Change Username"
                                />
                            </div>
                        </Card>

                        <Card title="Security Settings" className="mt-6">
                            <div className="space-y-5">
                                <Action
                                    label="Change Password"
                                    href={editSecurity()}
                                    cta="Change Password"
                                />
                                <Action
                                    label="Two-Factor Authentication"
                                    detail={
                                        account.two_factor_enabled
                                            ? 'Enabled on this account.'
                                            : 'Not enabled.'
                                    }
                                    href={editSecurity()}
                                    cta={
                                        account.two_factor_enabled
                                            ? 'Manage'
                                            : 'Enable'
                                    }
                                />

                                {/*
                                Where the design puts it, and still part of the
                                preferences form above -- which is why that form
                                wraps all three cards. Unlike the two rows above
                                it this is a stored setting, and it is genuinely
                                enforced: EnforceSessionTimeout signs an idle
                                session out rather than merely recording a
                                number nobody reads.
                            */}
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <label
                                        htmlFor="session_timeout"
                                        className="text-sm font-bold text-navy"
                                    >
                                        Session Timeout
                                    </label>
                                    <select
                                        id="session_timeout"
                                        name="session_timeout"
                                        defaultValue={String(
                                            settings.session_timeout,
                                        )}
                                        className="h-10 rounded-md border border-[#D8E3F2] bg-white px-3 text-sm text-navy focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                                    >
                                        {timeouts.map((timeout) => (
                                            <option
                                                key={timeout.value}
                                                value={timeout.value}
                                            >
                                                {timeout.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="flex justify-end">
                                    <SaveButton processing={processing} />
                                </div>
                            </div>
                        </Card>
                    </>
                )}
            </Form>
        </>
    );
}

function SaveButton({ processing }: { processing: boolean }) {
    return (
        <button
            type="submit"
            disabled={processing}
            className="rounded-md bg-[#3B72C4] px-6 py-2 text-sm font-bold text-white transition hover:bg-[#31629F] disabled:opacity-60"
        >
            {processing ? 'Saving…' : 'Save Changes'}
        </button>
    );
}

function Card({
    title,
    children,
    className = '',
}: {
    title: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section
            className={`rounded-xl bg-white p-6 shadow-sm ${className}`.trim()}
        >
            <div className="flex items-center gap-3 border-b border-[#EEF2F7] pb-4">
                <span
                    aria-hidden="true"
                    className="flex size-9 items-center justify-center rounded-full bg-[#E8F0FB] text-[#3B72C4]"
                >
                    <SettingsIcon className="size-5" />
                </span>
                <h3 className="text-lg font-extrabold tracking-tight text-navy">
                    {title}
                </h3>
            </div>

            <div className="pt-5">{children}</div>
        </section>
    );
}

function Field({
    label,
    name,
    defaultValue,
    options,
}: {
    label: string;
    name: string;
    defaultValue: string;
    options: SelectOption[];
}) {
    return (
        <div className="grid items-center gap-2 sm:grid-cols-[12rem_minmax(0,24rem)]">
            <label htmlFor={name} className="text-sm font-bold text-navy">
                {label}
            </label>
            <select
                id={name}
                name={name}
                defaultValue={defaultValue}
                className="h-10 rounded-md border border-[#D8E3F2] bg-white px-3 text-sm text-navy focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

function Action({
    label,
    detail,
    href,
    cta,
}: {
    label: string;
    detail?: string;
    href: ReturnType<typeof editProfile>;
    cta: string;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="min-w-0">
                <p className="text-sm font-bold text-navy">{label}</p>
                {detail && (
                    <p className="truncate text-xs text-copy">{detail}</p>
                )}
            </div>

            <Link
                href={href}
                className="shrink-0 rounded-md bg-[#3B72C4] px-4 py-2 text-sm font-bold text-white no-underline transition hover:bg-[#31629F]"
            >
                {cta}
            </Link>
        </div>
    );
}

AdminSettings.layout = {
    breadcrumbs: [{ title: 'Settings', href: admin.settings.edit() }],
};
