import { Form, Head } from '@inertiajs/react';
import {
    AuthSubmit,
    EmailField,
    FieldLabel,
    PasswordField,
} from '@/components/auth/auth-field';
import { PortalChips } from '@/components/auth/portal-chips';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Portal = 'user' | 'admin' | 'super-admin';

type Props = {
    status?: string;
    canResetPassword: boolean;
    portal?: Portal;
};

/**
 * Spec §3 asks for "separate login entry points for User, Admin, and Super
 * Admin". They are three URLs rendering this one page against one guard and one
 * POST target -- only the heading and the colour change.
 *
 * The portal is PRESENTATION ONLY. It is never posted, never reaches
 * Auth::attempt, and never influences authorization: the role is read from the
 * database row after the credentials check. Signing in at /login/admin with a
 * clerk account simply lands you on the clerk dashboard, which is deliberate --
 * rejecting the mismatch would leak which accounts are admins.
 */
const PORTALS: Record<Portal, { title: string }> = {
    user: { title: 'Login to Your Account' },
    admin: { title: 'Admin Login' },
    'super-admin': { title: 'Super Admin Login' },
};

export default function Login({
    status,
    canResetPassword,
    portal = 'user',
}: Props) {
    const copy = PORTALS[portal] ?? PORTALS.user;
    const danger = portal === 'super-admin';
    const isRolePortal = portal !== 'user';

    return (
        <>
            <Head title={copy.title} />

            <h1 className="mb-6 text-center text-2xl font-bold text-navy">
                {copy.title}
            </h1>

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
                                danger={danger}
                                disabled={processing}
                                data-test="login-button"
                                className="w-full max-w-[13rem]"
                            >
                                {processing && <Spinner />}
                                Login
                            </AuthSubmit>
                        </div>
                        {/*
                            Repeated under the button on the two role portals,
                            which is where their designs put it. The plain login
                            screen keeps only the one beside the Password label
                            and offers the portal chips here instead.
                        */}
                        {isRolePortal && canResetPassword && (
                            <p className="text-center">
                                <TextLink
                                    href={request()}
                                    className="text-sm font-bold text-link no-underline hover:underline"
                                >
                                    Forgot Password?
                                </TextLink>
                            </p>
                        )}
                    </>
                )}
            </Form>

            {/*
                §3's three entry points, offered from the plain login screen
                only. Reaching /login/admin FROM /login/admin is not a choice
                worth showing, and the role designs do not show it.
            */}
            {!isRolePortal && (
                <div className="mt-6">
                    <PortalChips current={portal} />
                </div>
            )}

            {!isRolePortal && (
                <p className="mt-6 text-center text-sm font-medium text-navy">
                    Don&rsquo;t have an account?{' '}
                    <TextLink
                        href={register()}
                        className="font-bold text-link no-underline hover:underline"
                    >
                        Register
                    </TextLink>
                </p>
            )}
        </>
    );
}

/*
 * Deliberately NO `Login.layout` property.
 *
 * The heading is rendered by the page, because it depends on which of §3's
 * three portals was opened and a static layout object cannot vary per request.
 *
 * It must be OMITTED rather than set to `{}`. Inertia reads an empty object as
 * "named layouts, none of them" -- Object.values({}).every(...) is vacuously
 * true -- so the layout list comes back empty and the entire auth shell
 * silently disappears, leaving the bare form on the page background.
 */
