import { Form } from '@inertiajs/react';
import { useState } from 'react';
import SecurityPinController from '@/actions/App/Http/Controllers/SecurityPinController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { PIN_LENGTH, PinInput } from '@/components/security-pin/pin-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

export type SecurityPinSettingsProps = {
    has_pin: boolean;
    set_at: string | null;
};

const setAtFormat = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'long',
    timeStyle: 'short',
});

/**
 * Settings > Security: change the Security PIN (client request, 2026-09-25).
 *
 * The same endpoint as "Forgot PIN?" in the pop-up -- the account password is
 * what proves who is asking in both places, so a person at somebody else's
 * unlocked desk cannot choose a new PIN for them.
 */
export function SecurityPinSettings({
    pin,
}: {
    pin: SecurityPinSettingsProps;
}) {
    const [value, setValue] = useState('');
    const [confirmation, setConfirmation] = useState('');

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Security PIN"
                description={
                    pin.has_pin && pin.set_at
                        ? `Your ${PIN_LENGTH}-digit PIN is asked for before a document is shown. Last set ${setAtFormat.format(new Date(pin.set_at))}.`
                        : `You have not created your ${PIN_LENGTH}-digit PIN yet. Set it here, or you will be asked to the first time you open a document.`
                }
            />

            <Form
                {...SecurityPinController.update.form()}
                options={{ preserveScroll: true }}
                resetOnError={['current_password']}
                resetOnSuccess={['current_password']}
                onError={() => {
                    setValue('');
                    setConfirmation('');
                }}
                onSuccess={() => {
                    setValue('');
                    setConfirmation('');
                }}
                className="space-y-6"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="pin_current_password">
                                Current password
                            </Label>
                            <PasswordInput
                                id="pin_current_password"
                                name="current_password"
                                className="mt-1 block w-full"
                                autoComplete="current-password"
                                placeholder="Current password"
                            />
                            <InputError message={errors.current_password} />
                        </div>

                        <div className="flex flex-wrap gap-8">
                            <div className="grid gap-2">
                                <Label htmlFor="settings-pin">
                                    {pin.has_pin ? 'New PIN' : 'PIN'}
                                </Label>
                                <PinInput
                                    id="settings-pin"
                                    name="pin"
                                    value={value}
                                    onChange={setValue}
                                    disabled={processing}
                                    invalid={Boolean(errors.pin)}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="settings-pin-confirmation">
                                    Confirm PIN
                                </Label>
                                <PinInput
                                    id="settings-pin-confirmation"
                                    name="pin_confirmation"
                                    value={confirmation}
                                    onChange={setConfirmation}
                                    disabled={processing}
                                    invalid={Boolean(errors.pin)}
                                />
                            </div>
                        </div>

                        <InputError message={errors.pin} />

                        <Button
                            disabled={
                                processing ||
                                value.length < PIN_LENGTH ||
                                confirmation.length < PIN_LENGTH
                            }
                        >
                            {pin.has_pin ? 'Change PIN' : 'Save PIN'}
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}
