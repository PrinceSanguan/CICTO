import { Form, Head } from '@inertiajs/react';
import {
    AuthSubmit,
    EmailField,
    FieldLabel,
    PasswordField,
    TextField,
} from '@/components/auth/auth-field';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

export default function Register({ passwordRules }: Props) {
    return (
        <>
            <Head title="Register" />

            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="space-y-5"
            >
                {({ processing, errors }) => (
                    <>
                        {/*
                            Kept, though the client's mockup shows only email and
                            password: `name` is NOT NULL on users, it is what the
                            audit trail and every Signature Certificate print,
                            and a register full of "user@example.com" instead of
                            a person's name is not a municipal record.
                        */}
                        <div className="space-y-1.5">
                            <FieldLabel htmlFor="name">Full name</FieldLabel>
                            <TextField
                                id="name"
                                required
                                autoFocus
                                autoComplete="name"
                                name="name"
                                placeholder="Juana dela Cruz"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="space-y-1.5">
                            <FieldLabel htmlFor="email">Email</FieldLabel>
                            <EmailField
                                id="email"
                                name="email"
                                required
                                autoComplete="email"
                                placeholder="Email"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="space-y-3">
                            <FieldLabel htmlFor="password">Password</FieldLabel>
                            <PasswordField
                                id="password"
                                name="password"
                                required
                                autoComplete="new-password"
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
                                required
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
                            data-test="register-user-button"
                        >
                            {processing && <Spinner />}
                            Register
                        </AuthSubmit>
                    </>
                )}
            </Form>

            <p className="mt-6 text-center text-sm font-medium text-navy">
                Already have an account?{' '}
                <TextLink href={login()} className="font-bold text-link">
                    Login
                </TextLink>
            </p>
        </>
    );
}

Register.layout = {
    title: 'Register',
};
