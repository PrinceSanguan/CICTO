import {
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
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
