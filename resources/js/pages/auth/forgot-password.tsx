import { Form, Head } from '@inertiajs/react';
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

export default function ForgotPassword({ status }: { status?: string }) {
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

            <p className="mt-6 text-center text-sm font-medium text-navy">
                Or, return to{' '}
                <TextLink href={login()} className="font-bold text-link">
                    Login
                </TextLink>
            </p>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Forgot Password',
    description: 'Enter your email and we will send you a reset link.',
};
