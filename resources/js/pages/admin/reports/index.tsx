import { Head, Link } from '@inertiajs/react';
import { lazy, Suspense } from 'react';
import { PanelHeading } from '@/components/admin/panel-heading';
import { ToneBadge } from '@/components/documents/status-badge';
import admin from '@/routes/admin';
import documents from '@/routes/documents';
import type { DocumentListItem } from '@/types';

const AdminChartBundle = lazy(
    () => import('@/components/admin/admin-chart-bundle'),
);

type PendingRow = DocumentListItem & {
    uploaded_by: string | null;
    updated_at: string | null;
};

type Props = {
    trend: {
        month: string;
        label: string;
        approved: number;
        pending: number;
        rejected: number;
    }[];
    pending: PendingRow[];
};

/**
 * §4's Reports item in the Admin Panel sidebar.
 *
 * Two cards, exactly as the design has them: the trend, and what is still
 * waiting. The chart component is shared with the panel dashboard so the two
 * screens can never disagree about what a bucket means; the table here is its
 * own, because the design gives it four columns and no row actions -- this is
 * a report, not a work queue.
 */
export default function AdminReports({ trend, pending }: Props) {
    return (
        <>
            <Head title="Reports" />

            <PanelHeading />

            <Card title="Reports" className="mt-6">
                <Suspense
                    fallback={
                        <div className="h-[280px] animate-pulse rounded-lg bg-[#EEF2F7]" />
                    }
                >
                    <AdminChartBundle trend={trend} />
                </Suspense>
            </Card>

            <Card title="Pending Document" className="mt-6">
                {/* Phone: cards, so Status stays on screen. */}
                <ul className="divide-y divide-[#EEF2F7] md:hidden">
                    {pending.length === 0 && (
                        <li className="py-8 text-center text-sm text-copy">
                            Nothing is waiting on this office.
                        </li>
                    )}

                    {pending.map((row) => (
                        <li key={row.id} className="py-4 first:pt-0">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <Link
                                        href={documents.show(row.id)}
                                        className="block font-semibold break-words text-navy hover:underline"
                                    >
                                        {row.title}
                                    </Link>
                                    <span className="mt-0.5 block font-mono text-xs text-copy">
                                        {row.control_number}
                                    </span>
                                </div>

                                <ToneBadge tone={row.status_tone}>
                                    {row.status_label}
                                </ToneBadge>
                            </div>

                            <dl className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-copy">
                                <div className="flex gap-1">
                                    <dt>Uploaded by</dt>
                                    <dd className="font-medium text-navy">
                                        {row.uploaded_by ?? '—'}
                                    </dd>
                                </div>
                                <div className="flex gap-1">
                                    <dt>Updated</dt>
                                    <dd className="font-medium text-navy">
                                        <FormattedDate iso={row.updated_at} />
                                    </dd>
                                </div>
                            </dl>
                        </li>
                    ))}
                </ul>

                <div className="hidden overflow-x-auto md:block">
                    <table className="w-full min-w-[560px] text-left">
                        <thead>
                            <tr className="border-b border-[#EEF2F7]">
                                {[
                                    'Document Title',
                                    'Uploaded By',
                                    'Date Updated',
                                    'Status',
                                ].map((heading) => (
                                    <th
                                        key={heading}
                                        scope="col"
                                        className="px-3 py-3 text-sm font-bold text-navy"
                                    >
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>

                        <tbody>
                            {pending.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-3 py-10 text-center text-sm text-copy"
                                    >
                                        Nothing is waiting on this office.
                                    </td>
                                </tr>
                            )}

                            {pending.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-b border-[#EEF2F7] last:border-0"
                                >
                                    <td className="px-3 py-4">
                                        <Link
                                            href={documents.show(row.id)}
                                            className="font-semibold text-navy hover:underline"
                                        >
                                            {row.title}
                                        </Link>
                                        <span className="mt-0.5 block font-mono text-xs text-copy">
                                            {row.control_number}
                                        </span>
                                    </td>
                                    <td className="px-3 py-4 text-sm text-copy">
                                        {row.uploaded_by ?? '—'}
                                    </td>
                                    <td className="px-3 py-4 text-sm whitespace-nowrap text-copy">
                                        <FormattedDate iso={row.updated_at} />
                                    </td>
                                    <td className="px-3 py-4">
                                        <ToneBadge tone={row.status_tone}>
                                            {row.status_label}
                                        </ToneBadge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </>
    );
}

function Card({
    title,
    children,
    className = '',
}: {
    title: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section
            className={`rounded-xl bg-white p-6 shadow-sm ${className}`.trim()}
        >
            <h2 className="text-xl font-extrabold tracking-tight text-navy">
                {title}
            </h2>
            <div className="mt-4">{children}</div>
        </section>
    );
}

/** Rendered client-side so each reader sees their own locale. */
function FormattedDate({ iso }: { iso: string | null }) {
    if (!iso) {
        return <>—</>;
    }

    return (
        <>
            {new Date(iso).toLocaleDateString(undefined, {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
            })}
        </>
    );
}

AdminReports.layout = {
    breadcrumbs: [{ title: 'Reports', href: admin.reports.index() }],
};
