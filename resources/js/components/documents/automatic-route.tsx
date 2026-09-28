import {
    ArrowDown,
    ArrowUp,
    Info,
    RotateCcw,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useState } from 'react';
import {
    UnstaffedWarning,
    unstaffed,
} from '@/components/documents/office-route-picker';
import { Button } from '@/components/ui/button';
import type { IdNameOption, RouteTemplate, RouteTemplateStep } from '@/types';

/**
 * One line of the route as the sender has it now: a step of the type's
 * template, or an office they added themselves. The template's steps are only
 * where the list STARTS (client, 2026-09-26: "mag auto lagay lang sya ng
 * offices pero pwede pa rin sya ma dagdagan or mabawasan").
 */
export type Entry =
    { key: string; stepIndex: number } | { key: string; officeId: number };

type Row = {
    entry: Entry;
    /** Null for an office the sender added. */
    step: RouteTemplateStep | null;
    office: IdNameOption | null;
    /** Ticked, or not optional. A note is never included: it is not a stop. */
    included: boolean;
    /** Its place on the route, the filing office being 1. Null: not a stop. */
    number: number | null;
    /** The same office as the stop before it: the folder is already there. */
    alreadyThere: boolean;
    /** A `choose` step's options. */
    choices?: IdNameOption[];
};

/** The list a type starts from: every step of its template, in order. */
export const initialEntries = (template: RouteTemplate): Entry[] =>
    template.steps.map((_, index) => ({ key: `s${index}`, stepIndex: index }));

/**
 * The route a document type suggests, as the sender has edited it.
 *
 * Pure, so what the rows show and what the form posts can never disagree:
 * both come out of this one walk. The filing office is always stop 1; every
 * line after it is a stop unless it is left out (an unticked optional step, an
 * office this installation does not have, an unanswered choice) or is the
 * office the folder would already be at.
 */
export function resolveRoute(
    template: RouteTemplate,
    entries: Entry[],
    offices: IdNameOption[],
    originId: number | null,
    answers: Record<number, number>,
    ticked: Record<number, boolean>,
): { rows: Row[]; ids: number[] } {
    const byId = new Map(offices.map((office) => [office.id, office]));
    const present = new Set(
        entries.flatMap((entry) =>
            'stepIndex' in entry ? [entry.stepIndex] : [],
        ),
    );

    /*
     * A step's office, wherever it now sits in the list: a `same` step
     * follows the choice it names even after the two have been reordered,
     * and has nothing to follow once that choice has been removed.
     */
    const stepOffice = (
        index: number,
    ): {
        officeId: number | null;
        included: boolean;
        choices?: IdNameOption[];
    } => {
        const step = template.steps[index];

        switch (step.kind) {
            case 'office':
                return {
                    officeId: step.office_id,
                    included: !step.optional || (ticked[index] ?? step.checked),
                };

            case 'choose': {
                const only = step.only;
                const choices =
                    only === null
                        ? offices
                        : offices.filter((office) => only.includes(office.id));
                const suggested =
                    step.suggested === 'origin' ? originId : step.suggested;
                const picked = answers[index] ?? suggested;

                return {
                    officeId:
                        picked !== null &&
                        choices.some((office) => office.id === picked)
                            ? picked
                            : null,
                    included: !step.optional || ticked[index] === true,
                    choices,
                };
            }

            case 'same':
                return present.has(step.step)
                    ? { ...stepOffice(step.step), choices: undefined }
                    : { officeId: null, included: false };

            case 'note':
                return { officeId: null, included: false };
        }
    };

    const rows: Row[] = [];
    const ids: number[] = originId === null ? [] : [originId];

    let last = originId;

    for (const entry of entries) {
        const step =
            'stepIndex' in entry ? template.steps[entry.stepIndex] : null;
        const resolved =
            'stepIndex' in entry
                ? stepOffice(entry.stepIndex)
                : { officeId: entry.officeId, included: true };

        const office =
            resolved.officeId === null
                ? null
                : (byId.get(resolved.officeId) ?? null);
        const isStop = resolved.included && office !== null;
        const alreadyThere = isStop && office.id === last;

        let number: number | null = null;

        if (isStop && !alreadyThere && originId !== null) {
            ids.push(office.id);
            number = ids.length;
            last = office.id;
        }

        rows.push({
            entry,
            step,
            office,
            included: resolved.included,
            number,
            alreadyThere,
            choices: resolved.choices,
        });
    }

    return { rows, ids };
}

/**
 * §5's Department field in its default mode: the route is filled in from the
 * document type -- "para po maging automated yung pag pili ng office, hindi
 * sya manual" (2026-09-25) -- and stays editable right here: remove an
 * office, move it, add another (2026-09-26). Manual, the other mode, is for
 * building a route from nothing.
 *
 * It stays MOUNTED while the form is in manual mode, rendering nothing, so the
 * edits made here survive a look at the other mode. The page keys it by
 * document type, which is what resets it.
 *
 * It renders the form's `office_ids[]` itself, only while it is showing.
 */
export function AutomaticRoute({
    template,
    typeName,
    offices,
    originId,
    onOriginChange,
    originLocked,
    hidden,
    disabled,
    fieldClassName,
    onManual,
}: {
    /** Undefined: the type has no template, or no type is chosen yet. */
    template: RouteTemplate | undefined;
    typeName: string | null;
    offices: IdNameOption[];
    /** The office filing it, stop 1. */
    originId: number | null;
    onOriginChange: (id: number | null) => void;
    /** The submitter's own office, which they cannot change. */
    originLocked: boolean;
    hidden: boolean;
    disabled: boolean;
    fieldClassName: string;
    onManual: () => void;
}) {
    const [answers, setAnswers] = useState<Record<number, number>>({});
    const [ticked, setTicked] = useState<Record<number, boolean>>({});
    const [entries, setEntries] = useState<Entry[]>(() =>
        template === undefined ? [] : initialEntries(template),
    );
    const [edited, setEdited] = useState(false);
    const [added, setAdded] = useState(0);

    if (hidden) {
        return null;
    }

    const origin = offices.find((office) => office.id === originId) ?? null;

    /*
     * A Super Admin belongs to no office, so the office it is filed under is
     * theirs to choose -- the same choice the manual picker's first row is.
     * Required, so a route cannot be posted without its first stop.
     */
    const originField = originLocked ? null : (
        <div className="grid min-w-0 gap-1.5">
            <label
                htmlFor="route_origin"
                className="text-xs font-bold text-navy"
            >
                Registered under
            </label>
            <select
                id="route_origin"
                required
                disabled={disabled}
                value={originId ?? ''}
                onChange={(event) =>
                    onOriginChange(Number(event.target.value) || null)
                }
                className={fieldClassName}
            >
                <option value="" disabled>
                    Select the office filing it…
                </option>
                {offices.map((office) => (
                    <option key={office.id} value={office.id}>
                        {office.name}
                    </option>
                ))}
            </select>
        </div>
    );

    if (typeName === null || template === undefined) {
        return (
            <div className="grid min-w-0 gap-3">
                {originField}

                <p className="rounded-md border border-dashed border-[#C9D4E2] bg-[#F7FAFF] px-4 py-3 text-sm text-copy">
                    {typeName === null ? (
                        'Choose a document type and the offices it passes through appear here.'
                    ) : (
                        <>
                            {typeName} has no suggested route yet.{' '}
                            <button
                                type="button"
                                onClick={onManual}
                                className="font-bold text-link underline-offset-2 hover:underline"
                            >
                                Choose the offices manually
                            </button>
                            .
                        </>
                    )}
                </p>

                {originId !== null && (
                    <input type="hidden" name="office_ids[]" value={originId} />
                )}
            </div>
        );
    }

    const { rows, ids } = resolveRoute(
        template,
        entries,
        offices,
        originId,
        answers,
        ticked,
    );

    const stops = ids
        .map((id) => offices.find((office) => office.id === id))
        .filter((office): office is IdNameOption => office !== undefined);

    // A Confidential route is its one choice and nothing else --
    // StoreDocumentRequest refuses any other -- so it is not editable.
    const editable = !template.confidential;

    const change = (next: Entry[]) => {
        setEntries(next);
        setEdited(true);
    };

    const move = (from: number, to: number) => {
        if (to < 0 || to >= entries.length) {
            return;
        }

        const next = [...entries];
        const [moved] = next.splice(from, 1);

        next.splice(to, 0, moved);
        change(next);
    };

    // Anything but the office the route already ends on: the folder cannot be
    // sent on to the desk it would already be at.
    const lastStop = ids.at(-1) ?? null;
    const addable = offices.filter((office) => office.id !== lastStop);

    return (
        <div className="grid min-w-0 gap-3">
            {originField}

            <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                <div className="min-w-0">
                    <p className="text-xs font-bold tracking-wide text-copy uppercase">
                        Route · {typeName}
                    </p>
                    {editable && (
                        <p className="text-xs text-copy">
                            Filled in for this document type. Add, remove or
                            reorder offices as needed.
                        </p>
                    )}
                </div>
                {editable && edited && (
                    <button
                        type="button"
                        disabled={disabled}
                        onClick={() => {
                            setEntries(initialEntries(template));
                            setAnswers({});
                            setTicked({});
                            setEdited(false);
                        }}
                        className="inline-flex items-center gap-1 text-sm font-bold text-link underline-offset-2 hover:underline disabled:opacity-60"
                    >
                        <RotateCcw className="size-3.5" aria-hidden="true" />
                        Reset to suggested
                    </button>
                )}
            </div>

            {template.note && (
                <p className="flex items-start gap-1.5 rounded-md bg-[#EEF4FD] px-3 py-2 text-xs text-navy">
                    <Info
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    {template.note}
                </p>
            )}

            <ol className="grid min-w-0 gap-2">
                <RouteRow
                    number={originId === null ? null : 1}
                    name={origin?.name ?? 'The office filing it'}
                    purpose={
                        originLocked
                            ? 'Your office — registers the document'
                            : 'Registers the document'
                    }
                    badge={
                        originLocked ? (
                            <Badge tone="navy">Your office</Badge>
                        ) : null
                    }
                />

                {rows.map((row, position) => (
                    <StepRow
                        key={row.entry.key}
                        row={row}
                        disabled={disabled}
                        fieldClassName={fieldClassName}
                        controls={
                            editable && row.step?.kind !== 'note'
                                ? {
                                      up:
                                          position > 0
                                              ? () =>
                                                    move(position, position - 1)
                                              : null,
                                      down:
                                          position < rows.length - 1
                                              ? () =>
                                                    move(position, position + 1)
                                              : null,
                                      remove: () =>
                                          change(
                                              entries.filter(
                                                  (entry) =>
                                                      entry.key !==
                                                      row.entry.key,
                                              ),
                                          ),
                                  }
                                : null
                        }
                        onAnswer={(id) => {
                            if ('stepIndex' in row.entry) {
                                const index = row.entry.stepIndex;

                                setAnswers((current) => ({
                                    ...current,
                                    [index]: id,
                                }));
                            }
                        }}
                        onTick={(on) => {
                            if ('stepIndex' in row.entry) {
                                const index = row.entry.stepIndex;

                                setTicked((current) => ({
                                    ...current,
                                    [index]: on,
                                }));
                            }
                        }}
                    />
                ))}
            </ol>

            {editable && (
                <div className="grid min-w-0 gap-1.5">
                    <label
                        htmlFor="route_add"
                        className="text-xs font-bold text-navy"
                    >
                        Add an office to the route
                    </label>
                    <select
                        id="route_add"
                        // An ADD button with a list attached, like the manual
                        // picker's: it never holds a value of its own.
                        value=""
                        disabled={disabled}
                        onChange={(event) => {
                            const officeId = Number(event.target.value);

                            if (officeId) {
                                change([
                                    ...entries,
                                    { key: `a${added}`, officeId },
                                ]);
                                setAdded(added + 1);
                            }
                        }}
                        className={fieldClassName}
                    >
                        <option value="">Add an office…</option>
                        {addable.map((office) => (
                            <option key={office.id} value={office.id}>
                                {office.name}
                                {unstaffed(office) ? ' — no account yet' : ''}
                                {ids.includes(office.id) ? ' — again' : ''}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            {origin !== null && (
                <p className="text-xs text-copy">
                    {ids.length > 1
                        ? `The document is registered under ${origin.name} and moves to the next office each time it is received, finishing at the last one.`
                        : `Nothing to route: the document stays at ${origin.name}.`}
                </p>
            )}

            <UnstaffedWarning offices={stops} />

            {/* What the form posts: the route above, in order. */}
            {originId !== null &&
                ids.map((id, index) => (
                    <input
                        key={index}
                        type="hidden"
                        name="office_ids[]"
                        value={id}
                    />
                ))}
        </div>
    );
}

type Controls = {
    /** Null at the top or bottom of the list. */
    up: (() => void) | null;
    down: (() => void) | null;
    remove: () => void;
};

function StepRow({
    row,
    disabled,
    fieldClassName,
    controls,
    onAnswer,
    onTick,
}: {
    row: Row;
    disabled: boolean;
    fieldClassName: string;
    /** Null when the route cannot be edited (Confidential) or for a note. */
    controls: Controls | null;
    onAnswer: (id: number) => void;
    onTick: (on: boolean) => void;
}) {
    const { step, office, included, number, alreadyThere } = row;

    // An office the sender added: a stop like any other, with nothing to
    // tick and nothing to choose.
    if (step === null) {
        return (
            <RouteRow
                number={number}
                muted={office === null}
                name={office?.name ?? 'Office'}
                purpose="Added by you"
                badge={
                    alreadyThere ? (
                        <Badge tone="sky">Already there</Badge>
                    ) : office !== null && unstaffed(office) ? (
                        <Badge tone="amber">
                            <TriangleAlert
                                className="size-3"
                                aria-hidden="true"
                            />
                            No account yet
                        </Badge>
                    ) : null
                }
                controls={controls}
                label={office?.name ?? 'this office'}
                disabled={disabled}
            />
        );
    }

    if (step.kind === 'note') {
        return (
            <li className="flex min-w-0 items-start gap-2 px-3 py-1 text-xs text-copy italic">
                <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                {step.purpose}
            </li>
        );
    }

    const optional = step.kind !== 'same' && step.optional;
    const fieldId = `route_step_${'stepIndex' in row.entry ? row.entry.stepIndex : row.entry.key}`;

    const tickBox = optional ? (
        <label className="inline-flex shrink-0 items-center gap-1.5 text-xs font-bold text-navy">
            <input
                type="checkbox"
                checked={included}
                disabled={disabled}
                onChange={(event) => onTick(event.target.checked)}
                className="size-4 accent-[#3B72C4]"
            />
            Include
        </label>
    ) : null;

    let status: React.ReactNode = null;

    if (step.kind === 'office' && step.office_id === null) {
        status = (
            <Badge tone="amber">
                <TriangleAlert className="size-3" aria-hidden="true" />
                Not set up — left out
            </Badge>
        );
    } else if (included && alreadyThere) {
        // Where the folder already is: no extra hop, so no number.
        status = <Badge tone="sky">Already there</Badge>;
    } else if (included && office !== null && unstaffed(office)) {
        status = (
            <Badge tone="amber">
                <TriangleAlert className="size-3" aria-hidden="true" />
                No account yet
            </Badge>
        );
    }

    const badge =
        status === null && tickBox === null ? null : (
            <>
                {status}
                {tickBox}
            </>
        );

    if (step.kind === 'choose') {
        const choices = row.choices ?? [];

        return (
            <RouteRow
                number={number}
                muted={!included}
                name={
                    <label htmlFor={fieldId} className="text-navy">
                        {step.purpose}
                    </label>
                }
                badge={badge}
                controls={controls}
                label={office?.name ?? step.purpose}
                disabled={disabled}
            >
                {choices.length === 0 ? (
                    <p className="text-xs text-[#8A5219]">
                        None of the offices for this step is set up. Remove it
                        and add another office below.
                    </p>
                ) : (
                    <select
                        id={fieldId}
                        // Only while the step is on the route: an unticked
                        // optional step must not block the submit.
                        required={included}
                        disabled={disabled || !included}
                        value={office?.id ?? ''}
                        onChange={(event) =>
                            onAnswer(Number(event.target.value))
                        }
                        className={fieldClassName}
                    >
                        <option value="" disabled>
                            Select an office…
                        </option>
                        {choices.map((choice) => (
                            <option key={choice.id} value={choice.id}>
                                {choice.name}
                                {unstaffed(choice) ? ' — no account yet' : ''}
                            </option>
                        ))}
                    </select>
                )}
            </RouteRow>
        );
    }

    const name =
        step.kind === 'office' && step.office_id === null
            ? (step.missing_office ?? 'Office')
            : (office?.name ?? 'The office chosen above');

    return (
        <RouteRow
            number={number}
            muted={!included || office === null}
            name={name}
            purpose={step.purpose}
            badge={badge}
            controls={controls}
            label={name}
            disabled={disabled}
        />
    );
}

function RouteRow({
    number,
    name,
    purpose,
    badge,
    muted = false,
    controls = null,
    label = '',
    disabled = false,
    children,
}: {
    number: number | null;
    name: React.ReactNode;
    purpose?: string;
    badge?: React.ReactNode;
    muted?: boolean;
    controls?: Controls | null;
    /** The office, for the buttons' accessible names. */
    label?: string;
    disabled?: boolean;
    children?: React.ReactNode;
}) {
    return (
        <li
            className={`grid min-w-0 gap-2 rounded-md border border-[#E4EAF2] px-3 py-2 ${
                muted ? 'bg-white' : 'bg-[#F7FAFF]'
            }`}
        >
            {/*
                flex-wrap: on a phone, a name plus "Include" plus three
                buttons is more than one line holds, and the name is the part
                that must stay readable -- so the controls drop below it,
                right-aligned, instead of squeezing it to one word a line.
            */}
            <div className="flex min-w-0 flex-wrap items-start gap-2">
                <span
                    aria-hidden={number === null ? true : undefined}
                    className={`mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold tabular-nums ${
                        number === null
                            ? 'bg-[#E4EAF2] text-copy'
                            : 'bg-[#3B72C4] text-white'
                    }`}
                >
                    {number ?? '–'}
                </span>

                <div
                    className={`min-w-40 flex-1 basis-40 ${muted ? 'opacity-60' : ''}`}
                >
                    {/* Wrapped, not truncated: two offices in the client's
                        list differ only at the end of an 84-character name. */}
                    <div className="text-sm font-medium [overflow-wrap:anywhere] text-navy">
                        {name}
                    </div>
                    {purpose && (
                        <div className="text-xs text-copy">{purpose}</div>
                    )}
                </div>

                {(badge || controls) && (
                    <div className="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                        {badge}
                        {controls && (
                            <span className="inline-flex items-center">
                                <IconButton
                                    label={`Move ${label} earlier`}
                                    disabled={disabled || controls.up === null}
                                    onClick={controls.up}
                                >
                                    <ArrowUp className="size-4" />
                                </IconButton>
                                <IconButton
                                    label={`Move ${label} later`}
                                    disabled={
                                        disabled || controls.down === null
                                    }
                                    onClick={controls.down}
                                >
                                    <ArrowDown className="size-4" />
                                </IconButton>
                                <IconButton
                                    label={`Remove ${label}`}
                                    disabled={disabled}
                                    onClick={controls.remove}
                                >
                                    <X className="size-4" />
                                </IconButton>
                            </span>
                        )}
                    </div>
                )}
            </div>

            {children}
        </li>
    );
}

function IconButton({
    label,
    disabled,
    onClick,
    children,
}: {
    label: string;
    disabled: boolean;
    onClick: (() => void) | null;
    children: React.ReactNode;
}) {
    return (
        <Button
            type="button"
            size="icon"
            variant="ghost"
            className="size-7 shrink-0"
            aria-label={label}
            disabled={disabled || onClick === null}
            onClick={() => onClick?.()}
        >
            {children}
        </Button>
    );
}

function Badge({
    tone,
    children,
}: {
    tone: 'navy' | 'sky' | 'amber';
    children: React.ReactNode;
}) {
    const tones = {
        navy: 'bg-[#E8F0FB] text-navy',
        sky: 'bg-[#E6F4FA] text-[#1F5F7A]',
        amber: 'bg-[#FDF1E3] text-[#9A5B22]',
    } as const;

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ${tones[tone]}`}
        >
            {children}
        </span>
    );
}
