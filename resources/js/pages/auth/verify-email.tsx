import { Form, Head, usePage } from '@inertiajs/react';
import { AuthSubmit } from '@/components/auth/auth-field';
import InputError from '@/components/input-error';
import { Spinner } from '@/components/ui/spinner';
import { send } from '@/routes/verification';

/**
 * The client's comp for this screen ends on "You can resend the email after a
 * few seconds" -- there is no Log out link under it, and the markup they sent
 * on 2026-08-26 struck the one that was there ("paalis ako nito").
 *
 * Worth knowing what that costs: this screen is shown to a visitor who IS
 * signed in but unverified, and AuthSimpleLayout has no nav, so the link was
 * the only way off the page short of closing the tab. Signing in as somebody
 * else now means clearing the session by hand. `logout()` is still routed and
 * still reachable everywhere else; only this one entry point is gone.
 */
export default function VerifyEmail({ status }: { status?: string }) {
    // The address is already known -- naming it is the difference between
    // "check your email" and "check THIS one", which is what a user with two
    // accounts actually needs to know.
    const address = (usePage().props.auth?.user?.email ?? '') as string;

    /*
      `status` is `session()->get('status')` straight from Fortify, and the
      session can hold an EMPTY string for it -- which is what the client
      screenshotted on 2026-08-26: a bare amber bar with nothing in it, above
      "We sent a link to...". The old guard was `status !== undefined`, so ''
      passed it and painted a banner with no message.

      Trimming and testing for content is the whole fix. The two branches below
      are unchanged otherwise: both carry text a user needs when it exists, and
      deleting them to clear the empty bar would take the real messages with it.
    */
    const notice = (status ?? '').trim();

    return (
        <>
            <Head title="Email verification" />

            {notice === 'verification-link-sent' && (
                <div
                    role="status"
                    className="mb-4 rounded-md bg-[#E8F5EC] px-4 py-3 text-center text-sm font-medium text-[#1F5136]"
                >
                    A new verification link has been sent to your email address.
                </div>
            )}

            {/*
              Any OTHER status, in amber.

              This branch used to not exist, and the strict equality above was
              the whole of it -- so every message that was not Fortify's own
              literal was flashed into the session and rendered nowhere. Two of
              them can reach this page: RequireOutgoingMail's "this server
              cannot send email yet" when the resend is refused, and the
              "your account was created but the verification email could not be
              sent" that follows a failed registration. Both are the only text
              on the screen that changes, and both were invisible.

              Amber rather than green: neither of them is a confirmation.
            */}
            {notice !== '' && notice !== 'verification-link-sent' && (
                <div
                    role="status"
                    className="mb-4 rounded-md bg-[#FDF3DC] px-4 py-3 text-center text-sm font-semibold text-[#7A5B12]"
                >
                    {notice}
                </div>
            )}

            <div className="space-y-6 text-center">
                <p className="text-sm font-bold text-navy">
                    We sent a link to{' '}
                    {address !== '' && (
                        <span className="break-all">{address}</span>
                    )}
                </p>
                <p className="text-sm font-bold text-navy">
                    Click the link to complete the verification process
                </p>
            </div>

            <Form {...send.form()} className="mt-20">
                {({ processing, errors }) => (
                    <>
                        {/*
                          The resend can fail outright now that the transport is
                          real -- bootstrap/app.php turns a Gmail outage into an
                          `email` error rather than a 500. Nothing on this page
                          read `errors` before, so that message had nowhere to
                          land either.
                        */}
                        <InputError className="mb-4" message={errors.email} />

                        {/*
                            Narrowed and centred rather than filling the card.
                            AuthSubmit is `w-full` by design -- on a login form
                            the button should span the fields above it -- but
                            this screen has no fields, so at the card's new
                            600px it read as a banner. The comp draws it at
                            about 350px. It goes through `className` because
                            AuthSubmit merges via cn() for exactly this.
                        */}
                        <AuthSubmit
                            disabled={processing}
                            className="mx-auto max-w-[350px]"
                        >
                            {processing && <Spinner />}
                            Verify Email
                        </AuthSubmit>

                        <p className="mt-12 text-center text-sm font-bold text-navy">
                            You can resend the email after a few seconds
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verify your email address',
};
