import type { FormComponentRef } from '@inertiajs/core';
import { Form, Head, router } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { MailCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import LoginOtpController from '@/actions/App/Http/Controllers/Auth/LoginOtpController';
import { AuthSubmit } from '@/components/auth/auth-field';
import InputError from '@/components/input-error';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';

type Props = {
    /** Masked on the server: "o•••••••n@baliwag.gov.ph". */
    email: string;
    length: number;
    ttlMinutes: number;
    /** Seconds until "Send a new code" may be pressed. */
    resendIn: number;
    status?: string;
};

/**
 * The sign-in code step (client request, 2026-09-25): after the password,
 * before the dashboard. Nobody is signed in until this page's code is right.
 *
 * The digits are shown as typed, unlike the Security PIN: a one-time code is
 * useless the moment it has been used, and reading it back is how a person
 * catches a typo before spending one of their five tries.
 */
export default function LoginOtp({
    email,
    length,
    ttlMinutes,
    resendIn,
    status,
}: Props) {
    const [code, setCode] = useState('');
    const [wait, setWait] = useState(resendIn);
    const [resending, setResending] = useState(false);
    const form = useRef<FormComponentRef>(null);

    // A fresh countdown every time the server answers with a new one -- after
    // "Send a new code" the page comes back with resendIn reset.
    const [lastResendIn, setLastResendIn] = useState(resendIn);

    if (resendIn !== lastResendIn) {
        setLastResendIn(resendIn);
        setWait(resendIn);
    }

    useEffect(() => {
        if (wait <= 0) {
            return;
        }

        const timer = window.setTimeout(() => setWait(wait - 1), 1000);

        return () => window.clearTimeout(timer);
    }, [wait]);

    return (
        <>
            <Head title="Enter your sign-in code" />

            <div className="mb-5 flex flex-col items-center text-center">
                <span className="mb-3 flex size-12 items-center justify-center rounded-full bg-[#E8F0FB] text-[#3B72C4]">
                    <MailCheck aria-hidden="true" className="size-6" />
                </span>
                <p className="text-sm text-copy">
                    We emailed a {length}-digit code to{' '}
                    {/* inline-block: on a phone the address drops to its own
                        line whole instead of breaking mid-domain. */}
                    <span className="inline-block font-bold [overflow-wrap:anywhere] text-navy">
                        {email}
                    </span>
                    . Enter it below to finish signing in.
                </p>
            </div>

            {status && (
                <div
                    role="status"
                    className="mb-4 rounded-md bg-[#E8F5EC] px-4 py-3 text-center text-sm font-medium text-[#1F5136]"
                >
                    {status}
                </div>
            )}

            <Form
                {...LoginOtpController.verify.form()}
                ref={form}
                onError={() => {
                    setCode('');
                    // Back in the boxes for the next try -- they were disabled
                    // while the request ran, and a disabled field loses focus.
                    window.setTimeout(
                        () => document.getElementById('code')?.focus(),
                        0,
                    );
                }}
                className="space-y-5"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col items-center gap-3">
                            <label htmlFor="code" className="sr-only">
                                Sign-in code
                            </label>
                            <InputOTP
                                id="code"
                                name="code"
                                maxLength={length}
                                value={code}
                                onChange={setCode}
                                // The whole code is the whole answer: send it
                                // as soon as the last digit lands.
                                onComplete={() =>
                                    window.setTimeout(
                                        () => form.current?.submit(),
                                        0,
                                    )
                                }
                                disabled={processing}
                                pattern={REGEXP_ONLY_DIGITS}
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                autoFocus
                                aria-invalid={errors.code ? true : undefined}
                                aria-describedby="code-help"
                            >
                                <InputOTPGroup>
                                    {Array.from({ length }, (_, index) => (
                                        <InputOTPSlot
                                            key={index}
                                            index={index}
                                            className="size-11 text-lg font-bold text-navy sm:size-12"
                                        />
                                    ))}
                                </InputOTPGroup>
                            </InputOTP>
                            <InputError message={errors.code} />
                            <p
                                id="code-help"
                                className="text-center text-xs text-copy"
                            >
                                The code expires in {ttlMinutes}{' '}
                                {ttlMinutes === 1 ? 'minute' : 'minutes'}. Not
                                in your inbox? Check Spam.
                            </p>
                        </div>

                        <AuthSubmit
                            disabled={processing || code.length < length}
                        >
                            {processing ? 'Checking…' : 'Verify and sign in'}
                        </AuthSubmit>
                    </>
                )}
            </Form>

            <div className="mt-5 flex flex-col items-center gap-2 text-sm">
                <button
                    type="button"
                    disabled={wait > 0 || resending}
                    onClick={() =>
                        router.post(
                            LoginOtpController.resend.url(),
                            {},
                            {
                                preserveScroll: true,
                                onStart: () => setResending(true),
                                onFinish: () => setResending(false),
                            },
                        )
                    }
                    className="font-bold text-link underline-offset-4 hover:underline disabled:cursor-not-allowed disabled:text-copy disabled:no-underline"
                >
                    {wait > 0
                        ? `Send a new code in ${wait}s`
                        : resending
                          ? 'Sending…'
                          : 'Send a new code'}
                </button>

                <button
                    type="button"
                    onClick={() => router.post(LoginOtpController.cancel.url())}
                    className="text-copy underline-offset-4 hover:underline"
                >
                    Use a different account
                </button>
            </div>
        </>
    );
}

LoginOtp.layout = {
    title: 'Enter your sign-in code',
};
