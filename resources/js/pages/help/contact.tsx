import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Clock, Mail, MapPin, Phone, Send, Smile } from 'lucide-react';
import { HelpScene } from '@/components/help/help-scene';
import InputError from '@/components/input-error';
import { login } from '@/routes';
import help from '@/routes/help';

type Props = {
    support: {
        office: string | null;
        email: string | null;
        phone: string | null;
        address: string | null;
        hours: string | null;
        hours_detail: string | null;
        mail_configured: boolean;
    };
};

/**
 * §23 Contact Support.
 *
 * Two columns: the office's published details on the left, a message form on
 * the right. The form posts to the same endpoint as the support ticket rather
 * than a second one -- it is the same act with fewer fields, and one delivery
 * path means one place where "was this actually sent?" is answered honestly.
 */
export default function HelpContact({ support }: Props) {
    const { auth } = usePage().props;

    const rows = [
        {
            icon: MapPin,
            label: 'Office Address',
            value: support.address,
            detail: support.office,
        },
        {
            icon: Phone,
            label: 'Phone Number',
            value: support.phone,
            href: support.phone
                ? `tel:${support.phone.replace(/[^\d+]/g, '')}`
                : null,
        },
        {
            icon: Mail,
            label: 'Email',
            value: support.email,
            href: support.email ? `mailto:${support.email}` : null,
        },
        {
            icon: Clock,
            label: 'Office Hours',
            value: support.hours,
            detail: support.hours_detail,
        },
    ];

    return (
        <>
            <Head title="Contact Support" />

            <HelpScene
                back={{ href: help.index(), label: 'Back' }}
                title="Contact Support"
                subtitle="Reach out to our support team directly."
            >
                <div className="grid gap-6 lg:grid-cols-2 lg:items-start">
                    <section className="rounded-2xl bg-white px-6 py-7 shadow-xl">
                        <h2 className="sr-only">Office contact details</h2>

                        <dl className="divide-y divide-dotted divide-[#D8E3F2]">
                            {rows.map((row) => (
                                <div
                                    key={row.label}
                                    className="flex items-start gap-4 py-5 first:pt-0 last:pb-0"
                                >
                                    <span
                                        aria-hidden="true"
                                        className="mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-full border-2 border-[#3B72C4] text-[#3B72C4]"
                                    >
                                        <row.icon className="size-5" />
                                    </span>

                                    <div className="min-w-0">
                                        <dt className="text-[15px] font-bold text-navy">
                                            {row.label}
                                        </dt>
                                        <dd className="mt-0.5 text-[15px] font-bold break-words text-link">
                                            {row.value ? (
                                                row.href ? (
                                                    <a
                                                        href={row.href}
                                                        className="hover:underline"
                                                    >
                                                        {row.value}
                                                    </a>
                                                ) : (
                                                    row.value
                                                )
                                            ) : (
                                                <span className="font-semibold text-copy">
                                                    Not published yet — ask an
                                                    administrator to set this in
                                                    the system settings.
                                                </span>
                                            )}
                                        </dd>
                                        {row.detail && (
                                            <p className="text-[15px] font-bold text-link">
                                                {row.detail}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </dl>
                    </section>

                    <section className="rounded-2xl bg-white px-6 py-7 shadow-xl">
                        <h2 className="text-xl font-extrabold tracking-tight text-navy">
                            Send us a Message
                        </h2>

                        {auth?.user ? (
                            <>
                                {/*
                                    The same honesty as the ticket page. This
                                    form goes down the identical path, so it must
                                    not imply a delivery the server cannot
                                    perform.
                                */}
                                {!support.mail_configured && (
                                    <p
                                        role="status"
                                        className="mt-4 rounded-lg bg-[#FDF3DC] px-4 py-3 text-xs leading-relaxed text-[#7A5B12]"
                                    >
                                        Outgoing mail is not configured on this
                                        server. A message sent here is recorded
                                        in the system log rather than delivered
                                        — please use the phone number opposite
                                        if it is urgent.
                                    </p>
                                )}

                                <Form
                                    {...help.ticket.store.form()}
                                    options={{ preserveScroll: true }}
                                    resetOnSuccess={['body']}
                                    className="mt-4"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            {/*
                                                The endpoint is the ticket
                                                endpoint, so it expects an issue
                                                type. This shorter form does not
                                                ask for one; "Something else" is
                                                the honest constant rather than a
                                                guess at what the message is
                                                about.
                                            */}
                                            <input
                                                type="hidden"
                                                name="issue_type"
                                                value="Something else"
                                            />

                                            <label
                                                className="sr-only"
                                                htmlFor="name"
                                            >
                                                Full Name
                                            </label>
                                            <input
                                                id="name"
                                                name="name"
                                                autoComplete="name"
                                                defaultValue={
                                                    auth?.user?.name ?? ''
                                                }
                                                placeholder="Full Name"
                                                aria-invalid={
                                                    errors.name
                                                        ? true
                                                        : undefined
                                                }
                                                className="h-12 w-full rounded-lg border border-[#E3E8EF] bg-white px-4 text-[15px] text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                                            />
                                            <InputError message={errors.name} />

                                            <label
                                                className="sr-only"
                                                htmlFor="email"
                                            >
                                                Email Address
                                            </label>
                                            <input
                                                id="email"
                                                name="email"
                                                type="email"
                                                autoComplete="email"
                                                defaultValue={
                                                    auth?.user?.email ?? ''
                                                }
                                                placeholder="Email Address"
                                                aria-invalid={
                                                    errors.email
                                                        ? true
                                                        : undefined
                                                }
                                                className="mt-4 h-12 w-full rounded-lg border border-[#E3E8EF] bg-white px-4 text-[15px] text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                                            />
                                            <InputError
                                                message={errors.email}
                                            />

                                            <div className="mt-4 rounded-lg border border-[#E3E8EF] bg-white px-4 py-3">
                                                <label
                                                    htmlFor="body"
                                                    className="block text-[15px] font-bold text-navy"
                                                >
                                                    Message
                                                </label>
                                                <textarea
                                                    id="body"
                                                    name="body"
                                                    rows={4}
                                                    maxLength={5000}
                                                    placeholder="Type your message here..."
                                                    aria-invalid={
                                                        errors.body
                                                            ? true
                                                            : undefined
                                                    }
                                                    className="mt-1 w-full resize-y border-0 p-0 text-[15px] text-navy placeholder:text-[#8A9AAE] focus-visible:ring-0 focus-visible:outline-none"
                                                />
                                            </div>
                                            <InputError message={errors.body} />

                                            <button
                                                type="submit"
                                                disabled={processing}
                                                className="mt-5 flex w-full items-center justify-center gap-2 rounded-lg bg-[#3B72C4] px-6 py-3.5 text-[15px] font-bold text-white shadow-lg transition hover:bg-[#31629F] disabled:opacity-60"
                                            >
                                                <Send
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                                {processing
                                                    ? 'Sending…'
                                                    : 'Send Message'}
                                            </button>
                                        </>
                                    )}
                                </Form>
                            </>
                        ) : (
                            /*
                                No form for a guest. §23's contact form posts to
                                the ticket endpoint, which attributes the message
                                to the session -- so a guest who filled this in
                                would be bounced to /login on submit and lose
                                every word of it. The office's own details
                                opposite still read with no account, which is the
                                point of the page being public at all.
                            */
                            <div className="mt-4 rounded-lg border border-[#E3E8EF] bg-[#F7FAFE] px-5 py-6">
                                <p className="text-[15px] leading-relaxed text-copy">
                                    A message is answered to the account it came
                                    from, so sending one needs you to sign in
                                    first. The office details opposite need no
                                    account at all.
                                </p>

                                <Link
                                    href={login()}
                                    className="mt-5 flex w-full items-center justify-center gap-2 rounded-lg bg-[#3B72C4] px-6 py-3.5 text-[15px] font-bold text-white shadow-lg transition hover:bg-[#31629F]"
                                >
                                    <Send
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    Sign in to send a message
                                </Link>
                            </div>
                        )}

                        <p className="mt-4 inline-flex items-center gap-2 rounded-lg bg-[#EEF4FC] px-4 py-3 text-[15px] font-bold text-navy">
                            <Smile aria-hidden="true" className="size-5" />
                            We&rsquo;re here to help you!
                        </p>

                        <p className="mt-4 text-xs text-copy">
                            Need to attach details about a specific document?{' '}
                            <Link
                                href={help.ticket()}
                                className="font-bold text-link hover:underline"
                            >
                                Submit a ticket instead
                            </Link>
                            .
                        </p>
                    </section>
                </div>
            </HelpScene>
        </>
    );
}

HelpContact.layout = {
    breadcrumbs: [
        { title: 'Help', href: help.index() },
        { title: 'Contact Support', href: help.contact() },
    ],
};
