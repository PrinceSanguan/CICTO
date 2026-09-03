import {
    CalendarDays,
    Check,
    ChevronRight,
    Clock,
    Hourglass,
} from 'lucide-react';
import { StatusPill } from '@/components/documents/status-pill';
import type { DocumentDetail, TimelineEntry } from '@/types';

/**
 * §10 Status Tracking, in the shape the client's "View Documents" screen asks
 * for: a stage stepper, the identifying details, a metrics panel, and the
 * per-stage timeline.
 *
 * Everything here is DERIVED from the movement ledger the server already
 * sends. Nothing is stored, so none of it can disagree with the audit trail.
 */

/**
 * The stages the rail names, in order.
 *
 * THREE, not the design's four. "Approved" was the third box until the client
 * removed the approval step on 2026-09-03: an office now presses Received and
 * the document travels straight on. Nothing can enter that stage any more, so
 * leaving the box on the rail would draw every document skipping a step it was
 * never going to take, and `upcomingStages` would promise an approval that is
 * never coming.
 */
const STAGES = [
    { key: 'initiated', label: 'Initiated' },
    { key: 'under_review', label: 'Under Review' },
    { key: 'completed', label: 'Completed' },
] as const;

/**
 * Where the document sits on that rail.
 *
 * The status vocabulary is still six words wide, and three of them are not
 * stages on a happy path -- so `returned` and `rejected` are reported against
 * the furthest point actually reached rather than invented as extra boxes, and
 * `approved` folds into Under Review.
 *
 * Folding `approved` rather than dropping it matters for the client's older
 * documents, which are stored in that status and still get opened. It is the
 * same collapse DocumentStatus::publicLabel() already makes -- both Under
 * Review and Approved read "In Process" -- so the rail and the pill agree.
 */
function stageIndex(status: string): number {
    switch (status) {
        case 'initiated':
            return 0;
        case 'under_review':
        case 'returned':
        case 'approved':
            return 1;
        case 'completed':
            return 2;
        case 'rejected':
            // Stopped where it was decided, and the status pill says so.
            return 1;
        default:
            return 0;
    }
}

/**
 * The stages this document has NOT reached yet.
 *
 * The client's design draws the stage rail vertically as well as across
 * the top: under the stages that happened, the ones still to come appear as
 * empty grey rows with a blank duration. `isOpen` is what keeps that honest --
 * a rejected or completed document has nothing still coming, and listing
 * "Approved" and "Completed" beneath a rejection would be a claim the movement
 * ledger never made.
 */
export function upcomingStages(status: string, isOpen: boolean): string[] {
    if (!isOpen) {
        return [];
    }

    return STAGES.slice(stageIndex(status) + 1).map((stage) => stage.label);
}

/**
 * The document mark the design hangs in the card's left gutter, level with the
 * stage rail rather than tucked against the heading.
 *
 * Drawn rather than pulled from the icon set: everything else on this screen is
 * a 1.5px lucide outline, and the design's mark is a solid block with the page
 * ruling knocked out of it in white and the corner folded. An outline glyph in
 * its place read as a sixth control rather than the sheet's emblem.
 */
export function DocumentMark({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 28"
            aria-hidden="true"
            className={className}
            fill="none"
        >
            <path
                d="M2 3a3 3 0 0 1 3-3h9l8 8v17a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V3Z"
                fill="#3B72C4"
            />
            <path d="M14 0l8 8h-6a2 2 0 0 1-2-2V0Z" fill="#8FB4E4" />
            <g fill="#FFFFFF">
                <rect x="6" y="12" width="12" height="2" rx="1" />
                <rect x="6" y="17" width="12" height="2" rx="1" />
                <rect x="6" y="22" width="8" height="2" rx="1" />
            </g>
        </svg>
    );
}

/**
 * The office mark for the Current Stage tile.
 *
 * The design draws three of the four metric glyphs as thin outlines -- clock,
 * hourglass, calendar -- and this one as a solid block with lit windows, the
 * same treatment as the sheet's document mark. It is the tile that answers
 * "where is it right now", and the weight is what makes it read first.
 */
export function OfficeMark({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 28 28"
            aria-hidden="true"
            className={className}
            fill="none"
        >
            <path
                d="M3 9a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v18H3V9Z"
                fill="#3B72C4"
            />
            <path
                d="M14 3a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v24H14V3Z"
                fill="#2C5EA8"
            />
            <g fill="#FFFFFF">
                <rect x="5.5" y="11" width="2.5" height="2.5" rx="0.5" />
                <rect x="9.5" y="11" width="2.5" height="2.5" rx="0.5" />
                <rect x="5.5" y="15.5" width="2.5" height="2.5" rx="0.5" />
                <rect x="9.5" y="15.5" width="2.5" height="2.5" rx="0.5" />
                <rect x="5.5" y="20" width="2.5" height="2.5" rx="0.5" />
                <rect x="9.5" y="20" width="2.5" height="2.5" rx="0.5" />
                <rect x="16.5" y="5.5" width="2.5" height="2.5" rx="0.5" />
                <rect x="20.5" y="5.5" width="2.5" height="2.5" rx="0.5" />
                <rect x="16.5" y="10" width="2.5" height="2.5" rx="0.5" />
                <rect x="20.5" y="10" width="2.5" height="2.5" rx="0.5" />
                <rect x="16.5" y="14.5" width="2.5" height="2.5" rx="0.5" />
                <rect x="20.5" y="14.5" width="2.5" height="2.5" rx="0.5" />
                <rect x="16.5" y="19" width="2.5" height="2.5" rx="0.5" />
                <rect x="20.5" y="19" width="2.5" height="2.5" rx="0.5" />
            </g>
        </svg>
    );
}

export function StageStepper({ status }: { status: string }) {
    const current = stageIndex(status);

    return (
        /*
         * gap-x below `sm` only.
         *
         * All the horizontal separation in this row came from the connector's
         * `mx-2`, and the connector is hidden on phones -- so at 375px the
         * stages butted together: the green tick sat flush against the previous
         * label and the active chevron's arrow tip, which overflows its own box
         * by 16px, printed straight over the word before it. From `sm` up the
         * connector is back and supplies the spacing itself, so the gap is
         * switched off there rather than added to it.
         */
        <ol className="flex flex-wrap items-center gap-x-3 gap-y-3 sm:gap-x-0">
            {STAGES.map((stage, index) => {
                const done = index < current;
                const active = index === current;

                /*
                    The disc caps the connector arriving from the stage before
                    it -- that is the whole reason it is a chevron. The first
                    stage has no incoming line, so while IT is the banner the
                    disc is a marker pointing at nothing, sitting outside the
                    shape it belongs to. It comes back the moment the stage is
                    done, because then it is a green tick reporting state
                    rather than a joint.
                */
                const badge = !(index === 0 && active);

                return (
                    <li key={stage.key} className="flex items-center">
                        {/*
                            One navy rail, not a blue-ahead/grey-behind pair.
                            The design runs the same dark line between every
                            stage: progress is carried by the badges and the
                            active banner, so colouring the track as well said
                            the same thing twice and left the tail looking
                            disabled rather than simply not yet reached.
                        */}
                        {index > 0 && (
                            <span
                                aria-hidden="true"
                                className="mx-3 hidden h-0.5 w-12 bg-navy sm:block xl:w-20"
                            />
                        )}

                        {/*
                            Badge THEN label, for all four -- the design gives
                            the active stage a filled blue disc in front of its
                            banner exactly like the others, so the rail reads as
                            one row of markers rather than three markers and an
                            unexplained block.
                        */}
                        <span className="flex items-center gap-2">
                            {badge && (
                                <span
                                    aria-hidden="true"
                                    className={`flex size-6 items-center justify-center rounded-full text-white ${
                                        done
                                            ? 'bg-[#2FA36B]'
                                            : active
                                              ? 'bg-[#3B72C4]'
                                              : 'bg-[#C9CFD9]'
                                    }`}
                                >
                                    {done ? (
                                        <Check
                                            className="size-4"
                                            strokeWidth={3}
                                        />
                                    ) : (
                                        <ChevronRight
                                            className="size-4"
                                            strokeWidth={3}
                                        />
                                    )}
                                </span>
                            )}

                            {active ? (
                                // aria-current does for a screen reader what
                                // the banner does for a sighted reader.
                                /*
                                    One clipped shape, not a rounded box with a
                                    border-triangle stuck to its side. That
                                    older pair could never meet cleanly: the
                                    box's rounded right corners cut back from
                                    the triangle's flat edge, leaving a notch
                                    at the join, and the triangle overhung its
                                    own parent so the banner needed `mr-4` to
                                    stop it printing over the next stage.

                                    A clip-path pentagon is one solid fill with
                                    square corners and a point that lands on the
                                    exact vertical centre, and it stays inside
                                    its box, so the spacing to the connector is
                                    the ordinary margin again. `pr-14` is the
                                    text's clearance from where the taper
                                    starts.
                                */
                                <span
                                    aria-current="step"
                                    className="flex h-10 items-center bg-[#3B72C4] pr-14 pl-8 text-[15px] font-bold text-white [clip-path:polygon(0_0,calc(100%_-_32px)_0,100%_50%,calc(100%_-_32px)_100%,0_100%)]"
                                >
                                    {stage.label}
                                </span>
                            ) : (
                                <span
                                    className={`text-[15px] font-bold ${
                                        done ? 'text-[#2FA36B]' : 'text-navy'
                                    }`}
                                >
                                    {stage.label}
                                </span>
                            )}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

export function DocumentFacts({ document }: { document: DocumentDetail }) {
    const rows: { label: string; value: React.ReactNode }[] = [
        { label: 'Control Number', value: document.control_number },
        { label: 'Title', value: document.title },
        {
            label: 'Department',
            value: document.tracking.resting_office ?? '—',
        },
        {
            label: 'Status',
            value: (
                <StatusPill
                    tone={document.status_tone}
                    label={document.status_label}
                />
            ),
        },
        {
            label: 'Date Initiated',
            value: formatDateTime(document.created_at),
        },
    ];

    return (
        <dl className="space-y-4">
            {rows.map((row) => (
                // Stacked on a phone. A fixed 160px label column with the value
                // inline pushed long values (control numbers, titles, the full
                // timestamp) off the right edge of a 375px screen.
                <div
                    key={row.label}
                    className="sm:flex sm:items-center sm:gap-3"
                >
                    <dt className="text-[15px] font-bold text-navy sm:w-40 sm:shrink-0">
                        {row.label}
                    </dt>
                    <span
                        aria-hidden="true"
                        className="hidden text-navy sm:inline"
                    >
                        :
                    </span>
                    <dd className="mt-0.5 min-w-0 text-[15px] font-bold break-words text-navy sm:mt-0">
                        {row.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export function TrackingMetrics({
    document,
    longestStage,
}: {
    document: DocumentDetail;
    longestStage: {
        office: string | null;
        stage: string;
        duration: string;
    } | null;
}) {
    const tracking = document.tracking;

    return (
        <div className="divide-y divide-[#E4EAF2] rounded-lg border border-[#E4EAF2]">
            <div className="grid divide-y divide-[#E4EAF2] sm:grid-cols-2 sm:divide-x sm:divide-y-0 sm:divide-[#E4EAF2]">
                <Metric
                    icon={Clock}
                    title="Pending Time"
                    value={tracking.time_at_current_office ?? '—'}
                    caption={
                        tracking.arrived_at
                            ? `Since ${formatDateTime(tracking.arrived_at)}`
                            : undefined
                    }
                />
                <Metric
                    mark={OfficeMark}
                    /*
                     * A finished document has no open leg, so reading the open
                     * leg alone made this tile say "Not routed yet" about a
                     * document that had just been routed through three offices.
                     * Past tense once it has come to rest.
                     */
                    title={tracking.is_open ? 'Current Stage' : 'Last Stage'}
                    value={tracking.resting_office ?? 'Not routed yet'}
                    caption={`(${document.status_label})`}
                />
            </div>

            <Metric
                icon={Hourglass}
                captionTone="loud"
                title="Longest Stage"
                value={
                    longestStage
                        ? (longestStage.office ?? longestStage.stage)
                        : '—'
                }
                caption={longestStage?.duration}
            />

            <Metric
                icon={CalendarDays}
                title="Expected Completion"
                value={
                    tracking.expected_completion_at
                        ? formatDate(tracking.expected_completion_at)
                        : 'No deadline set'
                }
            />
        </div>
    );
}

function Metric({
    icon: Icon,
    mark: Mark,
    title,
    value,
    caption,
    captionTone = 'quiet',
}: {
    icon?: typeof Clock;
    /** A drawn glyph, for the one tile the design fills in. */
    mark?: (props: { className?: string }) => React.ReactElement;
    title: string;
    value: React.ReactNode;
    caption?: string;
    /** 'loud' renders the caption as a second value line, as drawn. */
    captionTone?: 'quiet' | 'loud';
}) {
    return (
        <div className="flex items-start gap-3 p-4">
            {Mark ? (
                <Mark className="mt-0.5 w-10 shrink-0" />
            ) : (
                Icon && (
                    <Icon
                        aria-hidden="true"
                        className="mt-0.5 size-10 shrink-0 text-[#3B72C4]"
                        strokeWidth={1.5}
                    />
                )
            )}
            <div>
                <p className="text-[15px] font-bold text-link">{title}</p>
                <p className="text-[15px] font-bold text-navy">{value}</p>
                {caption && (
                    <p
                        className={
                            captionTone === 'loud'
                                ? 'text-[15px] font-bold text-navy'
                                : 'text-xs text-copy'
                        }
                    >
                        {caption}
                    </p>
                )}
            </div>
        </div>
    );
}

/** The vertical stage timeline with per-stage dwell times. */
export function ProgressTimeline({
    timeline,
    summary,
    upcoming = [],
}: {
    timeline: TimelineEntry[];
    summary: string;
    /** Stage labels still ahead, drawn as empty rows -- see upcomingStages. */
    upcoming?: string[];
}) {
    return (
        /*
         * A CONTAINER query, not a viewport one.
         *
         * This used to be `lg:grid-cols-[minmax(0,1fr)_320px]`, which triggers
         * on viewport width -- but the card holding it sits in a two-column
         * page grid, so at ~1137px the viewport said "go wide" while the column
         * was only ~480px. The timeline got squeezed to ~160px and the fixed
         * 320px aside printed straight over it. @container measures the space
         * this component actually has.
         */
        <div className="@container">
            <div className="grid gap-6 @2xl:grid-cols-[minmax(0,1fr)_300px]">
                <ol className="relative">
                    {timeline.map((entry, index) => {
                        const last =
                            index === timeline.length - 1 &&
                            upcoming.length === 0;

                        return (
                            <li key={entry.id} className="flex gap-4">
                                <div className="flex flex-col items-center">
                                    <span
                                        aria-hidden="true"
                                        className={`flex size-7 shrink-0 items-center justify-center rounded-full ${
                                            entry.is_open
                                                ? 'bg-[#2563C9]'
                                                : 'bg-[#2FA36B]'
                                        }`}
                                    >
                                        {!entry.is_open && (
                                            <Check
                                                className="size-4 text-white"
                                                strokeWidth={3}
                                            />
                                        )}
                                    </span>
                                    {!last && (
                                        <span
                                            aria-hidden="true"
                                            className="mt-1 w-0.5 flex-1 bg-navy"
                                        />
                                    )}
                                </div>

                                {/*
                                    A two-track grid, not justify-between. The
                                    design lines every "Duration :" up on one
                                    column; pushing each to the right edge of
                                    its own row instead made the labels wander
                                    with the length of the stage name above
                                    them.
                                */}
                                <div
                                    className={`grid flex-1 gap-x-6 gap-y-1 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] ${
                                        last ? '' : 'pb-8'
                                    }`}
                                >
                                    <div>
                                        <p className="text-[15px] font-bold text-navy">
                                            {entry.action_label}
                                        </p>
                                        <p className="text-xs text-copy">
                                            {formatDateTime(entry.arrived_at)}
                                            {entry.to_office
                                                ? ` · ${entry.to_office}`
                                                : ''}
                                        </p>
                                    </div>

                                    <p className="flex items-center gap-2 text-sm text-copy">
                                        <span>Duration :</span>
                                        <span className="font-bold text-navy">
                                            {entry.dwell}
                                        </span>
                                        {entry.is_open && (
                                            <span className="rounded bg-[#DCE9FB] px-2 py-0.5 text-xs font-bold text-link">
                                                (In Process)
                                            </span>
                                        )}
                                    </p>
                                </div>
                            </li>
                        );
                    })}

                    {upcoming.map((label, index) => (
                        <li key={`upcoming-${label}`} className="flex gap-4">
                            <div className="flex flex-col items-center">
                                <span
                                    aria-hidden="true"
                                    className="size-7 shrink-0 rounded-full bg-[#D9DEE6]"
                                />
                                {index < upcoming.length - 1 && (
                                    <span
                                        aria-hidden="true"
                                        className="mt-1 w-0.5 flex-1 bg-navy"
                                    />
                                )}
                            </div>

                            <div
                                className={`grid flex-1 gap-x-6 gap-y-1 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] ${
                                    index < upcoming.length - 1 ? 'pb-8' : ''
                                }`}
                            >
                                <div>
                                    <p className="text-[15px] font-bold text-[#8A9AAE]">
                                        {label}
                                    </p>
                                    <p className="text-xs text-copy">
                                        Not reached yet
                                    </p>
                                </div>

                                <p className="text-sm text-copy">Duration :</p>
                            </div>
                        </li>
                    ))}

                    {timeline.length === 0 && (
                        <li className="text-sm text-copy">
                            Nothing has happened to this document yet.
                        </li>
                    )}
                </ol>

                <aside className="self-end rounded-lg bg-[#DCE9FB] p-5">
                    <p className="flex items-center gap-2 text-[15px] font-bold text-link">
                        <Clock aria-hidden="true" className="size-5" />
                        Processing Summary
                    </p>
                    <p className="mt-3 text-sm font-medium text-navy">
                        {summary}
                    </p>
                </aside>
            </div>
        </div>
    );
}

function formatDate(value: string | null | undefined): string {
    return value
        ? new Date(value).toLocaleDateString(undefined, {
              year: 'numeric',
              month: 'long',
              day: 'numeric',
          })
        : '—';
}

function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    /*
     * Date and time composed, not `toLocaleString`. That helper joins the two
     * with a connector -- "August 11, 2026 at 2:52 PM" in en-US -- and the
     * design writes them plainly, "March 15, 2026 8:15 AM". The extra word is
     * also what wrapped the Pending Time caption onto a second line.
     */
    const at = new Date(value);

    const date = at.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const time = at.toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });

    return `${date} ${time}`;
}
