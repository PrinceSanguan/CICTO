import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Clock,
    FileCheck2,
    Files,
    Inbox,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { DocumentCards } from '@/components/documents/document-cards';
import { StatusPill } from '@/components/documents/status-pill';
import { dashboard } from '@/routes';
import documents from '@/routes/documents';
import type { DocumentListItem } from '@/types';

type Props = {
    summary: {
        total: number;
        processed: number;
        delayed: number;
        approval_rate: number | null;
    };
    stats: {
        inbox: number;
        overdue: number;
        approaching: number;
        submitted: number;
    };
    recent: DocumentListItem[];
    isAdmin: boolean;
};

/** §18 Dashboard. */
export default function Dashboard({ summary, stats, recent, isAdmin }: Props) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                        Dashboard
                    </h1>
                    <p className="mt-1 text-[15px] font-medium text-white/90">
                        Documents that need your attention.
                    </p>
                </div>

                <Link
                    href={documents.create()}
                    className="flex items-center gap-2 rounded-lg bg-[#3B72C4] px-5 py-3 text-sm font-bold text-white shadow-lg transition hover:bg-[#31629F]"
                >
                    <Plus className="size-5" />
                    Submit Document
                </Link>
            </div>

            {/*
                §18's four headline figures, from the same DocumentStats method
                Reports uses -- so the dashboard and the report can never quote
                different totals for the same office. These COUNT archived
                documents: filing is not deletion, and excluding filed work
                would undercount everything the office finished.
            */}
            <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Tile
                    icon={Files}
                    tint="#E8B84B"
                    label="Total Documents"
                    value={summary.total}
                />
                <Tile
                    icon={BarChart3}
                    tint="#3B72C4"
                    label="Processed This Month"
                    value={summary.processed}
                />
                <Tile
                    icon={AlertTriangle}
                    tint="#E07A3E"
                    label="Delayed"
                    value={summary.delayed}
                />
                <Tile
                    icon={ShieldCheck}
                    tint="#2F7BE0"
                    label="Approval Rate"
                    // An em dash, never "0%" — nothing decided yet is not the
                    // same as everything rejected.
                    value={
                        summary.approval_rate === null
                            ? '—'
                            : `${summary.approval_rate}%`
                    }
                />
            </div>

            {/* Operational counters: a different question from the figures above. */}
            <section className="mt-6 rounded-xl bg-white p-5 shadow-xl">
                <h2 className="text-lg font-bold text-navy">
                    {isAdmin ? 'In your office' : 'Your submissions'}
                </h2>

                <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Counter
                        icon={Inbox}
                        label={isAdmin ? 'Open here' : 'Open'}
                        value={stats.inbox}
                    />
                    <Counter
                        icon={AlertTriangle}
                        label="Overdue"
                        value={stats.overdue}
                        tone={stats.overdue > 0 ? 'danger' : undefined}
                    />
                    <Counter
                        icon={Clock}
                        label="Due soon"
                        value={stats.approaching}
                        tone={stats.approaching > 0 ? 'warning' : undefined}
                    />
                    <Counter
                        icon={FileCheck2}
                        label="Submitted by me"
                        value={stats.submitted}
                    />
                </div>
            </section>

            <section className="mt-6 overflow-hidden rounded-xl bg-white shadow-xl">
                <h2 className="border-b border-[#EEF2F7] px-5 py-4 text-lg font-bold text-navy">
                    {isAdmin ? 'Waiting in your office' : 'Your open documents'}
                </h2>

                {/* Phone: cards, so the status and the link stay reachable. */}
                <div className="md:hidden">
                    <DocumentCards items={recent} />
                </div>

                <div className="hidden overflow-x-auto md:block">
                    <table className="w-full min-w-[640px] text-left">
                        <thead className="bg-[#E8F0FB]">
                            <tr>
                                {[
                                    'Control No.',
                                    'Title',
                                    'Currently at',
                                    'Status',
                                    'Deadline',
                                ].map((heading) => (
                                    <th
                                        key={heading}
                                        scope="col"
                                        className="px-5 py-3 text-sm font-bold text-navy"
                                    >
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {recent.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-5 py-10 text-center text-sm text-copy"
                                    >
                                        No open documents right now.
                                    </td>
                                </tr>
                            )}

                            {recent.map((item) => (
                                <tr
                                    key={item.id}
                                    className="border-t border-[#EEF2F7]"
                                >
                                    <td className="px-5 py-3 font-mono text-sm font-semibold text-navy">
                                        <Link
                                            href={documents.show(item.id)}
                                            className="hover:underline"
                                        >
                                            {item.control_number}
                                        </Link>
                                    </td>
                                    <td className="max-w-72 truncate px-5 py-3 text-sm font-medium text-navy">
                                        {item.title}
                                    </td>
                                    <td className="px-5 py-3 text-sm text-copy">
                                        {item.current_office ?? '—'}
                                    </td>
                                    <td className="px-5 py-3">
                                        <StatusPill
                                            tone={item.status_tone}
                                            label={item.status_label}
                                            className="min-w-0 px-3 py-1.5 text-xs"
                                        />
                                    </td>
                                    <td className="px-5 py-3 text-sm text-copy">
                                        {item.due_state === 'none'
                                            ? '—'
                                            : item.due_state_label}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </>
    );
}

function Tile({
    icon: Icon,
    tint,
    label,
    value,
}: {
    icon: typeof Files;
    tint: string;
    label: string;
    value: number | string;
}) {
    return (
        <div className="flex items-center gap-3 rounded-xl bg-white p-5 shadow-xl">
            <Icon
                aria-hidden="true"
                className="size-9 shrink-0"
                style={{ color: tint }}
                strokeWidth={1.75}
            />
            <div className="min-w-0">
                <p className="text-[15px] font-bold text-navy">{label}</p>
                <p className="text-2xl font-extrabold text-navy tabular-nums">
                    {value}
                </p>
            </div>
        </div>
    );
}

function Counter({
    icon: Icon,
    label,
    value,
    tone,
}: {
    icon: typeof Files;
    label: string;
    value: number;
    tone?: 'danger' | 'warning';
}) {
    const colour =
        tone === 'danger'
            ? 'text-[#C5372D]'
            : tone === 'warning'
              ? 'text-[#B37A00]'
              : 'text-navy';

    return (
        <div className="rounded-lg border border-[#E4EAF2] p-4">
            <p className="flex items-center gap-2 text-sm text-copy">
                <Icon aria-hidden="true" className="size-4" />
                {label}
            </p>
            <p
                className={`mt-1 text-2xl font-extrabold tabular-nums ${colour}`}
            >
                {value}
            </p>
        </div>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
