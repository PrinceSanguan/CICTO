import {
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

/**
 * A monthly line chart for any named set of series.
 *
 * The Super Admin analytics screen draws three of these with different lines,
 * and AdminTrendChart is the same chart with one fixed series list. Rather than
 * copy the axis, grid, tooltip and legend configuration per chart -- four
 * places for them to drift -- that configuration lives here once.
 *
 * Imported through a lazy chart bundle, never directly: recharts is ~95 KB
 * gzipped and has no business in the main bundle on an LGU connection.
 */

export type SeriesPoint = {
    month: string;
    label: string;
} & Record<string, string | number>;

export type Series = {
    key: string;
    name: string;
    colour: string;
};

const AXIS = { fontSize: 11, fill: 'currentColor' } as const;

const TOOLTIP_STYLE = {
    fontSize: 12,
    borderRadius: 8,
    border: '1px solid rgb(0 0 0 / 0.1)',
    background: 'var(--color-popover, #fff)',
    color: 'var(--color-popover-foreground, #111)',
} as const;

export function SeriesChart({
    data,
    series,
    height = 280,
}: {
    data: SeriesPoint[];
    series: readonly Series[];
    height?: number;
}) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <LineChart
                data={data}
                margin={{ top: 8, right: 16, bottom: 0, left: -16 }}
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
                    // Counts, so a half-document tick would be nonsense.
                    allowDecimals={false}
                />
                <Tooltip contentStyle={TOOLTIP_STYLE} />
                <Legend
                    iconType="circle"
                    wrapperStyle={{ fontSize: 12, paddingTop: 8 }}
                />
                {series.map((line) => (
                    <Line
                        key={line.key}
                        type="monotone"
                        dataKey={line.key}
                        name={line.name}
                        stroke={line.colour}
                        strokeWidth={2.5}
                        dot={{ r: 3 }}
                        activeDot={{ r: 5 }}
                    />
                ))}
            </LineChart>
        </ResponsiveContainer>
    );
}
