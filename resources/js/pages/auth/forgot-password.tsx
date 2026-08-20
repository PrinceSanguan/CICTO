import { Form, Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import {
    AuthSubmit,
    EmailField,
    FieldLabel,
} from '@/components/auth/auth-field';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

type Props = {
    status?: string;
    /**
     * Whether this server has a mail transport that reaches people.
     *
     * Client question B3: CICTO does not supply SMTP credentials, so on the
     * shipping configuration the answer is no and this page cannot do what it
     * is named after. It says so rather than offering a form whose success
     * message would be untrue.
     */
    mailConfigured: boolean;
};

export default function ForgotPassword({ status, mailConfigured }: Props) {
    return (
        <>
            <Head title="Forgot password" />

            {status && (
                <div
                    role="status"
                    className="mb-4 rounded-md bg-[#E8F5EC] px-4 py-3 text-center text-sm font-medium text-[#1F5136]"
                >
                    {status}
                </div>
            )}

            {mailConfigured ? (
                <Form {...email.form()} className="space-y-5">
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-1.5">
                                <FieldLabel htmlFor="email">Email</FieldLabel>
                                <EmailField
                                    id="email"
                                    name="email"
                                    autoComplete="email"
                                    autoFocus
                                    placeholder="Enter your email address"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <AuthSubmit
                                disabled={processing}
                                data-test="email-password-reset-link-button"
                            >
                                {processing && <Spinner />}
                                Email password reset link
                            </AuthSubmit>
                        </>
                    )}
                </Form>
            ) : (
                /*
                    No form at all, rather than a disabled one.

                    A greyed-out button invites somebody to keep trying it. What
                    a person on this page needs is the one instruction that
                    actually works on this deployment, and it is not on this
                    screen -- it is a phone call to their administrator, who can
                    set a new password from Manage Users.
                */
                <div
                    role="status"
                    className="flex gap-3 rounded-lg bg-[#FDF3DC] px-5 py-4 text-[15px] leading-relaxed font-semibold text-[#7A5B12]"
                >
                    <AlertTriangle
                        aria-hidden="true"
                        className="mt-0.5 size-5 shrink-0"
                    />
                    <span>
                        This server cannot send email yet, so no reset link can
                        be sent to you.{' '}
                        <span className="font-bold">
                            Ask your administrator to set a new password for
                            you.
                        </span>{' '}
                        They can do it from the Manage Users screen, and you can
                        change it yourself under Settings once you are signed
                        in.
                    </span>
                </div>
            )}

            <p className="mt-6 text-center text-sm font-medium text-navy">
                Or, return to{' '}
                <TextLink href={login()} className="font-bold text-link">
                    Login
                </TextLink>
            </p>
        </>
    );
}

/*
    The strapline is static -- `.layout` is read once, not per render -- so it
    has to be true whether or not this server can send mail. It used to promise
    a link that, on the shipping configuration, was never coming.
*/
ForgotPassword.layout = {
    title: 'Forgot Password',
    description: 'How to get back into your account.',
};
