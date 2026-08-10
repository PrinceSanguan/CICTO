import { Form, Head } from '@inertiajs/react';
import {
    AuthSubmit,
    EmailField,
    FieldLabel,
    PasswordField,
} from '@/components/auth/auth-field';
import InputError from '@/components/input-error';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({ token, email, passwordRules }: Props) {
    return (
        <>
            <Head title="Reset password" />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                className="space-y-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="space-y-1.5">
                            <FieldLabel htmlFor="email">Email</FieldLabel>
                            {/*
                                Read-only, not editable: the reset token is
                                issued against this exact address. Letting it be
                                changed would only produce a token mismatch and
                                an error the user cannot act on.
                            */}
                            <EmailField
                                id="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                readOnly
                                placeholder="Enter your email address"
                                className="bg-[#F6F8FB]"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="space-y-3">
                            <FieldLabel htmlFor="password">Password</FieldLabel>
                            <PasswordField
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                autoFocus
                                placeholder="Password"
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />

                            <FieldLabel
                                htmlFor="password_confirmation"
                                className="sr-only"
                            >
                                Retype password
                            </FieldLabel>
                            <PasswordField
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                placeholder="Retype-Password"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <AuthSubmit
                            disabled={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            Reset Password
                        </AuthSubmit>
                    </>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'Reset Password',
};
