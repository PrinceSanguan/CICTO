import type { AdminTrendPoint } from '@/components/admin/admin-trend-chart';
import { AdminTrendChart } from '@/components/admin/admin-trend-chart';

/**
 * The lazy-loaded boundary for the Admin Panel's chart.
 *
 * One default export so the page can `lazy()` it, and one import site for
 * recharts, which is what keeps the library in its own chunk. It shares that
 * chunk with the §19 reports charts because both reach recharts through a
 * lazy boundary — the library is downloaded once and cached.
 */
export default function AdminChartBundle({
    trend,
}: {
    trend: AdminTrendPoint[];
}) {
    return <AdminTrendChart data={trend} />;
}
