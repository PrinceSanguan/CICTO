import type {
    MonthByStatus,
    MonthPoint,
    StatusSlice,
    TrendPoint,
} from '@/components/reports/report-charts';
import {
    MonthlyByStatusChart,
    ProcessingTrendChart,
    StatusPieChart,
} from '@/components/reports/report-charts';

/**
 * The lazy-loaded boundary for §19's charts.
 *
 * Exists so the reports page has a single default export to `lazy()` — and so
 * recharts is reachable from exactly one import site, which is what keeps it in
 * its own chunk instead of the main bundle.
 */
export default function ReportCharts({
    monthlyByStatus,
    statusDistribution,
    processingTrend,
}: {
    monthlyProcessed: MonthPoint[];
    monthlyByStatus: MonthByStatus[];
    statusDistribution: StatusSlice[];
    processingTrend: TrendPoint[];
}) {
    return (
        // On paper: the two charts side by side and the trend beneath, as a
        // compact block that fits the first sheet with the headline figures.
        <div className="grid gap-4 lg:grid-cols-2 print:grid-cols-2 print:gap-3">
            <section className="rounded-xl bg-white p-5 shadow-xl print:break-inside-avoid print:rounded-lg print:border print:border-[#D8E3F2] print:p-3 print:shadow-none">
                <h2 className="mb-4 text-xl font-bold text-navy print:mb-1 print:text-sm">
                    Monthly Documents Processed
                </h2>
                <MonthlyByStatusChart data={monthlyByStatus} />
            </section>

            <section className="rounded-xl bg-white p-5 shadow-xl print:break-inside-avoid print:rounded-lg print:border print:border-[#D8E3F2] print:p-3 print:shadow-none">
                <h2 className="mb-4 text-xl font-bold text-navy print:mb-1 print:text-sm">
                    Status Distribution
                </h2>
                <StatusPieChart data={statusDistribution} />
            </section>

            <section className="rounded-xl bg-white p-5 shadow-xl lg:col-span-2 print:col-span-2 print:break-inside-avoid print:rounded-lg print:border print:border-[#D8E3F2] print:p-3 print:shadow-none">
                <h2 className="mb-1 text-xl font-bold text-navy print:text-sm">
                    Processing trend
                </h2>
                <p className="mb-4 text-sm text-copy print:mb-1 print:text-xs">
                    Average days from registration to completion.
                </p>
                <ProcessingTrendChart data={processingTrend} />
            </section>
        </div>
    );
}
