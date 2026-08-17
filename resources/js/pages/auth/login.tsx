import { Form, Head } from '@inertiajs/react';
import {
    AuthSubmit,
    EmailField,
    FieldLabel,
    PasswordField,
} from '@/components/auth/auth-field';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

/**
 * ONE login screen for all three roles.
 *
 * §3 originally asked for "separate login entry points for User, Admin and
 * Super Admin", which shipped as three URLs rendering this page with a
 * `portal` prop and a row of role chips at the foot of the card. The client
 * asked for the chips to go on 2026-08-17: the choice was never a choice --
 * every portal posted to the same /login against the same guard, and the role
 * is read from the database row after the credentials check either way.
 *
 * Nothing about role detection changed with them. RoleAwareLoginResponse still
 * sends an admin to /admin/dashboard, a super admin to /super-admin/dashboard
 * and everyone else to /dashboard, for all four ways of becoming
 * authenticated -- password, two-factor, passkey and registration.
 *
 * /login/admin and /login/super-admin now redirect here; see routes/web.php.
 */
export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Login to Your Account" />

            {status && (
                <div
                    role="status"
                    className="mb-4 rounded-md bg-[#E8F5EC] px-4 py-3 text-center text-sm font-medium text-[#1F5136]"
                >
                    {status}
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="space-y-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="space-y-1.5">
                            <FieldLabel htmlFor="email">Email</FieldLabel>
                            <EmailField
                                id="email"
                                name="email"
                                required
                                autoFocus
                                autoComplete="email"
                                placeholder="Email"
                                aria-invalid={errors.email ? true : undefined}
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                            />
                            <InputError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <div className="flex items-center justify-between">
                                <FieldLabel htmlFor="password">
                                    Password
                                </FieldLabel>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="text-sm font-medium text-link no-underline hover:underline"
                                    >
                                        Forgot Password?
                                    </TextLink>
                                )}
                            </div>
                            <PasswordField
                                id="password"
                                name="password"
                                required
                                autoComplete="current-password"
                                placeholder="Password"
                                aria-invalid={
                                    errors.password ? true : undefined
                                }
                                aria-describedby={
                                    errors.password
                                        ? 'password-error'
                                        : undefined
                                }
                            />
                            <InputError
                                id="password-error"
                                message={errors.password}
                            />
                        </div>

                        <div className="flex items-center gap-3">
                            <Checkbox
                                id="remember"
                                name="remember"
                                className="size-5"
                            />
                            <label
                                htmlFor="remember"
                                className="text-[15px] font-bold text-navy"
                            >
                                Remember me
                            </label>
                        </div>

                        {/* Narrower and centred, as the supplied form shows. */}
                        <div className="flex justify-center pt-1">
                            <AuthSubmit
                                disabled={processing}
                                data-test="login-button"
                                className="w-full max-w-[13rem]"
                            >
                                {processing && <Spinner />}
                                Login
                            </AuthSubmit>
                        </div>
                    </>
                )}
            </Form>

            <p className="mt-6 text-center text-sm font-medium text-navy">
                Don&rsquo;t have an account?{' '}
                <TextLink
                    href={register()}
                    className="font-bold text-link no-underline hover:underline"
                >
                    Register
                </TextLink>
            </p>
        </>
    );
}

/*
 * The heading now belongs to the layout, like every other auth screen.
 *
 * It used to be rendered by the page because the title varied with the portal
 * and a static layout object cannot vary per request. With one entry point it
 * does not vary, so the special case is gone.
 *
 * It must never be set to `{}`. Inertia reads an empty object as "named
 * layouts, none of them" -- Object.values({}).every(...) is vacuously true --
 * so the layout list comes back empty and the entire auth shell silently
 * disappears, leaving the bare form on the page background. There is a test:
 * tests/Feature/InertiaLayoutContractTest.php.
 */
Login.layout = {
    title: 'Login to Your Account',
};
