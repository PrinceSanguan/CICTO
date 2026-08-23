import { Form, Head, usePage } from '@inertiajs/react';
import { AuthSubmit } from '@/components/auth/auth-field';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    // The address is already known -- naming it is the difference between
    // "check your email" and "check THIS one", which is what a user with two
    // accounts actually needs to know.
    const address = (usePage().props.auth?.user?.email ?? '') as string;

    return (
        <>
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
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
            {status !== undefined && status !== 'verification-link-sent' && (
                <div
                    role="status"
                    className="mb-4 rounded-md bg-[#FDF3DC] px-4 py-3 text-center text-sm font-semibold text-[#7A5B12]"
                >
                    {status}
                </div>
            )}

            <div className="space-y-2 text-center">
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

            <Form {...send.form()} className="mt-8">
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

                        <AuthSubmit disabled={processing}>
                            {processing && <Spinner />}
                            Verify Email
                        </AuthSubmit>

                        <p className="mt-6 text-center text-sm font-bold text-navy">
                            You can resend the email after a few seconds
                        </p>
                    </>
                )}
            </Form>

            <p className="mt-6 text-center text-sm">
                <TextLink href={logout()} className="font-bold text-link">
                    Log out
                </TextLink>
            </p>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verify your email address',
};
