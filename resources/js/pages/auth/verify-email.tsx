import { Form, Head, usePage } from '@inertiajs/react';
import { AuthSubmit } from '@/components/auth/auth-field';
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
                {({ processing }) => (
                    <>
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
