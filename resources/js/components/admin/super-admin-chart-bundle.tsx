import type { SeriesPoint } from '@/components/admin/series-chart';
import { SeriesChart } from '@/components/admin/series-chart';

/**
 * The lazy-loaded boundary for §4's Reports & Analytics screen.
 *
 * One default export so the page can `lazy()` it, and one import site for
 * recharts. It shares the vendor chunk with the Admin Panel chart and the §19
 * reports charts, because all three reach recharts through a lazy boundary --
 * the library is downloaded once and cached.
 */

/** Colours are shared with the tiles, so a colour means one thing everywhere. */
const WORKFLOW = [
    { key: 'approved', name: 'Approved', colour: '#2F7BE0' },
    { key: 'pending', name: 'Pending', colour: '#EFC65B' },
    { key: 'rejected', name: 'Rejected', colour: '#D5342A' },
] as const;

const ACTIVITY = [
    { key: 'user_logins', name: 'User Logins', colour: '#2F7BE0' },
    { key: 'document_uploads', name: 'Document Uploads', colour: '#EFC65B' },
    { key: 'admin_logins', name: 'Admin Logins', colour: '#D5342A' },
] as const;

const PROCESSING = [
    { key: 'new', name: 'New', colour: '#2F7BE0' },
    { key: 'approved', name: 'Approved', colour: '#2FA36B' },
    { key: 'rejected', name: 'Rejected', colour: '#EFC65B' },
] as const;

export default function SuperAdminChartBundle({
    chart,
    data,
    height,
}: {
    chart: 'workflow' | 'activity' | 'processing';
    data: SeriesPoint[];
    height?: number;
}) {
    const series =
        chart === 'activity'
            ? ACTIVITY
            : chart === 'processing'
              ? PROCESSING
              : WORKFLOW;

    return <SeriesChart data={data} series={series} height={height} />;
}
