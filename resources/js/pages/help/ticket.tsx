import { Form, Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, Clock, FileText, UploadCloud } from 'lucide-react';
import { useState } from 'react';
import { HelpScene } from '@/components/help/help-scene';
import InputError from '@/components/input-error';
import help from '@/routes/help';

type Props = {
    support: {
        office: string | null;
        email: string | null;
        phone: string | null;
        response_window: string;
        mail_configured: boolean;
    };
    issueTypes: string[];
};

/**
 * §23's Submit a Support Ticket screen.
 *
 * Two columns, as the design has them: the form on the left, and on the right
 * the screenshot dropzone, the submit button and the response-time note.
 *
 * The name and email fields are pre-filled from the session and are contact
 * details only -- the server attributes every ticket to the signed-in account
 * regardless of what is typed here, so the form cannot be used to file as
 * somebody else.
 */
export default function SubmitTicket({ support, issueTypes }: Props) {
    const { auth } = usePage().props;

    // Only the NAME, for the dropzone to echo back. The file itself stays in
    // the input, where the form serialiser can reach it.
    const [screenshot, setScreenshot] = useState<string | null>(null);

    return (
        <>
            <Head title="Submit a Ticket" />

            <HelpScene
                back={{ href: help.index(), label: 'Back' }}
                title="Submit a Support Ticket"
                subtitle="Fill out the form below and we'll get back to you shortly."
            >
                {/*
                    Shown before anything is typed, not after submitting.
                    Somebody about to describe a problem at length deserves to
                    know up front that it will be logged rather than emailed.
                */}
                {!support.mail_configured && (
                    <div
                        role="status"
                        className="mb-6 flex gap-3 rounded-xl bg-[#FDF3DC] px-5 py-4 text-sm text-[#7A5B12] shadow-sm"
                    >
                        <AlertTriangle
                            aria-hidden="true"
                            className="mt-0.5 size-5 shrink-0"
                        />
                        <p className="leading-relaxed">
                            <strong className="font-bold">
                                Outgoing mail is not configured on this server.
                            </strong>{' '}
                            A ticket submitted here is recorded in the system
                            log, but it will <strong>not</strong> reach anyone
                            by email. For anything urgent, use the details on
                            the{' '}
                            <Link
                                href={help.contact()}
                                className="font-bold text-link underline"
                            >
                                Contact Support
                            </Link>{' '}
                            page.
                        </p>
                    </div>
                )}

                {/*
                    `encType` because the screenshot field makes this a
                    multipart submission. Inertia's Form serialises the real DOM
                    form, so the file rides along once the form is declared
                    multipart -- without it the server sees every other field
                    arrive correctly and the attachment silently vanishes, which
                    is the exact failure the old honest copy warned about.
                */}
                <Form
                    {...help.ticket.store.form()}
                    encType="multipart/form-data"
                    options={{ preserveScroll: true }}
                    resetOnSuccess={['body', 'tracking_number']}
                    onSuccess={() => setScreenshot(null)}
                >
                    {({ processing, errors }) => (
                        <div className="grid gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)] lg:items-start">
                            <div className="rounded-2xl bg-white px-6 py-7 shadow-xl">
                                <Field
                                    label="Full name"
                                    name="name"
                                    defaultValue={auth?.user?.name ?? ''}
                                    placeholder="Enter your full name"
                                    error={errors.name}
                                    autoComplete="name"
                                />

                                <Field
                                    label="Email Address"
                                    name="email"
                                    type="email"
                                    defaultValue={auth?.user?.email ?? ''}
                                    placeholder="Enter your email address"
                                    error={errors.email}
                                    autoComplete="email"
                                />

                                <Field
                                    label="Document Tracking Number"
                                    optional
                                    name="tracking_number"
                                    placeholder="Enter tracking number"
                                    error={errors.tracking_number}
                                />

                                <div className="mt-5">
                                    <Label htmlFor="issue_type">
                                        Issue Type
                                    </Label>
                                    <select
                                        id="issue_type"
                                        name="issue_type"
                                        defaultValue=""
                                        aria-invalid={
                                            errors.issue_type ? true : undefined
                                        }
                                        className="mt-2 h-11 w-full rounded-lg border border-[#E3E8EF] bg-white px-3 text-[15px] text-navy focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                                    >
                                        <option value="" disabled>
                                            Select an issue
                                        </option>
                                        {issueTypes.map((type) => (
                                            <option key={type} value={type}>
                                                {type}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.issue_type} />
                                </div>

                                <div className="mt-5">
                                    <Label htmlFor="body">
                                        Message / Description
                                    </Label>
                                    <textarea
                                        id="body"
                                        name="body"
                                        rows={7}
                                        maxLength={5000}
                                        placeholder="Please describe your concern..."
                                        aria-invalid={
                                            errors.body ? true : undefined
                                        }
                                        className="mt-2 w-full rounded-lg border border-[#E3E8EF] bg-white px-3 py-2.5 text-[15px] text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                                    />
                                    <InputError message={errors.body} />
                                </div>
                            </div>

                            <div className="rounded-2xl bg-white px-6 py-7 shadow-xl">
                                {/*
                                    Present because the design shows it, and
                                    disabled because nothing on the server
                                    accepts it yet. An input that silently drops
                                    the file a reporter attached is worse than
                                    one that says so.
                                */}
                                <label
                                    htmlFor="screenshot"
                                    className="block cursor-pointer rounded-lg border-2 border-dashed border-[#BBD3F0] bg-[#F7FAFF] px-5 py-6 text-center transition focus-within:border-[#3B72C4] focus-within:ring-2 focus-within:ring-brand hover:border-[#3B72C4] hover:bg-[#EEF4FD]"
                                >
                                    <UploadCloud
                                        aria-hidden="true"
                                        className="mx-auto size-8 text-[#3B72C4]"
                                    />
                                    <p className="mt-2 text-[15px] font-bold text-navy">
                                        Upload Screenshot{' '}
                                        <span className="font-semibold text-copy">
                                            (Optional)
                                        </span>
                                    </p>
                                    <p className="mt-1 text-xs text-copy">
                                        {screenshot ??
                                            'Click to upload or drag and drop'}
                                    </p>

                                    {/*
                                        A real input, not a decorated div. The
                                        zone said "uploads are not enabled on
                                        this server" until 2026-08-26, when the
                                        client asked for the comp's "Click to
                                        upload or drag and drop" -- and that
                                        copy is only honest if the file is
                                        actually kept, so the field, its
                                        validation, its storage and its mail
                                        attachment all went in with it. A
                                        dropzone that swallows a reporter's
                                        screenshot is worse than one that
                                        admits it cannot take one.

                                        `sr-only` rather than `hidden`: a
                                        hidden input is unreachable by keyboard
                                        and invisible to a screen reader, so the
                                        label above would announce a control
                                        nobody could operate. This way the whole
                                        zone is the click target AND the input
                                        still takes focus, which is what
                                        `focus-within` above draws.
                                    */}
                                    <input
                                        id="screenshot"
                                        name="screenshot"
                                        type="file"
                                        accept="image/*"
                                        className="sr-only"
                                        onChange={(event) =>
                                            setScreenshot(
                                                event.target.files?.[0]?.name ??
                                                    null,
                                            )
                                        }
                                    />
                                </label>

                                <InputError
                                    className="mt-2"
                                    message={errors.screenshot}
                                />

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="mt-6 flex w-full items-center justify-center gap-2 rounded-lg bg-[#3B72C4] px-6 py-3.5 text-[15px] font-bold text-white shadow-lg transition hover:bg-[#31629F] disabled:opacity-60"
                                >
                                    <FileText
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                    {processing
                                        ? 'Submitting…'
                                        : 'Submit Ticket'}
                                </button>

                                <p className="mt-4 flex items-center justify-center gap-2 text-xs text-copy">
                                    <Clock
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    <span>
                                        We usually respond within{' '}
                                        <strong className="font-bold text-navy">
                                            {support.response_window}
                                        </strong>
                                        .
                                    </span>
                                </p>
                            </div>
                        </div>
                    )}
                </Form>
            </HelpScene>
        </>
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
        <label
            htmlFor={htmlFor}
            className="block text-[15px] font-bold text-navy"
        >
            {children}
        </label>
    );
}

function Field({
    label,
    name,
    error,
    optional = false,
    type = 'text',
    ...props
}: {
    label: string;
    name: string;
    error?: string;
    optional?: boolean;
    type?: string;
} & React.InputHTMLAttributes<HTMLInputElement>) {
    return (
        <div className="mt-5 first:mt-0">
            <Label htmlFor={name}>
                {label}
                {optional && (
                    <span className="ml-1 font-semibold text-copy">
                        (Optional)
                    </span>
                )}
            </Label>
            <input
                id={name}
                name={name}
                type={type}
                aria-invalid={error ? true : undefined}
                className="mt-2 h-11 w-full rounded-lg border border-[#E3E8EF] bg-white px-3 text-[15px] text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                {...props}
            />
            <InputError message={error} />
        </div>
    );
}

SubmitTicket.layout = {
    breadcrumbs: [
        { title: 'Help', href: help.index() },
        { title: 'Submit a Ticket', href: help.ticket() },
    ],
};
