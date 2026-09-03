import { ArrowDown, ArrowUp, Plus, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import type { IdNameOption } from '@/types';

const SELECT =
    'h-9 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60';

/**
 * An ordered list of offices, built one pick at a time.
 *
 * Two callers, one meaning. §9 "Send to Another Office" uses it to forward to
 * one office or several in one submit; §5's Submit Document form uses it for
 * the Department field, where the FIRST entry is the originating office -- the
 * one that stamps the control number and physically holds the folder -- and the
 * rest are the same routing plan, queued at registration instead of after it.
 *
 * DELIBERATELY NOT `<select multiple>`. A native multi-select reports its
 * chosen options in DOCUMENT order, not click order, so a routing list built
 * from one silently discards the sequence the sender picked -- and the sequence
 * is the whole content of a route. It is also close to unusable on a phone,
 * which is what the counter staff carry.
 *
 * So: a plain dropdown that ADDS to an ordered list, and the list is the route.
 * One office in it behaves exactly like the single dropdown this replaced.
 */
export function OfficeRoutePicker({
    offices,
    value,
    onChange,
    disabled = false,
    id = 'to_office_ids',
    label = 'Send to',
    noun = 'office',
    className = 'grid gap-3 sm:max-w-md',
    selectClassName = SELECT,
    hint = defaultHint,
    ordered = true,
}: {
    offices: IdNameOption[];
    /** Office ids in visiting order. */
    value: number[];
    onChange: (next: number[]) => void;
    disabled?: boolean;
    /** Id of the add-an-office dropdown, and what an outside label points at. */
    id?: string;
    /** Null when the caller renders its own label for `id`. */
    label?: string | null;
    /** What one entry is called, in this control's own copy. */
    noun?: string;
    className?: string;
    selectClassName?: string;
    /** The sentence under a route of more than one stop. */
    hint?: (first: IdNameOption) => ReactNode;
    /**
     * False when the picks are a SET, not a sequence -- every office served at
     * once, none before another. Numbering them 1, 2, 3 and offering arrows to
     * reorder them would be a lie about what the list does.
     */
    ordered?: boolean;
}) {
    const chosen = value
        .map((id) => offices.find((office) => office.id === id))
        .filter((office): office is IdNameOption => office !== undefined);

    const remaining = offices.filter((office) => !value.includes(office.id));

    /*
     * THE ROUTE IS WHAT IS ON SCREEN.
     *
     * `chosen` is `value` filtered against the offices the server currently
     * offers, and the two can diverge: a rejected submit re-renders with fresh
     * props while Inertia preserves form state, and the offices list is
     * recomputed per request (it excludes whichever office now holds the
     * folder). An id dropped from `chosen` used to stay in `value` -- invisible,
     * unremovable because its X button lives in the rendered list, and still
     * posted. Worse, the arrows index into `chosen` and spliced `value`, so once
     * the two lengths differed they reordered a different office than the one
     * clicked.
     *
     * Rebuilding from `chosen` on every mutation makes that impossible: after
     * any interaction the submitted ids are exactly the rows on screen.
     */
    const commit = (next: IdNameOption[]) =>
        onChange(next.map((office) => office.id));

    /*
     * And close the window where the user changes nothing: reconcile as soon as
     * an id stops being offered, so a stale entry cannot be submitted by someone
     * who just presses Confirm again.
     *
     * The dependencies are JOINED STRINGS, not the arrays themselves. `chosen`
     * is rebuilt every render, so depending on it directly would re-run this
     * forever; comparing the ids as text gives a stable value and lets the deps
     * be complete -- which matters beyond tidiness, because a single
     * `react-hooks` eslint-disable in a file makes the React Compiler skip
     * optimising the whole component.
     */
    const chosenIds = chosen.map((office) => office.id).join(',');
    const valueIds = value.join(',');

    useEffect(() => {
        if (chosenIds !== valueIds) {
            onChange(chosenIds === '' ? [] : chosenIds.split(',').map(Number));
        }
    }, [chosenIds, valueIds, onChange]);

    const ListTag = ordered ? 'ol' : 'ul';

    const move = (from: number, to: number) => {
        if (to < 0 || to >= chosen.length) {
            return;
        }

        const next = [...chosen];
        const [moved] = next.splice(from, 1);

        next.splice(to, 0, moved);
        commit(next);
    };

    return (
        <div className={className}>
            <div className="grid gap-2">
                {label !== null && (
                    <label htmlFor={id} className="text-sm font-medium">
                        {label}
                    </label>
                )}

                <select
                    id={id}
                    // Always reads "Add another…": this control is an ADD button
                    // with a list attached, not a field holding a value. Leaving
                    // the last pick selected would suggest the choice lives here
                    // rather than in the route below it.
                    value=""
                    disabled={disabled || remaining.length === 0}
                    onChange={(event) => {
                        const added = offices.find(
                            (office) =>
                                office.id === Number(event.target.value),
                        );

                        if (added) {
                            commit([...chosen, added]);
                        }
                    }}
                    className={selectClassName}
                >
                    <option value="">
                        {offices.length === 0
                            ? // Not "every one is already on the route": there
                              // are none to add, which is a different problem
                              // and the sentence has to say so.
                              `No ${noun} is available`
                            : remaining.length === 0
                              ? `Every ${noun} is already on the route`
                              : value.length === 0
                                ? `Select ${article(noun)} ${noun}…`
                                : `Add another ${noun}…`}
                    </option>
                    {remaining.map((office) => (
                        <option key={office.id} value={office.id}>
                            {office.name}
                        </option>
                    ))}
                </select>
            </div>

            {chosen.length > 0 && (
                <ListTag className="grid gap-2">
                    {chosen.map((office, index) => (
                        <li
                            key={office.id}
                            className="flex items-center gap-2 rounded-md border border-[#E4EAF2] bg-[#F7FAFF] px-3 py-2"
                        >
                            <span
                                aria-hidden={ordered ? undefined : true}
                                className={`flex shrink-0 items-center justify-center rounded-full bg-[#3B72C4] font-bold text-white ${
                                    ordered
                                        ? 'size-6 text-xs tabular-nums'
                                        : 'size-2'
                                }`}
                            >
                                {ordered ? index + 1 : ''}
                            </span>

                            <span className="min-w-0 flex-1 truncate text-sm font-medium text-navy">
                                {office.name}
                            </span>

                            {ordered && (
                                <>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-7"
                                        disabled={disabled || index === 0}
                                        aria-label={`Move ${office.name} earlier`}
                                        onClick={() => move(index, index - 1)}
                                    >
                                        <ArrowUp className="size-4" />
                                    </Button>

                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-7"
                                        disabled={
                                            disabled ||
                                            index === chosen.length - 1
                                        }
                                        aria-label={`Move ${office.name} later`}
                                        onClick={() => move(index, index + 1)}
                                    >
                                        <ArrowDown className="size-4" />
                                    </Button>
                                </>
                            )}

                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                disabled={disabled}
                                aria-label={`Remove ${office.name}`}
                                onClick={() =>
                                    commit(
                                        chosen.filter(
                                            (row) => row.id !== office.id,
                                        ),
                                    )
                                }
                            >
                                <X className="size-4" />
                            </Button>
                        </li>
                    ))}
                </ListTag>
            )}

            {/*
                Said plainly, because it is the one thing about a route that is
                not obvious from the list: the folder is not in all of these
                offices at once, it travels them in order.
            */}
            {chosen.length > 1 && (
                <p className="flex items-start gap-1.5 text-xs text-copy">
                    <Plus
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    {hint(chosen[0])}
                </p>
            )}
        </div>
    );
}

const defaultHint = (first: IdNameOption): ReactNode => (
    <>
        The document goes to {first.name} now, then moves to the next office
        automatically each time it is approved.
    </>
);

const article = (noun: string): string => (/^[aeiou]/i.test(noun) ? 'an' : 'a');

/**
 * Whatever the server said about the destinations, whichever key it used.
 *
 * Rules on `to_office_ids.*` -- an office deactivated between opening the page
 * and pressing Confirm, say -- are reported by Laravel under an indexed key
 * like `to_office_ids.0`. Reading only the bare key left those invisible, and
 * an invisible validation error is a button that silently does nothing, which
 * is exactly the failure the empty-array bug already cost us once.
 */
export function routeError(
    errors: Record<string, string>,
    field = 'to_office_ids',
): string | undefined {
    return (
        errors[field] ??
        Object.entries(errors).find(([key]) => key.startsWith(`${field}.`))?.[1]
    );
}
