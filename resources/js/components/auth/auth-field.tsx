import { Eye, EyeOff, Lock, Mail, UserRound } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * The auth screens' input treatment: a leading icon inside the field, and a
 * reveal toggle on password fields.
 *
 * Separate from the app's shared `Input` on purpose. These six screens carry
 * the client's own visual language -- taller fields, blue-tinted icons, no dark
 * variant -- and folding that into the shared component would drag it into
 * every settings form.
 */

const FIELD =
    'h-13 w-full rounded-md border border-[#DCE4EE] bg-white pl-12 text-[15px] text-navy ' +
    'placeholder:text-[#9AA5B4] focus-visible:border-brand focus-visible:ring-2 ' +
    'focus-visible:ring-brand/30 focus-visible:outline-none disabled:opacity-60';

export function EmailField({
    className,
    ref,
    ...props
}: ComponentProps<'input'> & { ref?: Ref<HTMLInputElement> }) {
    return (
        <div className="relative">
            <Mail
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#7EA9E0]"
                strokeWidth={1.75}
            />
            <input
                type="email"
                ref={ref}
                className={cn(FIELD, 'pr-4', className)}
                {...props}
            />
        </div>
    );
}

export function TextField({
    className,
    icon: Icon = UserRound,
    ref,
    ...props
}: ComponentProps<'input'> & {
    ref?: Ref<HTMLInputElement>;
    icon?: typeof UserRound;
}) {
    return (
        <div className="relative">
            <Icon
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#7EA9E0]"
                strokeWidth={1.75}
            />
            <input
                type="text"
                ref={ref}
                className={cn(FIELD, 'pr-4', className)}
                {...props}
            />
        </div>
    );
}

export function PasswordField({
    className,
    ref,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & { ref?: Ref<HTMLInputElement> }) {
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            {/*
                Drawn exactly like the Mail icon above it -- outline only, no
                filled navy disc behind it. The disc was the one solid shape on
                an otherwise flat form, so it read as a button and pulled the
                eye off the field it was labelling.
            */}
            <Lock
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#7EA9E0]"
                strokeWidth={1.75}
            />

            <input
                type={visible ? 'text' : 'password'}
                ref={ref}
                className={cn(FIELD, 'pr-12', className)}
                {...props}
            />

            <button
                type="button"
                onClick={() => setVisible((previous) => !previous)}
                // Keyboard reachable. It was tabIndex={-1} for tidiness, but a
                // control that only a mouse can operate is a WCAG 2.1.1
                // Level A failure, and this one guards against typing a
                // password wrong on a shared counter terminal.
                aria-pressed={visible}
                aria-label={visible ? 'Hide password' : 'Show password'}
                className="absolute top-1/2 right-3 -translate-y-1/2 rounded-md p-1 text-[#7B8794] transition hover:text-navy focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:outline-none"
            >
                {visible ? (
                    <Eye className="size-5" strokeWidth={1.75} />
                ) : (
                    <EyeOff className="size-5" strokeWidth={1.75} />
                )}
            </button>
        </div>
    );
}

export function FieldLabel({
    htmlFor,
    children,
    className,
}: {
    htmlFor: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <label
            htmlFor={htmlFor}
            className={cn('block text-sm font-bold text-navy', className)}
        >
            {children}
        </label>
    );
}

/*
 * There was a `danger` variant here, red, for the Super Admin login. It went
 * with the three portals on 2026-08-17 -- one login screen cannot know which
 * role is signing in -- and an unused variant is a variant nobody maintains.
 */
export function AuthSubmit({
    children,
    className,
    ...props
}: ComponentProps<'button'>) {
    return (
        <button
            type="submit"
            className={cn(
                'flex h-12 w-full items-center justify-center gap-2 rounded-md text-[15px] font-bold text-white',
                'transition disabled:cursor-not-allowed disabled:opacity-70',
                'bg-[#3B72C4] hover:bg-[#31629F]',
                // Last, so a caller can narrow the width. It has to go through
                // cn(): spreading className via ...props would REPLACE the base
                // classes rather than merge with them.
                className,
            )}
            {...props}
        >
            {children}
        </button>
    );
}
