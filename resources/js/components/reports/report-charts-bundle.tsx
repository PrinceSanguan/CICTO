import {
    MonthlyProcessedChart,
    ProcessingTrendChart,
    StatusDistributionChart,
} from '@/components/reports/report-charts';
import type {
    MonthPoint,
    StatusSlice,
    TrendPoint,
} from '@/components/reports/report-charts';

/**
 * The lazy-loaded boundary for §19's charts.
 *
 * Exists so the reports page has a single default export to `lazy()` — and so
 * recharts is reachable from exactly one import site, which is what keeps it in
 * its own chunk instead of the main bundle.
 */
export default function ReportCharts({
    monthlyProcessed,
    statusDistribution,
    processingTrend,
}: {
    monthlyProcessed: MonthPoint[];
    statusDistribution: StatusSlice[];
    processingTrend: TrendPoint[];
}) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <section className="rounded-xl border p-4">
                <h3 className="mb-3 text-sm font-semibold">
                    Documents processed per month
                </h3>
                <MonthlyProcessedChart data={monthlyProcessed} />
            </section>

            <section className="rounded-xl border p-4">
                <h3 className="mb-3 text-sm font-semibold">
                    Status distribution
                </h3>
                <StatusDistributionChart data={statusDistribution} />
            </section>

            <section className="rounded-xl border p-4 lg:col-span-2">
                <h3 className="mb-3 text-sm font-semibold">
                    Processing trend
                    <span className="ml-2 font-normal text-muted-foreground">
                        average days from registration to completion
                    </span>
                </h3>
                <ProcessingTrendChart data={processingTrend} />
            </section>
        </div>
    );
}
