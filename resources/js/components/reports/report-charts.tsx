import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Legend,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

/**
 * §19 charts.
 *
 * Recharts is pinned to 2.15.x on purpose — 3.x changed its internals and an
 * unpinned reinstall months from now would break tooltips silently.
 *
 * This module is loaded lazily by the reports page. Recharts is ~95 KB gzipped
 * and has no business in the main bundle on an LGU connection.
 */

export type MonthPoint = { month: string; label: string; count: number };
export type MonthByStatus = {
    month: string;
    label: string;
    pending: number;
    in_process: number;
    for_approval: number;
    completed: number;
    rejected: number;
};
export type TrendPoint = { month: string; label: string; days: number | null };
export type StatusSlice = { status: string; count: number };

const AXIS = { fontSize: 11, fill: 'currentColor' } as const;

const TOOLTIP_STYLE = {
    fontSize: 12,
    borderRadius: 8,
    border: '1px solid rgb(0 0 0 / 0.1)',
    background: 'var(--color-popover, #fff)',
    color: 'var(--color-popover-foreground, #111)',
} as const;

export function MonthlyProcessedChart({ data }: { data: MonthPoint[] }) {
    return (
        <ResponsiveContainer width="100%" height={240}>
            <BarChart
                data={data}
                margin={{ top: 8, right: 8, bottom: 0, left: -20 }}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    opacity={0.25}
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    tick={AXIS}
                    tickLine={false}
                    axisLine={false}
                />
                <YAxis
                    tick={AXIS}
                    tickLine={false}
                    axisLine={false}
                    allowDecimals={false}
                />
                <Tooltip
                    contentStyle={TOOLTIP_STYLE}
                    cursor={{ opacity: 0.08 }}
                />
                <Bar
                    dataKey="count"
                    name="Documents processed"
                    fill="var(--chart-1, #2563eb)"
                    radius={[4, 4, 0, 0]}
                />
            </BarChart>
        </ResponsiveContainer>
    );
}

/**
 * Horizontal bars, deliberately not a pie.
 *
 * Four categories whose magnitudes differ by an order of magnitude are
 * unreadable as a pie — the small ones become slivers with no label.
 */
export function StatusDistributionChart({ data }: { data: StatusSlice[] }) {
    return (
        <ResponsiveContainer width="100%" height={240}>
            <BarChart
                data={data}
                layout="vertical"
                margin={{ top: 8, right: 16, bottom: 0, left: 10 }}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    opacity={0.25}
                    horizontal={false}
                />
                <XAxis
                    type="number"
                    tick={AXIS}
                    tickLine={false}
                    axisLine={false}
                    allowDecimals={false}
                />
                <YAxis
                    type="category"
                    dataKey="status"
                    tick={AXIS}
                    tickLine={false}
                    axisLine={false}
                    width={78}
                />
                <Tooltip
                    contentStyle={TOOLTIP_STYLE}
                    cursor={{ opacity: 0.08 }}
                />
                <Bar
                    dataKey="count"
                    name="Documents"
                    fill="var(--chart-2, #059669)"
                    radius={[0, 4, 4, 0]}
                />
            </BarChart>
        </ResponsiveContainer>
    );
}

export function ProcessingTrendChart({ data }: { data: TrendPoint[] }) {
    return (
        <ResponsiveContainer width="100%" height={240}>
            <LineChart
                data={data}
                margin={{ top: 8, right: 8, bottom: 0, left: -20 }}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    opacity={0.25}
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    tick={AXIS}
                    tickLine={false}
                    axisLine={false}
                />
                <YAxis tick={AXIS} tickLine={false} axisLine={false} unit="d" />
                <Tooltip
                    contentStyle={TOOLTIP_STYLE}
                    // recharts types the value as ValueType, which includes
                    // arrays for stacked series. Narrow it here rather than
                    // declaring a signature the library will not accept.
                    formatter={(value) =>
                        value === null || value === undefined
                            ? '—'
                            : `${String(value)} days`
                    }
                />
                <Line
                    type="monotone"
                    dataKey="days"
                    name="Average days to complete"
                    stroke="var(--chart-3, #d97706)"
                    strokeWidth={2}
                    dot={{ r: 3 }}
                    // Months with no completions are gaps, not zeros. Plotting
                    // them as zero would claim same-day turnaround.
                    connectNulls={false}
                />
            </LineChart>
        </ResponsiveContainer>
    );
}

/**
 * The client's "Monthly Documents Processed" chart: five grouped bars a month.
 *
 * The palette is shared with the pie below so a colour means the same thing in
 * both, and every series carries a legend entry -- colour is never the only way
 * to tell them apart.
 */
export const REPORT_SERIES = [
    { key: 'pending', name: 'Pending', colour: '#EFC65B' },
    { key: 'in_process', name: 'In Process', colour: '#6FBF9A' },
    { key: 'for_approval', name: 'For Approval', colour: '#2F7BE0' },
    { key: 'completed', name: 'Completed', colour: '#DD7A4E' },
    { key: 'rejected', name: 'Rejected', colour: '#E0473C' },
] as const;

export function MonthlyByStatusChart({ data }: { data: MonthByStatus[] }) {
    return (
        <ResponsiveContainer width="100%" height={300}>
            <BarChart
                data={data}
                margin={{ top: 8, right: 8, bottom: 0, left: -20 }}
                barGap={2}
                barCategoryGap="18%"
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    opacity={0.25}
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    tick={AXIS}
                    tickLine={false}
                    axisLine={false}
                />
                <YAxis
                    tick={AXIS}
                    tickLine={false}
                    axisLine={false}
                    allowDecimals={false}
                />
                <Tooltip
                    contentStyle={TOOLTIP_STYLE}
                    cursor={{ opacity: 0.08 }}
                />
                <Legend
                    iconType="circle"
                    wrapperStyle={{ fontSize: 11, paddingTop: 8 }}
                />
                {REPORT_SERIES.map((series) => (
                    <Bar
                        key={series.key}
                        dataKey={series.key}
                        name={series.name}
                        fill={series.colour}
                        radius={[3, 3, 0, 0]}
                    />
                ))}
            </BarChart>
        </ResponsiveContainer>
    );
}

/** Status Distribution, as the pie the design asks for. */
export function StatusPieChart({ data }: { data: StatusSlice[] }) {
    const SLICE: Record<string, string> = {
        Pending: '#EFC65B',
        'In Process': '#6FBF9A',
        'For Approval': '#2F7BE0',
        Completed: '#DD7A4E',
        Rejected: '#E0473C',
    };

    const total = data.reduce((sum, slice) => sum + slice.count, 0);

    // A pie of nothing renders as an invisible dot with a legend, which reads
    // as a broken chart rather than an empty one.
    if (total === 0) {
        return (
            <p className="flex h-[300px] items-center justify-center text-sm text-muted-foreground">
                No documents in this period.
            </p>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={300}>
            <PieChart>
                <Tooltip contentStyle={TOOLTIP_STYLE} />
                <Legend
                    layout="vertical"
                    align="left"
                    verticalAlign="middle"
                    iconType="circle"
                    wrapperStyle={{ fontSize: 12 }}
                />
                <Pie
                    data={data}
                    dataKey="count"
                    nameKey="status"
                    innerRadius={0}
                    outerRadius={110}
                    stroke="#FFFFFF"
                    strokeWidth={2}
                >
                    {data.map((slice) => (
                        <Cell
                            key={slice.status}
                            fill={SLICE[slice.status] ?? '#9AA7B8'}
                        />
                    ))}
                </Pie>
            </PieChart>
        </ResponsiveContainer>
    );
}
