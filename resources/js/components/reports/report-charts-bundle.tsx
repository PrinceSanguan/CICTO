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
    exportButtons,
}: {
    monthlyProcessed: MonthPoint[];
    monthlyByStatus: MonthByStatus[];
    statusDistribution: StatusSlice[];
    processingTrend: TrendPoint[];
    exportButtons?: React.ReactNode;
}) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <section className="rounded-xl bg-white p-5 shadow-xl">
                <h2 className="mb-4 text-xl font-bold text-navy">
                    Monthly Documents Processed
                </h2>
                <MonthlyByStatusChart data={monthlyByStatus} />
            </section>

            <section className="rounded-xl bg-white p-5 shadow-xl">
                <h2 className="mb-4 text-xl font-bold text-navy">
                    Status Distribution
                </h2>
                <StatusPieChart data={statusDistribution} />

                {exportButtons && (
                    <div className="mt-4 flex flex-wrap justify-end gap-3">
                        {exportButtons}
                    </div>
                )}
            </section>

            <section className="rounded-xl bg-white p-5 shadow-xl lg:col-span-2">
                <h2 className="mb-1 text-xl font-bold text-navy">
                    Processing trend
                </h2>
                <p className="mb-4 text-sm text-copy">
                    Average days from registration to completion.
                </p>
                <ProcessingTrendChart data={processingTrend} />
            </section>
        </div>
    );
}
