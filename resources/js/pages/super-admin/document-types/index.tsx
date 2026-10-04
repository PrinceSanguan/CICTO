import { Head, router, useForm } from '@inertiajs/react';
import { Lock, Pencil, Plus, RotateCcw, Search } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { PanelHeading } from '@/components/admin/panel-heading';
import {
    draftKey,
    TypeRouteEditor,
} from '@/components/admin/type-route-editor';
import type { RouteDraftStep } from '@/components/admin/type-route-editor';
import InputError from '@/components/input-error';
import superAdmin from '@/routes/super-admin';
import type { IdNameOption } from '@/types';

/** One saved step -- DocumentTypeController::steps(). */
type TypeStep = {
    id: number;
    kind: RouteDraftStep['kind'];
    office_id: number | null;
    office_name: string | null;
    /** True for a step with no office of its own. */
    office_active: boolean;
    optional: boolean;
    checked: boolean;
    purpose: string | null;
    /** A `choose` step's offices and suggestion, in words. */
    detail: string | null;
    /** A `same` step's choice, by step id. */
    same_as: number | null;
};

type TypeRow = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    turnaround_days: number | null;
    /** Made on this page. False: one of DocumentTypeSeeder's 43. */
    is_custom: boolean;
    is_active: boolean;
    /** False only for a placeholder the seeder retired. */
    editable: boolean;
    /** False for a Confidential type: config decides where it goes. */
    route_editable: boolean;
    /** A built-in type somebody has changed. */
    customized: boolean;
    /** A built-in type whose route somebody has changed. */
    route_customized: boolean;
    is_confidential: boolean;
    allows_broadcast: boolean;
    /** Shown above the route on Submit Document. Not editable here. */
    route_note: string | null;
    documents_count: number;
    steps: TypeStep[];
};

type Props = {
    types: TypeRow[];
    offices: IdNameOption[];
    /** RoutePlan::maxStops() - 1: the filer is always stop 1. */
    maxSteps: number;
    /** What a blank turnaround falls back to. */
    defaultTurnaroundDays: number;
};

const INPUT =
    'mt-2 h-11 w-full min-w-0 rounded-lg border border-[#E3E8EF] bg-white px-3 text-[15px] text-navy focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none disabled:bg-[#EEF2F7] disabled:text-copy';

const OUTLINE_BUTTON =
    'flex items-center gap-2 rounded-lg border border-[#D8E3F2] bg-white px-4 py-2 text-sm font-bold whitespace-nowrap text-navy transition hover:bg-[#F2F6FC] disabled:opacity-60';

/**
 * Document Types (client requests 2026-10-02, "mag dagdag ng documents type
 * tapos i input na rin po don yung offices na dadaanan nya, para sa
 * automation"; and 2026-10-04, the built-in types editable too).
 *
 * A Super Admin adds a type and the offices it passes through, and edits any
 * type -- the 43 built-in ones included. On Submit Document, picking a type
 * fills the Automatic route in from what is saved here.
 */
export default function DocumentTypes({
    types,
    offices,
    maxSteps,
    defaultTurnaroundDays,
}: Props) {
    // One panel at a time: 'new', the type being edited, or none.
    const [open, setOpen] = useState<TypeRow | 'new' | null>(null);
    const [query, setQuery] = useState('');

    const custom = types.filter((type) => type.is_custom);
    const builtIn = types.filter((type) => !type.is_custom);
    const needle = query.trim().toLowerCase();
    const shown =
        needle === ''
            ? builtIn
            : builtIn.filter(
                  (type) =>
                      type.name.toLowerCase().includes(needle) ||
                      type.code.toLowerCase().includes(needle),
              );

    const toggle = (type: TypeRow) =>
        setOpen((current) =>
            current !== 'new' && current?.id === type.id ? null : type,
        );

    const isOpen = (type: TypeRow) => open !== 'new' && open?.id === type.id;

    // The panel opens at the top of the list the type is in.
    const panel = (where: 'custom' | 'built-in') =>
        open !== null &&
        (open === 'new' || open.is_custom ? 'custom' : 'built-in') ===
            where && (
            <TypeForm
                // A fresh form per type, so one type's half-typed edits never
                // carry into another's.
                key={open === 'new' ? 'new' : open.id}
                type={open === 'new' ? null : open}
                offices={offices}
                maxSteps={maxSteps}
                defaultTurnaroundDays={defaultTurnaroundDays}
                onDone={() => setOpen(null)}
            />
        );

    const row = (type: TypeRow) => (
        <TypeItem
            key={type.id}
            type={type}
            turnaround={
                type.turnaround_days === null
                    ? `Default (${days(defaultTurnaroundDays)})`
                    : days(type.turnaround_days)
            }
            editing={isOpen(type)}
            onEdit={() => toggle(type)}
        />
    );

    return (
        <>
            <Head title="Document Types" />

            <PanelHeading title="Super Admin Panel" />

            <h2 className="mt-6 text-2xl font-extrabold tracking-tight text-navy">
                Document Types
            </h2>

            <section className="mt-4 rounded-xl border border-[#E4EAF3] bg-white p-6 shadow-sm">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 className="text-lg font-extrabold tracking-tight text-navy">
                            Your document types
                        </h3>
                        <p className="mt-1 max-w-prose text-sm text-copy">
                            Add a type and the offices it passes through. When
                            somebody picks it on Submit Document, the route
                            fills itself in. Documents already filed keep the
                            route they were filed with.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={() =>
                            setOpen((current) =>
                                current === 'new' ? null : 'new',
                            )
                        }
                        aria-expanded={open === 'new'}
                        aria-controls="document-type-panel"
                        className="flex shrink-0 items-center justify-center gap-2 rounded-lg bg-[#3B72C4] px-7 py-3 text-[15px] font-bold text-white shadow-lg transition hover:bg-[#31629F]"
                    >
                        <Plus aria-hidden="true" className="size-5" />
                        Add Document Type
                    </button>
                </div>

                {panel('custom')}

                <ul className="mt-6 divide-y divide-[#EEF2F7]">
                    {custom.length === 0 && (
                        <li className="py-8 text-center text-sm text-copy">
                            None yet. A type you add appears on Submit Document
                            straight away, after the built-in ones.
                        </li>
                    )}

                    {custom.map(row)}
                </ul>
            </section>

            <section className="mt-6 rounded-xl border border-[#E4EAF3] bg-white p-6 shadow-sm">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 className="text-lg font-extrabold tracking-tight text-navy">
                            Built-in document types
                        </h3>
                        <p className="mt-1 max-w-prose text-sm text-copy">
                            These come with the system, from the client's own
                            list. You can change any of them, and a change stays
                            through updates. A route you changed can be put back
                            the way it came.
                        </p>
                    </div>

                    <label className="relative block w-full shrink-0 sm:w-64">
                        <span className="sr-only">Find a built-in type</span>
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-copy"
                        />
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Find a type…"
                            className="h-10 w-full min-w-0 rounded-lg border border-[#E3E8EF] bg-white pr-3 pl-9 text-sm text-navy placeholder:text-[#9AA5B4] focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                        />
                    </label>
                </div>

                {panel('built-in')}

                <ul className="mt-4 divide-y divide-[#EEF2F7]">
                    {shown.length === 0 && (
                        <li className="py-8 text-center text-sm text-copy">
                            No built-in type matches “{query.trim()}”.
                        </li>
                    )}

                    {shown.map(row)}
                </ul>
            </section>
        </>
    );
}

/** One type in either list. */
function TypeItem({
    type,
    turnaround,
    editing,
    onEdit,
}: {
    type: TypeRow;
    turnaround: string;
    editing: boolean;
    onEdit: () => void;
}) {
    return (
        <li className="grid gap-3 py-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-start">
            <div className="min-w-0">
                <p className="flex flex-wrap items-center gap-2">
                    <span className="text-[15px] font-bold text-navy">
                        {type.name}
                    </span>
                    <Code>{type.code}</Code>
                    {!type.is_custom && (
                        <Pill>
                            {type.editable ? (
                                'Built-in'
                            ) : (
                                <>
                                    <Lock
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                    Retired
                                </>
                            )}
                        </Pill>
                    )}
                    {type.customized && <Pill tone="amber">Changed</Pill>}
                    {type.is_confidential && <Pill>Confidential</Pill>}
                    {type.allows_broadcast && <Pill>Broadcast</Pill>}
                    <StatusPill active={type.is_active} />
                </p>
                {type.description && (
                    <p className="mt-1 text-sm text-copy">{type.description}</p>
                )}
                {type.steps.length > 0 ? (
                    <RouteLine steps={type.steps} />
                ) : (
                    <p className="mt-2 text-sm text-copy">
                        No route: the person filing chooses the offices.
                    </p>
                )}
                <p className="mt-1 text-xs text-copy">
                    Turnaround: {turnaround} · {type.documents_count}{' '}
                    {type.documents_count === 1 ? 'document' : 'documents'}{' '}
                    filed
                </p>
            </div>

            {type.editable ? (
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={onEdit}
                        aria-expanded={editing}
                        aria-controls="document-type-panel"
                        className={OUTLINE_BUTTON}
                    >
                        <Pencil aria-hidden="true" className="size-4" />
                        <span>
                            Edit
                            <span className="sr-only"> {type.name}</span>
                        </span>
                    </button>
                    <StatusButton type={type} />
                </div>
            ) : (
                <p className="max-w-60 text-xs text-copy">
                    Retired when the client's list replaced the sample types.
                    Kept for the documents filed under it.
                </p>
            )}
        </li>
    );
}

/** Saved steps as the editor holds them. */
const toDraft = (steps: TypeStep[]): RouteDraftStep[] => {
    const keys = new Map(steps.map((step) => [step.id, draftKey()]));

    return steps.map((step) => ({
        key: keys.get(step.id) ?? draftKey(),
        kind: step.kind,
        office_id: step.office_id,
        office_name: step.office_name ?? '',
        step_id: step.kind === 'office' ? null : step.id,
        same_as:
            step.same_as === null ? null : (keys.get(step.same_as) ?? null),
        detail: step.detail,
        optional: step.optional,
        checked: step.checked,
        purpose: step.purpose ?? '',
    }));
};

/**
 * Add a type, or edit one.
 *
 * useForm rather than <Form>: the route is a list of objects, held as state
 * while it is built, and posted as JSON.
 */
function TypeForm({
    type,
    offices,
    maxSteps,
    defaultTurnaroundDays,
    onDone,
}: {
    type: TypeRow | null;
    offices: IdNameOption[];
    maxSteps: number;
    defaultTurnaroundDays: number;
    onDone: () => void;
}) {
    const routeEditable = type === null || type.route_editable;
    const builtIn = type !== null && !type.is_custom;

    const form = useForm<{
        name: string;
        code: string;
        description: string;
        turnaround_days: string;
        steps: RouteDraftStep[];
    }>({
        name: type?.name ?? '',
        code: type?.code ?? '',
        description: type?.description ?? '',
        turnaround_days: type?.turnaround_days?.toString() ?? '',
        steps: toDraft(type?.steps ?? []),
    });

    // Keys like `steps.2.office_id` are not in useForm's key type.
    const errors = form.errors as Record<string, string | undefined>;

    // Bring the panel to the Super Admin: it opens above a list that may be
    // long, so an Edit pressed near the bottom would open off-screen.
    const heading = useRef<HTMLHeadingElement | null>(null);

    useEffect(() => {
        heading.current?.scrollIntoView({
            block: 'start',
            behavior: 'smooth',
        });
    }, []);

    const blankToNull = (value: string): string | null =>
        value.trim() === '' ? null : value;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            name: data.name,
            // Chosen once. An edit posts none, and the server refuses a
            // different one.
            ...(type === null ? { code: data.code } : {}),
            description: blankToNull(data.description),
            turnaround_days:
                data.turnaround_days === ''
                    ? null
                    : Number(data.turnaround_days),
            // A Confidential type's route is not this form's to send.
            ...(routeEditable
                ? {
                      steps: data.steps.map((step) =>
                          step.kind === 'office'
                              ? {
                                    kind: step.kind,
                                    office_id: step.office_id,
                                    optional: step.optional,
                                    checked: step.optional && step.checked,
                                    purpose: blankToNull(step.purpose),
                                }
                              : {
                                    kind: step.kind,
                                    step_id: step.step_id,
                                    optional: step.optional,
                                    purpose: blankToNull(step.purpose),
                                },
                      ),
                  }
                : {}),
        }));

        const options = { preserveScroll: true, onSuccess: onDone };

        if (type === null) {
            form.post(superAdmin.documentTypes.store.url(), options);
        } else {
            form.patch(superAdmin.documentTypes.update.url(type.id), options);
        }
    };

    return (
        <form
            id="document-type-panel"
            onSubmit={submit}
            className="mt-6 rounded-xl border border-[#E4EAF3] bg-[#F7FAFF] p-5"
        >
            <h3
                ref={heading}
                className="scroll-mt-24 text-lg font-extrabold tracking-tight text-navy"
            >
                {type === null ? 'Add a document type' : `Edit ${type.name}`}
            </h3>

            <div className="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="type-name">Name</Label>
                    <input
                        id="type-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        maxLength={191}
                        autoFocus
                        placeholder="e.g. Special Event Permit"
                        aria-invalid={errors.name ? true : undefined}
                        className={INPUT}
                    />
                    <InputError message={errors.name} />
                </div>

                <div>
                    <Label htmlFor="type-code">Code</Label>
                    <input
                        id="type-code"
                        value={form.data.code}
                        // Fixed once the type exists: the code is a key, not a
                        // label.
                        disabled={type !== null}
                        onChange={(event) =>
                            form.setData(
                                'code',
                                event.target.value
                                    .toUpperCase()
                                    .replace(/\s+/g, '-'),
                            )
                        }
                        maxLength={32}
                        placeholder="e.g. EVENT-PERMIT"
                        aria-describedby="type-code-hint"
                        aria-invalid={errors.code ? true : undefined}
                        className={`${INPUT} font-mono uppercase`}
                    />
                    <p id="type-code-hint" className="mt-1 text-xs text-copy">
                        {type === null
                            ? 'Capital letters, numbers and hyphens. It cannot be changed later.'
                            : 'The code cannot be changed.'}
                    </p>
                    <InputError message={errors.code} />
                </div>

                <div className="sm:col-span-2">
                    <Label htmlFor="type-description">
                        Description (optional)
                    </Label>
                    <input
                        id="type-description"
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        maxLength={500}
                        className={INPUT}
                    />
                    <InputError message={errors.description} />
                </div>

                <div>
                    <Label htmlFor="type-turnaround">
                        Turnaround in days (optional)
                    </Label>
                    <input
                        id="type-turnaround"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={365}
                        value={form.data.turnaround_days}
                        onChange={(event) =>
                            form.setData('turnaround_days', event.target.value)
                        }
                        placeholder={String(defaultTurnaroundDays)}
                        aria-describedby="type-turnaround-hint"
                        aria-invalid={errors.turnaround_days ? true : undefined}
                        className={INPUT}
                    />
                    <p
                        id="type-turnaround-hint"
                        className="mt-1 text-xs text-copy"
                    >
                        How long a document of this type should take, for
                        deadline monitoring. Blank uses the default of{' '}
                        {days(defaultTurnaroundDays)}.
                    </p>
                    <InputError message={errors.turnaround_days} />
                </div>
            </div>

            <fieldset className="mt-6 min-w-0">
                <legend className="text-sm font-bold text-navy">Route</legend>

                {type?.route_note && (
                    <p className="mt-1 max-w-prose rounded-md bg-[#EEF4FC] px-3 py-2 text-xs text-navy">
                        Shown above the route on Submit Document:{' '}
                        {type.route_note}
                    </p>
                )}

                {routeEditable ? (
                    <>
                        <p className="mt-1 mb-3 max-w-prose text-xs text-copy">
                            The offices it passes through, in order. The office
                            filing it is always first, so start with where it
                            goes next. An office can come round again, but not
                            straight after itself.
                            {builtIn &&
                                ' Steps that are not a fixed office came with the type: you can move, reword or remove them, but not add new ones.'}
                        </p>

                        <TypeRouteEditor
                            offices={offices}
                            value={form.data.steps}
                            onChange={(steps) => form.setData('steps', steps)}
                            max={maxSteps}
                            errors={errors}
                            allowStartTicked={builtIn}
                            disabled={form.processing}
                        />

                        {builtIn && type.route_customized && (
                            <RestoreRoute type={type} onDone={onDone} />
                        )}
                    </>
                ) : (
                    <>
                        <p className="mt-1 mb-3 max-w-prose text-xs text-copy">
                            A Confidential document always goes straight to the
                            offices set for Confidential documents, so its route
                            cannot be changed here.
                        </p>
                        {type !== null && <RouteLine steps={type.steps} />}
                        <InputError message={errors.steps} />
                    </>
                )}
            </fieldset>

            <div className="mt-6 flex justify-end gap-3">
                <button
                    type="button"
                    onClick={onDone}
                    className="rounded-lg border border-[#D8E3F2] bg-white px-6 py-2.5 text-sm font-bold text-navy transition hover:bg-[#F2F6FC]"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    disabled={form.processing}
                    className="rounded-lg bg-[#3B72C4] px-6 py-2.5 text-sm font-bold text-white transition hover:bg-[#31629F] disabled:opacity-60"
                >
                    {form.processing
                        ? 'Saving…'
                        : type === null
                          ? 'Add document type'
                          : 'Save changes'}
                </button>
            </div>
        </form>
    );
}

/**
 * Put a built-in type's route back the way it came. Asked twice, in the page
 * rather than a browser dialog: it throws away every change to the route.
 */
function RestoreRoute({ type, onDone }: { type: TypeRow; onDone: () => void }) {
    const [asking, setAsking] = useState(false);
    const [processing, setProcessing] = useState(false);

    const restore = () =>
        router.post(
            superAdmin.documentTypes.restoreRoute.url(type.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
            },
        );

    return (
        <div className="mt-4 rounded-md border border-[#E4EAF2] bg-white px-3 py-3">
            {asking ? (
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-navy">
                        Put back the route {type.name} came with? Every change
                        to its route is lost, including any not saved yet.
                    </p>
                    <div className="flex shrink-0 gap-2">
                        <button
                            type="button"
                            onClick={() => setAsking(false)}
                            disabled={processing}
                            className={OUTLINE_BUTTON}
                        >
                            Keep mine
                        </button>
                        <button
                            type="button"
                            onClick={restore}
                            disabled={processing}
                            className="rounded-lg bg-[#3B72C4] px-4 py-2 text-sm font-bold whitespace-nowrap text-white transition hover:bg-[#31629F] disabled:opacity-60"
                        >
                            {processing ? 'Restoring…' : 'Restore'}
                        </button>
                    </div>
                </div>
            ) : (
                <button
                    type="button"
                    onClick={() => setAsking(true)}
                    className={OUTLINE_BUTTON}
                >
                    <RotateCcw aria-hidden="true" className="size-4" />
                    Restore original route
                </button>
            )}
        </div>
    );
}

/**
 * "Filer → OCM → (CLO) → ARO": optional stops in brackets, a step chosen when
 * filing in italics. A note is not a stop, so it is not here.
 */
function RouteLine({ steps }: { steps: TypeStep[] }) {
    const stops = steps.filter((step) => step.kind !== 'note');
    const number = (id: number | null): number =>
        stops.findIndex((step) => step.id === id) + 2;

    return (
        <p className="mt-2 text-sm text-navy">
            <span className="text-copy">Filing office</span>
            {stops.map((step) => {
                const label =
                    step.kind === 'office'
                        ? (step.office_name ?? '')
                        : step.kind === 'choose'
                          ? step.purpose || 'Office chosen when filing'
                          : `same office as step ${number(step.same_as)}`;

                return (
                    <span key={step.id}>
                        <span className="text-copy"> → </span>
                        <span
                            className={
                                !step.office_active
                                    ? 'text-[#9A5B22] line-through'
                                    : step.kind === 'office'
                                      ? undefined
                                      : 'italic'
                            }
                            title={
                                !step.office_active
                                    ? `${label} is no longer active, so this stop is left out on Submit Document.`
                                    : step.kind === 'choose'
                                      ? `Chosen when filing. ${step.detail ?? ''}`
                                      : step.purpose || undefined
                            }
                        >
                            {step.optional ? `(${label})` : label}
                        </span>
                    </span>
                );
            })}
        </p>
    );
}

function StatusButton({ type }: { type: TypeRow }) {
    const [processing, setProcessing] = useState(false);

    return (
        <button
            type="button"
            disabled={processing}
            onClick={() =>
                router.patch(
                    superAdmin.documentTypes.status.url(type.id),
                    { is_active: !type.is_active },
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                    },
                )
            }
            className={OUTLINE_BUTTON}
        >
            {type.is_active ? 'Deactivate' : 'Activate'}
            <span className="sr-only"> {type.name}</span>
        </button>
    );
}

function Code({ children }: { children: React.ReactNode }) {
    return (
        <span className="rounded bg-[#EEF2F7] px-1.5 py-0.5 font-mono text-[11px] font-bold text-navy">
            {children}
        </span>
    );
}

function Pill({
    tone = 'blue',
    children,
}: {
    tone?: 'blue' | 'amber';
    children: React.ReactNode;
}) {
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ${
                tone === 'amber'
                    ? 'bg-[#FDF1E3] text-[#9A5B22]'
                    : 'bg-[#E8F0FB] text-navy'
            }`}
        >
            {children}
        </span>
    );
}

function StatusPill({ active }: { active: boolean }) {
    return (
        <span
            className={`inline-block rounded-md px-3 py-0.5 text-xs font-bold text-white ${
                active ? 'bg-[#5BC45B]' : 'bg-[#9AA3B4]'
            }`}
        >
            {active ? 'Active' : 'Deactivated'}
        </span>
    );
}

function Label({
    htmlFor,
    children,
}: {
    htmlFor: string;
    children: React.ReactNode;
}) {
    return (
        <label htmlFor={htmlFor} className="block text-sm font-bold text-navy">
            {children}
        </label>
    );
}

const days = (count: number): string =>
    `${count} ${count === 1 ? 'day' : 'days'}`;

DocumentTypes.layout = {
    breadcrumbs: [
        { title: 'Document Types', href: superAdmin.documentTypes.index() },
    ],
};
