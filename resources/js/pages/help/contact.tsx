import { Head, Link } from '@inertiajs/react';
import { Building2, ChevronLeft, Mail, Phone } from 'lucide-react';
import help from '@/routes/help';

type Props = {
    support: {
        office: string | null;
        email: string | null;
        phone: string | null;
        mail_configured: boolean;
    };
};

/** §23 Contact Support: details from config, no form. */
export default function HelpContact({ support }: Props) {
    const rows = [
        { icon: Building2, label: 'Office', value: support.office },
        {
            icon: Mail,
            label: 'Email',
            value: support.email,
            href: support.email ? `mailto:${support.email}` : null,
        },
        {
            icon: Phone,
            label: 'Phone',
            value: support.phone,
            href: support.phone
                ? `tel:${support.phone.replace(/\s/g, '')}`
                : null,
        },
    ];

    return (
        <>
            <Head title="Contact Support" />

            <Link
                href={help.index()}
                className="inline-flex items-center gap-1 text-sm font-bold text-white/90 transition hover:text-white"
            >
                <ChevronLeft className="size-4" />
                Back to Help
            </Link>

            <h1 className="mt-4 text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                Contact Support
            </h1>
            <p className="mt-1 text-[15px] font-medium text-white/90">
                Reach out to our support team directly for assistance.
            </p>

            <div className="mt-6 max-w-2xl rounded-xl bg-white p-6 shadow-xl sm:p-8">
                <dl className="space-y-5">
                    {rows.map((row) => (
                        <div key={row.label} className="flex items-start gap-4">
                            <row.icon
                                aria-hidden="true"
                                className="mt-0.5 size-6 shrink-0 text-[#3B72C4]"
                                strokeWidth={1.75}
                            />
                            <div className="min-w-0">
                                <dt className="text-sm font-bold text-navy">
                                    {row.label}
                                </dt>
                                <dd className="text-[15px] break-words text-copy">
                                    {row.value === null || row.value === '' ? (
                                        // Never a blank line: an empty contact
                                        // card looks like the page failed to
                                        // load rather than like a setting
                                        // nobody has filled in.
                                        <span className="text-[#9AA5B4]">
                                            Not published yet — ask an
                                            administrator to set this in the
                                            system settings.
                                        </span>
                                    ) : row.href ? (
                                        <a
                                            href={row.href}
                                            className="font-medium text-link hover:underline"
                                        >
                                            {row.value}
                                        </a>
                                    ) : (
                                        row.value
                                    )}
                                </dd>
                            </div>
                        </div>
                    ))}
                </dl>

                <Link
                    href={help.ticket()}
                    className="mt-8 inline-block rounded-md bg-[#3B72C4] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#31629F]"
                >
                    Submit a ticket instead
                </Link>
            </div>
        </>
    );
}

HelpContact.layout = {
    breadcrumbs: [
        { title: 'Help', href: help.index() },
        { title: 'Contact Support', href: help.contact() },
    ],
};
