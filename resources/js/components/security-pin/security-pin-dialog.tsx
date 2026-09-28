import type { FormComponentRef } from '@inertiajs/core';
import { Form } from '@inertiajs/react';
import { KeyRound, LockKeyhole, ShieldCheck } from 'lucide-react';
import { useRef, useState } from 'react';
import SecurityPinController from '@/actions/App/Http/Controllers/SecurityPinController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import {
    minutesLabel,
    PIN_LENGTH,
    PinInput,
} from '@/components/security-pin/pin-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export type SecurityPinMode = 'create' | 'verify' | 'forgot';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Whether this person already has a PIN: decides create vs enter. */
    hasPin: boolean;
    attemptsLeft: number;
    maxAttempts: number;
    idleMinutes: number;
};

/**
 * Put the caret back where the person has to type next after a refused
 * attempt. Deferred a tick: the boxes are disabled while the request runs, and
 * a disabled field cannot take focus until the response has re-enabled it.
 */
function focusField(id: string) {
    window.setTimeout(() => document.getElementById(id)?.focus(), 0);
}

/**
 * The Security PIN pop-up (client request, 2026-09-25).
 *
 * Three faces of one dialog:
 *  - create: the first time somebody without a PIN opens a document;
 *  - verify: every other time, and again after the idle timeout;
 *  - forgot: a new PIN, proved with the account password instead.
 *
 * Each face posts to SecurityPinController and the server redirects back to
 * the document, which then renders in full -- nothing here unlocks anything by
 * itself.
 */
export function SecurityPinDialog({
    open,
    onOpenChange,
    hasPin,
    attemptsLeft,
    maxAttempts,
    idleMinutes,
}: Props) {
    const [mode, setMode] = useState<SecurityPinMode>(
        hasPin ? 'verify' : 'create',
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                {mode === 'create' && <CreatePin idleMinutes={idleMinutes} />}
                {mode === 'verify' && (
                    <VerifyPin
                        attemptsLeft={attemptsLeft}
                        maxAttempts={maxAttempts}
                        onForgot={() => setMode('forgot')}
                    />
                )}
                {mode === 'forgot' && (
                    <ForgotPin onBack={() => setMode('verify')} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function Heading({
    icon: Icon,
    title,
    children,
}: {
    icon: typeof KeyRound;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <DialogHeader className="items-center text-center">
            <span className="mb-2 flex size-12 items-center justify-center rounded-full bg-[#E8F0FB] text-[#3B72C4]">
                <Icon aria-hidden="true" className="size-6" />
            </span>
            <DialogTitle className="text-xl text-navy">{title}</DialogTitle>
            <DialogDescription className="text-sm text-copy">
                {children}
            </DialogDescription>
        </DialogHeader>
    );
}

function CreatePin({ idleMinutes }: { idleMinutes: number }) {
    const [pin, setPin] = useState('');
    const [confirmation, setConfirmation] = useState('');

    return (
        <>
            <Heading icon={ShieldCheck} title="Create your Security PIN">
                Choose a {PIN_LENGTH}-digit PIN that only you know. You will
                enter it whenever you open a document, and again after{' '}
                {minutesLabel(idleMinutes)} without activity — so nobody can
                read your documents on a computer you left signed in.
            </Heading>

            <Form
                {...SecurityPinController.store.form()}
                options={{ preserveScroll: true }}
                onError={() => {
                    setPin('');
                    setConfirmation('');
                    focusField('security-pin-new');
                }}
                className="mt-2 space-y-5"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2 text-center">
                            <Label htmlFor="security-pin-new">New PIN</Label>
                            <PinInput
                                id="security-pin-new"
                                name="pin"
                                value={pin}
                                onChange={setPin}
                                autoFocus
                                disabled={processing}
                                invalid={Boolean(errors.pin)}
                                describedBy="security-pin-new-error"
                            />
                        </div>

                        <div className="grid gap-2 text-center">
                            <Label htmlFor="security-pin-confirm">
                                Confirm PIN
                            </Label>
                            <PinInput
                                id="security-pin-confirm"
                                name="pin_confirmation"
                                value={confirmation}
                                onChange={setConfirmation}
                                disabled={processing}
                                invalid={Boolean(errors.pin)}
                                describedBy="security-pin-new-error"
                            />
                        </div>

                        <div
                            id="security-pin-new-error"
                            className="text-center"
                        >
                            <InputError message={errors.pin} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={
                                processing ||
                                pin.length < PIN_LENGTH ||
                                confirmation.length < PIN_LENGTH
                            }
                        >
                            {processing ? 'Saving…' : 'Save PIN and open'}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

function VerifyPin({
    attemptsLeft,
    maxAttempts,
    onForgot,
}: {
    attemptsLeft: number;
    maxAttempts: number;
    onForgot: () => void;
}) {
    const [pin, setPin] = useState('');
    const form = useRef<FormComponentRef>(null);

    return (
        <>
            <Heading icon={LockKeyhole} title="Enter your Security PIN">
                This document is protected. Enter your {PIN_LENGTH}-digit PIN to
                view it.
            </Heading>

            <Form
                {...SecurityPinController.verify.form()}
                ref={form}
                options={{ preserveScroll: true }}
                onError={() => {
                    setPin('');
                    focusField('security-pin');
                }}
                className="mt-2 space-y-5"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2 text-center">
                            <Label htmlFor="security-pin" className="sr-only">
                                Security PIN
                            </Label>
                            <PinInput
                                id="security-pin"
                                name="pin"
                                value={pin}
                                onChange={setPin}
                                // Four digits is the whole answer: send it as
                                // soon as the last one lands.
                                onComplete={() =>
                                    setTimeout(() => form.current?.submit(), 0)
                                }
                                autoFocus
                                disabled={processing}
                                invalid={Boolean(errors.pin)}
                                describedBy="security-pin-error"
                            />
                            <div id="security-pin-error">
                                <InputError message={errors.pin} />
                            </div>
                            {!errors.pin && attemptsLeft < maxAttempts && (
                                <p className="text-xs text-copy">
                                    {attemptsLeft}{' '}
                                    {attemptsLeft === 1 ? 'try' : 'tries'} left
                                    before you are signed out.
                                </p>
                            )}
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing || pin.length < PIN_LENGTH}
                        >
                            {processing ? 'Checking…' : 'Open document'}
                        </Button>

                        <button
                            type="button"
                            onClick={onForgot}
                            className="mx-auto block text-sm font-bold text-link hover:underline"
                        >
                            Forgot PIN?
                        </button>
                    </>
                )}
            </Form>
        </>
    );
}

function ForgotPin({ onBack }: { onBack: () => void }) {
    const [pin, setPin] = useState('');
    const [confirmation, setConfirmation] = useState('');

    return (
        <>
            <Heading icon={KeyRound} title="Set a new Security PIN">
                Enter the password you sign in with, then choose a new{' '}
                {PIN_LENGTH}-digit PIN.
            </Heading>

            <Form
                {...SecurityPinController.update.form()}
                options={{ preserveScroll: true }}
                resetOnError={['current_password']}
                onError={(errors) => {
                    setPin('');
                    setConfirmation('');
                    focusField(
                        errors.current_password
                            ? 'security-pin-password'
                            : 'security-pin-reset',
                    );
                }}
                className="mt-2 space-y-5"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="security-pin-password">
                                Your password
                            </Label>
                            <PasswordInput
                                id="security-pin-password"
                                name="current_password"
                                autoComplete="current-password"
                                autoFocus
                                disabled={processing}
                            />
                            <InputError message={errors.current_password} />
                        </div>

                        <div className="grid gap-2 text-center">
                            <Label htmlFor="security-pin-reset">New PIN</Label>
                            <PinInput
                                id="security-pin-reset"
                                name="pin"
                                value={pin}
                                onChange={setPin}
                                disabled={processing}
                                invalid={Boolean(errors.pin)}
                                describedBy="security-pin-reset-error"
                            />
                        </div>

                        <div className="grid gap-2 text-center">
                            <Label htmlFor="security-pin-reset-confirm">
                                Confirm PIN
                            </Label>
                            <PinInput
                                id="security-pin-reset-confirm"
                                name="pin_confirmation"
                                value={confirmation}
                                onChange={setConfirmation}
                                disabled={processing}
                                invalid={Boolean(errors.pin)}
                                describedBy="security-pin-reset-error"
                            />
                        </div>

                        <div
                            id="security-pin-reset-error"
                            className="text-center"
                        >
                            <InputError message={errors.pin} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={
                                processing ||
                                pin.length < PIN_LENGTH ||
                                confirmation.length < PIN_LENGTH
                            }
                        >
                            {processing ? 'Saving…' : 'Save new PIN and open'}
                        </Button>

                        <button
                            type="button"
                            onClick={onBack}
                            className="mx-auto block text-sm font-bold text-link hover:underline"
                        >
                            Back to Enter PIN
                        </button>
                    </>
                )}
            </Form>
        </>
    );
}
