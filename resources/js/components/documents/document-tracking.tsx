import {
    Building2,
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

/** The four stages the design names, in order. */
const STAGES = [
    { key: 'initiated', label: 'Initiated' },
    { key: 'under_review', label: 'Under Review' },
    { key: 'approved', label: 'Approved' },
    { key: 'completed', label: 'Completed' },
] as const;

/**
 * Where the document sits on that four-stage rail.
 *
 * The workflow has six states; `returned` and `rejected` are not stages on a
 * happy path, so they are reported against the furthest point actually
 * reached rather than being invented as a fifth box.
 */
function stageIndex(status: string): number {
    switch (status) {
        case 'initiated':
            return 0;
        case 'under_review':
        case 'returned':
            return 1;
        case 'approved':
            return 2;
        case 'completed':
            return 3;
        case 'rejected':
            // Stopped where it was decided, and the status pill says so.
            return 1;
        default:
            return 0;
    }
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

                return (
                    <li key={stage.key} className="flex items-center">
                        {index > 0 && (
                            <span
                                aria-hidden="true"
                                className={`mx-2 hidden h-0.5 w-10 sm:block xl:w-16 ${
                                    done || active
                                        ? 'bg-[#3B72C4]'
                                        : 'bg-[#D9DEE6]'
                                }`}
                            />
                        )}

                        {active ? (
                            // The chevron block the design uses for "you are
                            // here". aria-current does the same job for a
                            // screen reader.
                            <span
                                aria-current="step"
                                className="relative mr-4 flex h-10 items-center rounded-sm bg-[#3B72C4] pr-6 pl-5 text-[15px] font-bold text-white sm:mr-0"
                            >
                                {stage.label}
                                <span
                                    aria-hidden="true"
                                    className="absolute top-0 -right-4 h-0 w-0 border-y-[20px] border-l-[16px] border-y-transparent border-l-[#3B72C4]"
                                />
                            </span>
                        ) : (
                            <span className="flex items-center gap-2">
                                <span
                                    aria-hidden="true"
                                    className={`flex size-6 items-center justify-center rounded-full ${
                                        done
                                            ? 'bg-[#2FA36B] text-white'
                                            : 'bg-[#D9DEE6] text-white'
                                    }`}
                                >
                                    {done ? (
                                        <Check
                                            className="size-4"
                                            strokeWidth={3}
                                        />
                                    ) : (
                                        <ChevronRight className="size-4" />
                                    )}
                                </span>
                                <span
                                    className={`text-[15px] font-bold ${
                                        done ? 'text-[#2FA36B]' : 'text-navy'
                                    }`}
                                >
                                    {stage.label}
                                </span>
                            </span>
                        )}
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
                    icon={Building2}
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
    title,
    value,
    caption,
}: {
    icon: typeof Clock;
    title: string;
    value: React.ReactNode;
    caption?: string;
}) {
    return (
        <div className="flex items-start gap-3 p-4">
            <Icon
                aria-hidden="true"
                className="mt-0.5 size-8 shrink-0 text-[#3B72C4]"
                strokeWidth={1.5}
            />
            <div>
                <p className="text-[15px] font-bold text-link">{title}</p>
                <p className="text-[15px] font-bold text-navy">{value}</p>
                {caption && <p className="text-xs text-copy">{caption}</p>}
            </div>
        </div>
    );
}

/** The vertical stage timeline with per-stage dwell times. */
export function ProgressTimeline({
    timeline,
    summary,
}: {
    timeline: TimelineEntry[];
    summary: string;
}) {
    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <ol className="relative">
                {timeline.map((entry, index) => {
                    const last = index === timeline.length - 1;

                    return (
                        <li
                            key={entry.id}
                            className="flex gap-4 pb-8 last:pb-0"
                        >
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
                                        className="mt-1 w-0.5 flex-1 bg-[#C9D2DE]"
                                    />
                                )}
                            </div>

                            <div className="flex flex-1 flex-wrap items-start justify-between gap-x-6 gap-y-1">
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
                <p className="mt-3 text-sm font-medium text-navy">{summary}</p>
            </aside>
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
    return value
        ? new Date(value).toLocaleString(undefined, {
              year: 'numeric',
              month: 'long',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '—';
}
