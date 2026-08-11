import { Head, Link } from '@inertiajs/react';
import { CictoLockup } from '@/components/auth/cicto-lockup';
import { dashboard, login } from '@/routes';

type Props = {
    status: number;
    /** True when the visitor has a session, so we know where "back" is. */
    authenticated: boolean;
    /**
     * Which kind of refusal a 403 was. 'role' means the URL needs a role this
     * account does not hold; null means record-level scoping.
     */
    reason?: 'role' | null;
};

/**
 * The one page every unhandled HTTP status lands on.
 *
 * Laravel's own 403 and 404 pages are unstyled, carry no branding and -- the
 * part that actually matters -- offer no link back into the app, so a clerk who
 * opens a document belonging to another office reaches a dead end and has to
 * know to press the browser's Back button. These statuses are ordinary
 * outcomes of office scoping, not crashes, and should read that way.
 */
const ROLE_DENIED = {
    title: 'This area needs a different role',
    body: 'Your account does not have access to this part of the system. Admin and Super Admin panels are limited to the staff who administer them. If you need access, ask a system administrator to change your role.',
};

const MESSAGES: Record<number, { title: string; body: string }> = {
    403: {
        title: 'You cannot open this document',
        body: 'It belongs to another office. Documents are only visible to the office holding them and to system administrators. If you believe you should have access, ask your department head to forward it to your office.',
    },
    404: {
        title: 'That page does not exist',
        body: 'The link may be mistyped, or the document may have been archived. Check the control number and try searching for it from Track Documents.',
    },
    419: {
        title: 'Your session expired',
        body: 'You were signed out after a period of inactivity, which protects the register on shared office computers. Sign in again and your work will pick up where it left off.',
    },
    429: {
        title: 'Too many requests',
        body: 'Slow down for a moment and try again. This limit exists to keep the register responsive for everyone in the building.',
    },
    500: {
        title: 'Something went wrong at our end',
        body: 'The error has been recorded. Nothing you submitted was lost — if you were acting on a document, open it again and check its current status before retrying.',
    },
    503: {
        title: 'CICTO is briefly unavailable',
        body: 'The system is being updated. This usually takes a few minutes; please try again shortly.',
    },
};

export default function ErrorPage({ status, authenticated, reason }: Props) {
    /*
     * A 403 has two quite different causes and they need different sentences.
     * Telling an Admin who typed a Super Admin URL that "it belongs to another
     * office" and to ask their department head to forward it is advice about a
     * document they never opened.
     */
    const { title, body } =
        status === 403 && reason === 'role'
            ? ROLE_DENIED
            : (MESSAGES[status] ?? {
                  title: 'Something went wrong',
                  body: 'The page could not be displayed. Please try again.',
              });

    // 419 always sends you to sign in -- the session is what expired, so the
    // dashboard would only bounce you here again.
    const backToApp = authenticated && status !== 419;

    return (
        <>
            <Head title={`${status} — ${title}`} />

            <main className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-[#E8F0FB] to-white px-6 py-16">
                <div className="w-full max-w-lg rounded-2xl bg-white px-6 py-10 text-center shadow-xl sm:px-10">
                    <CictoLockup className="mx-auto h-16 w-auto" />

                    <p className="mt-6 font-mono text-sm font-semibold text-copy">
                        Error {status}
                    </p>

                    <h1 className="mt-2 text-2xl font-extrabold tracking-tight text-navy sm:text-3xl">
                        {title}
                    </h1>

                    <p className="mx-auto mt-4 max-w-prose text-[15px] leading-relaxed text-copy">
                        {body}
                    </p>

                    <Link
                        href={backToApp ? dashboard() : login()}
                        className="mt-8 inline-block rounded-lg bg-[#3B72C4] px-8 py-3 text-sm font-bold text-white no-underline shadow-lg transition hover:bg-[#31629F]"
                    >
                        {backToApp ? 'Back to Home' : 'Go to sign in'}
                    </Link>
                </div>
            </main>
        </>
    );
}
