import { ArrowDown, ArrowUp, Info, TriangleAlert, X } from 'lucide-react';
import {
    collapse,
    routeError,
    unstaffed,
    UnstaffedWarning,
} from '@/components/documents/office-route-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import type { IdNameOption } from '@/types';

/**
 * One step of a type's route, as the editor holds it.
 *
 * `office` steps are the ones a Super Admin adds. The other kinds come with a
 * built-in type, from the client's routing PDF -- see App\Enums\RouteStepKind
 * -- and are kept by `step_id`: they can be moved, reworded or removed here,
 * never made.
 */
export type RouteDraftStep = {
    /** Stable across moves: an office may be on the route twice. */
    key: string;
    kind: 'office' | 'choose' | 'same' | 'note';
    /** An `office` step's office. */
    office_id: number | null;
    /** The saved name, for an office no longer on the active list. */
    office_name: string;
    /** The saved step a kept `choose`, `same` or `note` step is. */
    step_id: number | null;
    /** A `same` step's choice: that step's `key`. */
    same_as: string | null;
    /** A `choose` step's offices and suggestion, in words. */
    detail: string | null;
    optional: boolean;
    /** An optional step that starts ticked (a built-in type's BAC members). */
    checked: boolean;
    purpose: string;
};

let nextKey = 0;

export const draftKey = (): string => `step-${++nextKey}`;

/**
 * The offices the "twice in a row" rule compares, as RoutePlan::collapse
 * sees them in SaveDocumentTypeRequest: a note is not a stop, and a choice
 * is not known yet, so it equals nothing.
 */
const run = (steps: RouteDraftStep[]): number[] =>
    steps.flatMap((step, index) =>
        step.kind === 'note'
            ? []
            : [step.kind === 'office' ? (step.office_id ?? 0) : -1 - index],
    );

const repeats = (steps: RouteDraftStep[]): boolean =>
    collapse(run(steps)).length !== run(steps).length;

/** Every "same office" step after the choice it repeats. */
const inOrder = (steps: RouteDraftStep[]): boolean =>
    steps.every((step, index) => {
        if (step.kind !== 'same') {
            return true;
        }

        const choice = steps.findIndex((other) => other.key === step.same_as);

        return choice !== -1 && choice < index;
    });

/**
 * An office straight after itself becomes one step, keeping the first one's
 * settings -- what removing the office between two visits to the same one
 * leaves. A note between them does not keep them apart.
 */
const merged = (steps: RouteDraftStep[]): RouteDraftStep[] => {
    let previous: RouteDraftStep | null = null;

    return steps.filter((step) => {
        if (step.kind === 'note') {
            return true;
        }

        const repeat =
            step.kind === 'office' &&
            previous?.kind === 'office' &&
            previous.office_id === step.office_id;

        if (!repeat) {
            previous = step;
        }

        return !repeat;
    });
};

const SELECT =
    'h-11 w-full min-w-0 rounded-lg border border-[#E3E8EF] bg-white px-3 text-[15px] text-navy focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none disabled:bg-[#EEF2F7] disabled:text-copy';

const TEXT =
    'h-9 w-full min-w-0 rounded-md border border-[#E3E8EF] bg-white px-3 text-sm text-navy placeholder:text-[#9AA5B4] focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none';

/**
 * The offices a document type passes through (client requests 2026-10-02
 * and, for the built-in types, 2026-10-04).
 *
 * NOT OfficeRoutePicker. That control holds bare office ids, and with an
 * office allowed on the route twice an id does not say which ROW it is -- so
 * a step's "optional" tick and its purpose would come loose from their office
 * the moment a row moved. This one keeps each step whole and borrows the
 * picker's rules instead: an office may come round again but not straight
 * after itself (`collapse`, which mirrors RoutePlan::collapse), and an office
 * nobody can receive for is named before it is saved.
 *
 * The office FILING the document is not a step here. It is always stop 1 on
 * the Submit form, so the route starts where the folder goes from there.
 */
export function TypeRouteEditor({
    offices,
    value,
    onChange,
    max,
    errors,
    allowStartTicked = false,
    disabled = false,
}: {
    /** The active offices, as the Submit form offers them. */
    offices: IdNameOption[];
    value: RouteDraftStep[];
    onChange: (next: RouteDraftStep[]) => void;
    /** RoutePlan::maxStops() - 1: the filer takes the first stop. */
    max: number;
    errors: Record<string, string | undefined>;
    /** A built-in type's optional steps may start ticked; a custom one's not. */
    allowStartTicked?: boolean;
    disabled?: boolean;
}) {
    const byId = new Map(offices.map((office) => [office.id, office]));
    const last = value.filter((step) => step.kind !== 'note').at(-1);
    const full = value.length >= max;

    // Everything but the office the route already ends on: the folder
    // cannot be sent on to the desk it would already be at.
    const remaining = offices.filter(
        (office) => last?.kind !== 'office' || office.id !== last.office_id,
    );

    const moved = (from: number, to: number): RouteDraftStep[] | null => {
        if (to < 0 || to >= value.length) {
            return null;
        }

        const next = [...value];
        const [row] = next.splice(from, 1);

        next.splice(to, 0, row);

        // Not offered, rather than performed and then merged away.
        return !repeats(next) && inOrder(next) ? next : null;
    };

    // By position: with repeats, removing the second visit must leave the
    // first. A choice takes the steps that repeat it with it.
    const removed = (index: number): RouteDraftStep[] => {
        const gone = value[index];

        return merged(
            value.filter(
                (step, row) =>
                    row !== index &&
                    !(gone.kind === 'choose' && step.same_as === gone.key),
            ),
        );
    };

    const update = (index: number, patch: Partial<RouteDraftStep>) =>
        onChange(
            value.map((step, row) =>
                row === index ? { ...step, ...patch } : step,
            ),
        );

    // Stop numbers, the filer being 1. A note is not a stop.
    const numbers = new Map<string, number>();

    value.forEach((step) => {
        if (step.kind !== 'note') {
            numbers.set(step.key, numbers.size + 2);
        }
    });

    const summary = routeError(
        Object.fromEntries(
            Object.entries(errors).filter(
                (entry): entry is [string, string] =>
                    entry[1] !== undefined &&
                    // Row errors are shown on their row.
                    !/^steps\.\d+\./.test(entry[0]),
            ),
        ),
        'steps',
    );

    return (
        <div className="grid min-w-0 gap-3">
            <ol className="grid min-w-0 gap-2">
                <li className="flex min-w-0 items-center gap-2 rounded-md border border-dashed border-[#D8E3F2] bg-white px-3 py-2">
                    <Bubble>1</Bubble>
                    <span className="min-w-0 flex-1 text-sm text-copy">
                        The office filing the document
                    </span>
                    <span className="shrink-0 rounded-full bg-[#E8F0FB] px-2 py-0.5 text-[11px] font-bold text-navy">
                        Always first
                    </span>
                </li>

                {value.map((step, index) => {
                    const office =
                        step.office_id === null
                            ? undefined
                            : byId.get(step.office_id);
                    const name = title(step, office, numbers);
                    const rowError = Object.entries(errors).find(
                        ([key, message]) =>
                            message !== undefined &&
                            key.startsWith(`steps.${index}.`),
                    )?.[1];

                    return (
                        /*
                            A grid, so the step's name always has the row's
                            width to itself. It used to share one line with a
                            flag and three buttons, none of which can shrink,
                            and at phone width that left the name 0px wide --
                            a numbered row naming no office at all. Now the
                            name wraps instead of truncating, the flag sits
                            under it, and on a phone the buttons take a line
                            of their own under the purpose.
                        */
                        <li
                            key={step.key}
                            className={`grid min-w-0 grid-cols-[auto_minmax(0,1fr)] gap-x-2 gap-y-2 rounded-md border px-3 py-2 sm:grid-cols-[auto_minmax(0,1fr)_auto] ${
                                step.kind === 'note'
                                    ? 'border-dashed border-[#D8E3F2] bg-white'
                                    : 'border-[#E4EAF2] bg-[#F7FAFF]'
                            }`}
                        >
                            <span className="col-start-1 row-start-1">
                                {step.kind === 'note' ? (
                                    <span className="flex size-6 items-center justify-center text-copy">
                                        <Info
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </span>
                                ) : (
                                    <Bubble>{numbers.get(step.key)}</Bubble>
                                )}
                            </span>

                            <div className="col-start-2 row-start-1 min-w-0 self-center">
                                <p className="text-sm font-medium break-words text-navy">
                                    {name}
                                </p>

                                {step.kind === 'choose' && step.detail && (
                                    <p className="text-xs break-words text-copy">
                                        {step.detail}
                                    </p>
                                )}

                                {step.kind === 'office' &&
                                    (office === undefined ? (
                                        <Flag
                                            title={`${name} is no longer active.`}
                                        >
                                            No longer active
                                        </Flag>
                                    ) : (
                                        unstaffed(office) && (
                                            <Flag
                                                title={`${name} has no account that can receive a document yet.`}
                                            >
                                                No account yet
                                            </Flag>
                                        )
                                    ))}
                            </div>

                            <div className="col-start-2 row-start-3 flex justify-end gap-1 sm:col-start-3 sm:row-start-1 sm:self-start">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    className="size-7 shrink-0"
                                    disabled={
                                        disabled ||
                                        moved(index, index - 1) === null
                                    }
                                    aria-label={`Move ${name} earlier`}
                                    onClick={() => {
                                        const next = moved(index, index - 1);

                                        if (next) {
                                            onChange(next);
                                        }
                                    }}
                                >
                                    <ArrowUp className="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    className="size-7 shrink-0"
                                    disabled={
                                        disabled ||
                                        moved(index, index + 1) === null
                                    }
                                    aria-label={`Move ${name} later`}
                                    onClick={() => {
                                        const next = moved(index, index + 1);

                                        if (next) {
                                            onChange(next);
                                        }
                                    }}
                                >
                                    <ArrowDown className="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    className="size-7 shrink-0"
                                    disabled={disabled}
                                    aria-label={`Remove ${name}`}
                                    title={
                                        step.kind === 'choose' &&
                                        value.some(
                                            (other) =>
                                                other.same_as === step.key,
                                        )
                                            ? 'Also removes the step that repeats this choice'
                                            : undefined
                                    }
                                    onClick={() => onChange(removed(index))}
                                >
                                    <X className="size-4" />
                                </Button>
                            </div>

                            <div className="col-start-2 row-start-2 grid min-w-0 gap-2 sm:col-span-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                <input
                                    value={step.purpose}
                                    disabled={disabled}
                                    maxLength={191}
                                    required={
                                        step.kind === 'choose' ||
                                        step.kind === 'note'
                                    }
                                    onChange={(event) =>
                                        update(index, {
                                            purpose: event.target.value,
                                        })
                                    }
                                    placeholder={placeholder(step)}
                                    aria-label={
                                        step.kind === 'note'
                                            ? 'What the note says'
                                            : `What happens at ${name}`
                                    }
                                    className={TEXT}
                                />

                                {(step.kind === 'office' ||
                                    step.kind === 'choose') && (
                                    <div className="grid gap-1">
                                        <label className="flex items-center gap-2 text-xs font-medium text-copy">
                                            <input
                                                type="checkbox"
                                                checked={step.optional}
                                                disabled={disabled}
                                                onChange={(event) =>
                                                    update(index, {
                                                        optional:
                                                            event.target
                                                                .checked,
                                                        checked: false,
                                                    })
                                                }
                                                className="size-4 accent-[#3B72C4]"
                                            />
                                            Optional — only if the filer ticks
                                            it
                                        </label>

                                        {allowStartTicked &&
                                            step.kind === 'office' &&
                                            step.optional && (
                                                <label className="flex items-center gap-2 pl-6 text-xs font-medium text-copy">
                                                    <input
                                                        type="checkbox"
                                                        checked={step.checked}
                                                        disabled={disabled}
                                                        onChange={(event) =>
                                                            update(index, {
                                                                checked:
                                                                    event.target
                                                                        .checked,
                                                            })
                                                        }
                                                        className="size-4 accent-[#3B72C4]"
                                                    />
                                                    Ticked to start with
                                                </label>
                                            )}
                                    </div>
                                )}
                            </div>

                            <InputError
                                message={rowError}
                                className="col-start-2 row-start-4 text-xs sm:col-span-2 sm:row-start-3"
                            />
                        </li>
                    );
                })}
            </ol>

            <select
                id="route-add-office"
                aria-label="Add an office to the route"
                value=""
                disabled={disabled || full || remaining.length === 0}
                onChange={(event) => {
                    const added = byId.get(Number(event.target.value));

                    if (added) {
                        onChange([
                            ...value,
                            {
                                key: draftKey(),
                                kind: 'office',
                                office_id: added.id,
                                office_name: added.name,
                                step_id: null,
                                same_as: null,
                                detail: null,
                                optional: false,
                                checked: false,
                                purpose: '',
                            },
                        ]);
                    }
                }}
                className={SELECT}
            >
                <option value="">
                    {full
                        ? `The route is at its limit of ${max} offices`
                        : value.length === 0
                          ? 'Select the first office it goes to…'
                          : 'Add another office…'}
                </option>
                {remaining.map((office) => (
                    <option key={office.id} value={office.id}>
                        {office.name}
                        {unstaffed(office) ? ' — no account yet' : ''}
                        {value.some((step) => step.office_id === office.id)
                            ? ' — again'
                            : ''}
                    </option>
                ))}
            </select>

            <UnstaffedWarning
                offices={value
                    .map((step) =>
                        step.office_id === null
                            ? undefined
                            : byId.get(step.office_id),
                    )
                    .filter(
                        (office): office is IdNameOption =>
                            office !== undefined,
                    )}
            />

            <InputError message={summary} />
        </div>
    );
}

/** What a row is called: its office, or what kind of step it is. */
function title(
    step: RouteDraftStep,
    office: IdNameOption | undefined,
    numbers: Map<string, number>,
): string {
    switch (step.kind) {
        case 'office':
            return office?.name ?? step.office_name;

        case 'choose':
            return 'Office chosen when filing';

        case 'same': {
            const number =
                step.same_as === null ? undefined : numbers.get(step.same_as);

            return number === undefined
                ? 'The same office as the choice above'
                : `The same office as step ${number}`;
        }

        case 'note':
            return 'Note on Submit Document — not a stop';
    }
}

function placeholder(step: RouteDraftStep): string {
    switch (step.kind) {
        case 'choose':
            return 'Label, e.g. Concerned office — receives';

        case 'note':
            return 'What the note says';

        default:
            return 'Purpose (optional), e.g. Approval';
    }
}

function Bubble({ children }: { children: React.ReactNode }) {
    return (
        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#3B72C4] text-xs font-bold text-white tabular-nums">
            {children}
        </span>
    );
}

function Flag({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <span
            className="mt-1 inline-flex items-center gap-1 rounded-full bg-[#FDF1E3] px-2 py-0.5 text-[11px] font-bold text-[#9A5B22]"
            title={title}
        >
            <TriangleAlert className="size-3" aria-hidden="true" />
            {children}
        </span>
    );
}
