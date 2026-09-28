import { OTPInput, OTPInputContext, REGEXP_ONLY_DIGITS } from 'input-otp';
import { useContext } from 'react';
import { cn } from '@/lib/utils';

/** App\Support\SecurityPin::LENGTH. */
export const PIN_LENGTH = 4;

/** "1 minute", "5 minutes" -- the idle timeout is configurable, down to one. */
export function minutesLabel(minutes: number): string {
    return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
}

type Props = {
    id: string;
    name: string;
    value: string;
    onChange: (value: string) => void;
    onComplete?: (value: string) => void;
    autoFocus?: boolean;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
};

/**
 * Four boxes for a Security PIN.
 *
 * Built on the same `input-otp` field as the two-factor code, with one
 * difference that is the point of the feature: the digits are MASKED. The PIN
 * exists because somebody else may be looking at this screen -- showing it in
 * plain figures while it is typed would hand it to them.
 *
 * The real <input> underneath carries `name`, so an Inertia <Form> submits it
 * like any other field.
 */
export function PinInput({
    id,
    name,
    value,
    onChange,
    onComplete,
    autoFocus,
    disabled,
    invalid,
    describedBy,
}: Props) {
    return (
        <OTPInput
            id={id}
            name={name}
            value={value}
            onChange={onChange}
            onComplete={onComplete}
            maxLength={PIN_LENGTH}
            pattern={REGEXP_ONLY_DIGITS}
            inputMode="numeric"
            autoComplete="off"
            autoFocus={autoFocus}
            disabled={disabled}
            aria-invalid={invalid || undefined}
            aria-describedby={describedBy}
            containerClassName="flex items-center justify-center gap-3 has-[:disabled]:opacity-50"
            className="disabled:cursor-not-allowed"
        >
            <MaskedSlots invalid={invalid} />
        </OTPInput>
    );
}

function MaskedSlots({ invalid }: { invalid?: boolean }) {
    const { slots } = useContext(OTPInputContext);

    return (
        <>
            {slots.map((slot, index) => (
                <div
                    key={index}
                    aria-hidden="true"
                    className={cn(
                        'relative flex size-12 items-center justify-center rounded-lg border bg-white text-2xl font-bold text-navy shadow-sm transition-all',
                        invalid ? 'border-[#D93025]' : 'border-[#C9D6E8]',
                        slot.isActive && 'z-10 ring-2 ring-[#3B72C4]',
                    )}
                >
                    {slot.char !== null ? '•' : null}
                    {slot.hasFakeCaret && (
                        <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                            <div className="h-6 w-px animate-caret-blink bg-navy duration-1000" />
                        </div>
                    )}
                </div>
            ))}
        </>
    );
}
