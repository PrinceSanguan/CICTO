import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Download,
    FileSpreadsheet,
    Files,
    FileText,
    ShieldCheck,
} from 'lucide-react';
import { lazy, Suspense } from 'react';
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
type MonthByStatus = {
    month: string;
    label: string;
    pending: number;
    in_process: number;
    for_approval: number;
    completed: number;
    rejected: number;
};
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
    monthlyByStatus: MonthByStatus[];
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
    monthlyByStatus,
    statusDistribution,
    processingTrend,
    userActivity,
    officePerformance,
    months,
    canExport,
    limits,
}: Props) {
    /*
     * Rendered in the two places the design puts them: as full-size pills
     * beneath the tiles, and again inside the Status Distribution card.
     *
     * The in-card set is `compact`. Both used to render at the full size, and
     * three large pills do not fit across a half-width chart card -- the CSV
     * one wrapped and hung outside the card border.
     */
    const exportLinks = <ExportLinks />;

    return (
        <>
            <Head title="Reports" />

            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                            Reports &amp; Analytics
                        </h1>
                        <p className="mt-1 text-sm font-medium text-white/90">
                            Document activity over the last {months} months.
                        </p>
                    </div>

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
                    </div>
                </div>

                {/* §18 headline numbers, as the design's icon tiles. */}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Tile
                        icon={Files}
                        tint="#E8B84B"
                        label="Total Documents"
                        value={summary.total}
                    />
                    <Tile
                        icon={BarChart3}
                        tint="#3B72C4"
                        label="Monthly Processed"
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
                        label="Approved Rate"
                        // Never "0%" -- nothing decided is not the same as
                        // everything rejected.
                        value={
                            summary.approval_rate === null
                                ? '—'
                                : `${summary.approval_rate}%`
                        }
                    />
                </div>

                {/*
                    The export pair sits directly beneath the tiles, which is
                    where the design puts it -- and, incidentally, out from
                    under the decorative watermark that made the old header
                    cluster hard to read.
                */}
                {canExport && (
                    <div className="flex flex-wrap gap-3">{exportLinks}</div>
                )}

                <Suspense
                    fallback={<Skeleton className="h-64 w-full rounded-xl" />}
                >
                    <Charts
                        monthlyProcessed={monthlyProcessed}
                        monthlyByStatus={monthlyByStatus}
                        statusDistribution={statusDistribution}
                        processingTrend={processingTrend}
                        exportButtons={
                            canExport ? <ExportLinks compact /> : null
                        }
                    />
                </Suspense>

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* §19 artifact 4 — the one that is easy to forget */}
                    <section className="overflow-hidden rounded-xl bg-white shadow-xl">
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

                    <section className="overflow-hidden rounded-xl bg-white shadow-xl">
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

                {/* In its own card: the page is tall enough that this note
                    lands on the pale ground band, where white is unreadable. */}
                {canExport && (
                    <p className="rounded-xl bg-white p-4 text-xs text-copy shadow-xl">
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

/**
 * A headline figure from the design: tinted icon, label, value.
 *
 * The icon is decorative -- the label carries the meaning -- so it is hidden
 * from screen readers rather than being announced as "image".
 */
/**
 * The export pair, in the two places the design puts them.
 *
 * `compact` is the in-card set. Both used to render at full size, and three
 * large pills do not fit across a half-width chart card -- the CSV one wrapped
 * and hung outside the card border.
 */
function ExportLinks({ compact = false }: { compact?: boolean }) {
    const style = compact
        ? 'gap-2 px-3 py-2 text-xs shadow-sm'
        : 'gap-3 px-6 py-3 text-[15px] shadow-lg';

    const icon = compact ? 'size-4' : 'size-5';

    return (
        <>
            <a
                href={reports.export.url({ query: { format: 'pdf' } })}
                className={`inline-flex items-center rounded-lg bg-white font-bold text-navy no-underline transition hover:bg-[#F2F6FC] ${style}`}
            >
                <FileText
                    aria-hidden="true"
                    className={`${icon} text-[#D7373F]`}
                />
                {compact ? 'PDF' : 'Download PDF'}
            </a>

            <a
                href={reports.export.url({ query: { format: 'xlsx' } })}
                className={`inline-flex items-center rounded-lg bg-white font-bold text-navy no-underline transition hover:bg-[#F2F6FC] ${style}`}
            >
                <FileSpreadsheet
                    aria-hidden="true"
                    className={`${icon} text-[#1F7244]`}
                />
                {compact ? 'Excel' : 'Export Excel'}
            </a>

            {/*
                CSV is not in the design, and it stays anyway: it is the only
                export with no row ceiling, and the note at the foot of this
                page tells a records officer to reach for it once a range grows
                past what PDF and Excel can hold. Removing it would leave that
                advice pointing at nothing.
            */}
            <a
                href={reports.export.url({ query: { format: 'csv' } })}
                title="Plain CSV — opens in Excel, never runs out of memory"
                className={`inline-flex items-center rounded-lg bg-white font-bold text-navy no-underline transition hover:bg-[#F2F6FC] ${style}`}
            >
                <Download
                    aria-hidden="true"
                    className={`${icon} text-[#3B72C4]`}
                />
                CSV
            </a>
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

ReportsIndex.layout = {
    breadcrumbs: [{ title: 'Reports', href: reports.index() }],
};
