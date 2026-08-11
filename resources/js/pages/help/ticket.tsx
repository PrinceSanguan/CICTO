import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle, ChevronLeft } from 'lucide-react';
import { useState } from 'react';
import HelpController from '@/actions/App/Http/Controllers/HelpController';
import InputError from '@/components/input-error';
import { Spinner } from '@/components/ui/spinner';
import help from '@/routes/help';

type Props = {
    support: {
        office: string | null;
        email: string | null;
        phone: string | null;
        mail_configured: boolean;
    };
};

const FIELD =
    'w-full rounded-md border border-[#DCE4EE] bg-white px-4 text-[15px] text-navy ' +
    'placeholder:text-[#9AA5B4] focus-visible:border-brand focus-visible:ring-2 ' +
    'focus-visible:ring-brand/25 focus-visible:outline-none';

/** §23 Submit a Support Ticket. */
export default function HelpTicket({ support }: Props) {
    const [body, setBody] = useState('');

    return (
        <>
            <Head title="Submit a Ticket" />

            <Link
                href={help.index()}
                className="inline-flex items-center gap-1 text-sm font-bold text-white/90 transition hover:text-white"
            >
                <ChevronLeft className="size-4" />
                Back to Help
            </Link>

            <h1 className="mt-4 text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                Submit a Ticket
            </h1>
            <p className="mt-1 text-[15px] font-medium text-white/90">
                Describe the problem and our team will get back to you.
            </p>

            {/*
                Shown BEFORE the form, not after submitting. If this server
                cannot send mail, the user needs to know that while deciding
                whether to type out their problem -- not once they have.
            */}
            {!support.mail_configured && (
                <div className="mt-6 flex max-w-2xl gap-3 rounded-xl bg-[#FFF6E5] p-4 shadow-xl">
                    <AlertTriangle
                        aria-hidden="true"
                        className="mt-0.5 size-5 shrink-0 text-[#B37A00]"
                    />
                    <p className="text-sm text-[#5A4300]">
                        <strong className="font-bold">
                            Outgoing mail is not configured on this server.
                        </strong>{' '}
                        A ticket submitted here is recorded in the system log,
                        but it will <strong>not</strong> reach anyone by email.
                        For anything urgent, use the details on the{' '}
                        <Link
                            href={help.contact()}
                            className="font-bold text-link hover:underline"
                        >
                            Contact Support
                        </Link>{' '}
                        page.
                    </p>
                </div>
            )}

            <Form
                {...HelpController.submitTicket.form()}
                resetOnSuccess={['subject', 'body']}
                onSuccess={() => setBody('')}
                className="mt-6 max-w-2xl rounded-xl bg-white p-6 shadow-xl sm:p-8"
            >
                {({ processing, errors }) => (
                    <>
                        <div>
                            <label
                                htmlFor="subject"
                                className="block text-sm font-bold text-navy"
                            >
                                Subject
                                <span
                                    aria-hidden="true"
                                    className="text-danger"
                                >
                                    *
                                </span>
                                <span className="sr-only"> (required)</span>
                            </label>
                            <input
                                id="subject"
                                name="subject"
                                required
                                autoFocus
                                maxLength={150}
                                placeholder="Short summary of the problem"
                                className={`${FIELD} mt-1.5 h-12`}
                                aria-invalid={errors.subject ? true : undefined}
                            />
                            <InputError
                                message={errors.subject}
                                className="mt-1"
                            />
                        </div>

                        <div className="mt-5">
                            <label
                                htmlFor="body"
                                className="block text-sm font-bold text-navy"
                            >
                                Details
                                <span
                                    aria-hidden="true"
                                    className="text-danger"
                                >
                                    *
                                </span>
                                <span className="sr-only"> (required)</span>
                            </label>

                            <div className="relative mt-1.5">
                                <textarea
                                    id="body"
                                    name="body"
                                    required
                                    rows={7}
                                    maxLength={5000}
                                    value={body}
                                    onChange={(event) =>
                                        setBody(event.target.value)
                                    }
                                    placeholder="What were you doing, what did you expect, and what happened instead? A control number helps."
                                    className={`${FIELD} resize-y py-3 pb-7`}
                                    aria-invalid={
                                        errors.body ? true : undefined
                                    }
                                />
                                <span
                                    aria-hidden="true"
                                    className="pointer-events-none absolute right-3 bottom-2.5 text-xs text-[#9AA5B4]"
                                >
                                    {body.length}/5000
                                </span>
                            </div>

                            <InputError
                                message={errors.body}
                                className="mt-1"
                            />
                        </div>

                        {/*
                            No name or email field: both come from the signed-in
                            account. Letting somebody type another person's
                            address would be a way to send mail as them, and
                            this posts to a municipal inbox.
                        */}
                        <p className="mt-4 text-xs text-copy">
                            Your name, email address and office are attached
                            automatically so support can reply.
                        </p>

                        <div className="mt-6 flex flex-wrap justify-end gap-3">
                            <Link
                                href={help.index()}
                                className="rounded-md border border-[#DCE4EE] px-6 py-3 text-sm font-bold text-navy transition hover:bg-[#F4F7FC]"
                            >
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                disabled={processing}
                                className="flex items-center gap-2 rounded-md bg-[#3B72C4] px-8 py-3 text-sm font-bold text-white transition hover:bg-[#31629F] disabled:cursor-not-allowed disabled:opacity-70"
                            >
                                {processing && <Spinner />}
                                Submit ticket
                            </button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

HelpTicket.layout = {
    breadcrumbs: [
        { title: 'Help', href: help.index() },
        { title: 'Submit a Ticket', href: help.ticket() },
    ],
};
