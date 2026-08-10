import { Head, router } from '@inertiajs/react';
import { Download, FileSpreadsheet, FileText } from 'lucide-react';
import { lazy, Suspense } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import reports from '@/routes/reports';

// Recharts is ~95 KB gzipped. Split it out so the rest of the app does not pay
// for a page most users open occasionally.
const Charts = lazy(() => import('@/components/reports/report-charts-bundle'));

type MonthPoint = { month: string; label: string; count: number };
type TrendPoint = { month: string; label: string; days: number | null };
type StatusSlice = { status: string; count: number };
type ActivityRow = {
    user: string;
    office: string | null;
    actions: number;
    approvals: number;
};
type OfficeRow = { office: string; legs: number; average_minutes: number };

type Props = {
    summary: {
        total: number;
        processed: number;
        delayed: number;
        approval_rate: number | null;
    };
    monthlyProcessed: MonthPoint[];
    statusDistribution: StatusSlice[];
    processingTrend: TrendPoint[];
    userActivity: ActivityRow[];
    officePerformance: OfficeRow[];
    months: number;
    canExport: boolean;
    limits: { pdf: number; xlsx: number };
};

function humanMinutes(minutes: number): string {
    if (minutes < 60) {
        return `${minutes}m`;
    }

    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);

    return days > 0 ? `${days}d ${hours}h` : `${hours}h`;
}

export default function ReportsIndex({
    summary,
    monthlyProcessed,
    statusDistribution,
    processingTrend,
    userActivity,
    officePerformance,
    months,
    canExport,
    limits,
}: Props) {
    return (
        <>
            <Head title="Reports" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title="Reports"
                        description={`Document activity over the last ${months} months.`}
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            value={months}
                            onChange={(event) =>
                                router.get(
                                    reports.index.url(),
                                    { months: event.target.value },
                                    { preserveScroll: true },
                                )
                            }
                            aria-label="Reporting period"
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            {[3, 6, 12, 24].map((n) => (
                                <option key={n} value={n}>
                                    Last {n} months
                                </option>
                            ))}
                        </select>

                        {canExport && (
                            <>
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={reports.export.url({
                                            query: { format: 'xlsx' },
                                        })}
                                    >
                                        <FileSpreadsheet className="size-4" />
                                        Excel
                                    </a>
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={reports.export.url({
                                            query: { format: 'pdf' },
                                        })}
                                    >
                                        <FileText className="size-4" />
                                        PDF
                                    </a>
                                </Button>
                                <Button variant="ghost" size="sm" asChild>
                                    <a
                                        href={reports.export.url({
                                            query: { format: 'csv' },
                                        })}
                                        title="Plain CSV — opens in Excel, never runs out of memory"
                                    >
                                        <Download className="size-4" />
                                        CSV
                                    </a>
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {/* §18 headline numbers */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Total documents" value={summary.total} />
                    <Stat
                        label="Processed this month"
                        value={summary.processed}
                    />
                    <Stat
                        label="Delayed"
                        value={summary.delayed}
                        tone={summary.delayed > 0 ? 'danger' : undefined}
                    />
                    <Stat
                        label="Approval rate"
                        // Never "0%" — nothing decided is not the same as
                        // everything rejected.
                        value={
                            summary.approval_rate === null
                                ? '—'
                                : `${summary.approval_rate}%`
                        }
                    />
                </div>

                <Suspense
                    fallback={<Skeleton className="h-64 w-full rounded-xl" />}
                >
                    <Charts
                        monthlyProcessed={monthlyProcessed}
                        statusDistribution={statusDistribution}
                        processingTrend={processingTrend}
                    />
                </Suspense>

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* §19 artifact 4 — the one that is easy to forget */}
                    <section className="rounded-xl border">
                        <h3 className="border-b p-4 text-sm font-semibold">
                            User activity
                        </h3>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Staff</TableHead>
                                    <TableHead>Office</TableHead>
                                    <TableHead className="text-right">
                                        Actions
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Approvals
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {userActivity.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={4}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No activity yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {userActivity.map((row) => (
                                    <TableRow key={`${row.user}-${row.office}`}>
                                        <TableCell>{row.user}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {row.office ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {row.actions}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {row.approvals}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>

                    <section className="rounded-xl border">
                        <h3 className="border-b p-4 text-sm font-semibold">
                            Average time at each office
                        </h3>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Office</TableHead>
                                    <TableHead className="text-right">
                                        Handled
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Average
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {officePerformance.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={3}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            Nothing has completed a stage yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {officePerformance.map((row) => (
                                    <TableRow key={row.office}>
                                        <TableCell>{row.office}</TableCell>
                                        <TableCell className="text-right">
                                            {row.legs}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {humanMinutes(row.average_minutes)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>
                </div>

                {canExport && (
                    <p className="text-xs text-muted-foreground">
                        Exports run immediately rather than in the background,
                        so they are capped at {limits.pdf.toLocaleString()} rows
                        for PDF and {limits.xlsx.toLocaleString()} for Excel.
                        Past that, narrow the range or use CSV.
                    </p>
                )}
            </div>
        </>
    );
}

function Stat({
    label,
    value,
    tone,
}: {
    label: string;
    value: number | string;
    tone?: 'danger';
}) {
    return (
        <div className="rounded-xl border p-4">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={`mt-1 text-2xl font-semibold ${
                    tone === 'danger' ? 'text-red-600 dark:text-red-400' : ''
                }`}
            >
                {value}
            </p>
        </div>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [{ title: 'Reports', href: reports.index() }],
};
