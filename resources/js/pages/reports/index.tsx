import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Download,
    FileSpreadsheet,
    Files,
    FileText,
    Printer,
    ShieldCheck,
} from 'lucide-react';
import { lazy, Suspense } from 'react';
import { PrintMasthead } from '@/components/documents/document-tracking';
import { ActivityCard } from '@/components/reports/activity-card';
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
import type { Auth } from '@/types';

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
    returned: number;
};
type TrendPoint = { month: string; label: string; days: number | null };
type StatusSlice = { status: string; count: number };
type OfficeRow = {
    id: number;
    office: string;
    legs: number;
    average_minutes: number;
};

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
    /** Admin and up: the "User activity" card loads its own lists. */
    showActivity: boolean;
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
    showActivity,
    officePerformance,
    months,
    canExport,
    limits,
}: Props) {
    // Whose figures these are, for the printed sheet: a page handed to a
    // department head has to say which office it covers.
    const { auth } = usePage<{ auth: Auth }>().props;
    const scope =
        auth.role === 'super_admin'
            ? 'All offices'
            : auth.role === 'admin'
              ? (auth.office?.name ?? 'Your office')
              : 'Documents you submitted';

    return (
        <>
            <Head title="Reports" />

            {/*
                Printable on one or two sheets of bond paper (client request,
                2026-09-25). Every `print:` class on this page serves that: the
                layout drops its own chrome, the cards lose their shadows for a
                thin border, the four figures share one row, the charts are
                redrawn at paper size (see ChartBox), and controls that mean
                nothing on paper -- the period picker, exports, search, paging
                buttons -- are left off. What prints is what is on screen: the
                activity list as it stands, with any trail that is open.
            */}
            <PrintMasthead />

            <div className="flex flex-col gap-4 print:gap-3 print:[print-color-adjust:exact]">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-3xl font-extrabold tracking-tight text-white sm:text-4xl print:text-xl print:text-navy">
                            Reports &amp; Analytics
                        </h1>
                        <p className="mt-1 text-sm font-medium text-white/90 print:mt-0 print:text-xs print:text-copy">
                            Document activity over the last {months} months.
                            <span className="hidden print:inline">
                                {' '}
                                Covering: {scope}.
                            </span>
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        <button
                            type="button"
                            onClick={() => window.print()}
                            className="inline-flex h-9 items-center gap-2 rounded-md bg-white px-3 text-sm font-bold text-navy shadow-sm transition hover:bg-[#F2F6FC]"
                        >
                            <Printer aria-hidden="true" className="size-4" />
                            Print
                        </button>
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
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 print:grid-cols-4 print:gap-2">
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
                    The exports sit directly beneath the tiles, which is where
                    the design puts them -- and, incidentally, out from under
                    the decorative watermark that made the old header cluster
                    hard to read. Only here: a second, smaller set inside the
                    Status Distribution card was removed as a duplicate
                    (client request, 2026-09-29).
                */}
                {canExport && (
                    <div className="flex flex-wrap gap-3 print:hidden">
                        <ExportLinks />
                    </div>
                )}

                <Suspense
                    fallback={<Skeleton className="h-64 w-full rounded-xl" />}
                >
                    <Charts
                        monthlyProcessed={monthlyProcessed}
                        monthlyByStatus={monthlyByStatus}
                        statusDistribution={statusDistribution}
                        processingTrend={processingTrend}
                    />
                </Suspense>

                {/*
                    §19 artifact 4 -- the one that is easy to forget. Full
                    width, by document or by person, folded until clicked
                    (client request, 2026-09-25): the old half-width table of
                    every person scrolled sideways and said nothing about what
                    anybody did.
                */}
                {showActivity && <ActivityCard months={months} />}

                {showActivity && (
                    // Allowed to run onto the next sheet: kept whole, a Super
                    // Admin's forty-odd offices jumped a page and left the one
                    // before it half empty. The rows themselves never split.
                    <section className="overflow-hidden rounded-xl bg-white shadow-xl print:rounded-lg print:border print:border-[#D8E3F2] print:shadow-none">
                        <h3 className="border-b p-4 text-sm font-semibold print:px-3 print:py-2">
                            Average time at each office
                            {/* What the two figures on each printed row are. */}
                            <span className="hidden font-normal text-copy print:inline">
                                {' '}
                                — documents handled · average time there
                            </span>
                        </h3>

                        {/*
                            On paper, two columns of plain rows instead of the
                            table: a Super Admin's list runs to every office,
                            and one row per line would take a sheet by itself.
                        */}
                        <ol className="hidden columns-2 gap-6 px-3 py-1.5 text-[9px] leading-tight print:block">
                            {officePerformance.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex break-inside-avoid justify-between gap-2 border-b border-[#EEF2F7] py-px"
                                >
                                    <span className="text-navy">
                                        {row.office}
                                    </span>
                                    <span className="shrink-0 text-copy tabular-nums">
                                        {row.legs} ·{' '}
                                        {humanMinutes(row.average_minutes)}
                                    </span>
                                </li>
                            ))}
                        </ol>

                        <Table className="print:hidden">
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
                                    <TableRow key={row.id}>
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
                )}

                {/* In its own card: the page is tall enough that this note
                    lands on the pale ground band, where white is unreadable. */}
                {canExport && (
                    <p className="rounded-xl bg-white p-4 text-xs text-copy shadow-xl print:hidden">
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
/** The document register's exports: PDF, Excel and CSV. */
function ExportLinks() {
    const style = 'gap-3 px-6 py-3 text-[15px] shadow-lg';
    const icon = 'size-5';

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
                Download PDF
            </a>

            <a
                href={reports.export.url({ query: { format: 'xlsx' } })}
                className={`inline-flex items-center rounded-lg bg-white font-bold text-navy no-underline transition hover:bg-[#F2F6FC] ${style}`}
            >
                <FileSpreadsheet
                    aria-hidden="true"
                    className={`${icon} text-[#1F7244]`}
                />
                Export Excel
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
        <div className="flex items-center gap-3 rounded-xl bg-white p-5 shadow-xl print:gap-2 print:rounded-lg print:border print:border-[#D8E3F2] print:p-2.5 print:shadow-none">
            <Icon
                aria-hidden="true"
                className="size-9 shrink-0 print:size-6"
                style={{ color: tint }}
                strokeWidth={1.75}
            />
            <div className="min-w-0">
                <p className="text-[15px] font-bold text-navy print:text-[10px]">
                    {label}
                </p>
                <p className="text-2xl font-extrabold text-navy tabular-nums print:text-base">
                    {value}
                </p>
            </div>
        </div>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [{ title: 'Reports', href: reports.index() }],
};
