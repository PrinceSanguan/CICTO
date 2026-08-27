import { Head, Link } from '@inertiajs/react';
import { BookOpen, ChevronRight, Headset, Ticket } from 'lucide-react';
import help from '@/routes/help';

type Props = {
    support: {
        office: string | null;
        email: string | null;
        phone: string | null;
        mail_configured: boolean;
    };
};

/** §23 Help & Support hub. */
export default function HelpIndex({ support }: Props) {
    const cards = [
        {
            icon: BookOpen,
            title: 'Knowledge Base',
            body: 'Browse helpful articles and FAQs in our knowledge base.',
            action: 'View Articles',
            href: help.knowledgeBase(),
        },
        {
            icon: Ticket,
            title: 'Submit a Ticket',
            body: 'Submit a support ticket and our team will get back to you shortly.',
            action: 'Open Ticket',
            href: help.ticket(),
        },
        {
            icon: Headset,
            title: 'Contact Support',
            body: 'Reach out to our support team directly for assistance.',
            action: 'Contact Us',
            href: help.contact(),
        },
    ];

    return (
        <>
            <Head title="Help" />

            <h1 className="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                Need Help?
            </h1>
            <p className="mt-1 max-w-md text-[15px] font-medium text-white/90">
                We&rsquo;re here to assist you with your document tracking
                concerns
            </p>

            {/* Navy, like the other two primary actions that sit straight on
                the hero gradient (documents/index.tsx, dashboard.tsx). A brand
                blue here has almost no lightness between it and the ground, so
                it reads as a shaded rectangle rather than a button. The card
                buttons below keep #3B72C4 -- their ground is white. */}
            <Link
                href={help.contact()}
                className="mt-6 inline-flex items-center gap-3 rounded-lg bg-navy px-7 py-4 text-lg font-bold text-white shadow-lg transition duration-200 ease-out hover:bg-[#232c73] active:scale-[0.98]"
            >
                Contact Support
                <ChevronRight className="size-5" />
            </Link>

            <div className="mt-10 grid gap-6 md:grid-cols-3">
                {cards.map((card) => (
                    <section
                        key={card.title}
                        className="flex flex-col items-center rounded-xl bg-white p-8 text-center shadow-xl"
                    >
                        <card.icon
                            aria-hidden="true"
                            className="size-14 text-navy"
                            strokeWidth={1.25}
                        />

                        <h2 className="mt-4 text-xl font-bold text-navy">
                            {card.title}
                        </h2>

                        <p className="mt-3 flex-1 text-[15px] text-copy">
                            {card.body}
                        </p>

                        <Link
                            href={card.href}
                            className="mt-6 w-full max-w-[13rem] rounded-md bg-[#3B72C4] py-3 text-sm font-bold text-white transition hover:bg-[#31629F]"
                        >
                            {card.action}
                        </Link>
                    </section>
                ))}
            </div>

            {/*
                Stated rather than hidden. §23 names a ticket page, but this
                deployment has no outgoing mail configured (client question B3),
                so a form that looks like it sends would be worse than none.
            */}
            {!support.mail_configured && (
                <p className="mt-6 rounded-xl bg-white p-4 text-sm text-copy shadow-xl">
                    <strong className="font-bold text-navy">
                        Note for administrators:
                    </strong>{' '}
                    outgoing mail is not configured on this server, so support
                    tickets are recorded in the system log rather than emailed.
                    Until SMTP details are supplied, please use the contact
                    details on the Contact Support page.
                </p>
            )}
        </>
    );
}

HelpIndex.layout = {
    breadcrumbs: [{ title: 'Help', href: help.index() }],
};
