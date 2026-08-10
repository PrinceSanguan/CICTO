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
 * The Admin Panel's Reports card: approved / pending / rejected per month.
 *
 * Imported through the lazy chart bundle, never directly — recharts is ~95 KB
 * gzipped and has no business in the main bundle on an LGU connection.
 */

export type AdminTrendPoint = {
    month: string;
    label: string;
    approved: number;
    pending: number;
    rejected: number;
};

const AXIS = { fontSize: 11, fill: 'currentColor' } as const;

const TOOLTIP_STYLE = {
    fontSize: 12,
    borderRadius: 8,
    border: '1px solid rgb(0 0 0 / 0.1)',
    background: 'var(--color-popover, #fff)',
    color: 'var(--color-popover-foreground, #111)',
} as const;

/** Matches the tile palette so a colour means one thing across the page. */
const SERIES = [
    { key: 'approved', name: 'Approved', colour: '#2F7BE0' },
    { key: 'pending', name: 'Pending', colour: '#EFC65B' },
    { key: 'rejected', name: 'Rejected', colour: '#D5342A' },
] as const;

export function AdminTrendChart({ data }: { data: AdminTrendPoint[] }) {
    return (
        <ResponsiveContainer width="100%" height={280}>
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
                    allowDecimals={false}
                />
                <Tooltip contentStyle={TOOLTIP_STYLE} />
                <Legend
                    iconType="circle"
                    wrapperStyle={{ fontSize: 12, paddingTop: 8 }}
                />
                {SERIES.map((series) => (
                    <Line
                        key={series.key}
                        type="monotone"
                        dataKey={series.key}
                        name={series.name}
                        stroke={series.colour}
                        strokeWidth={2.5}
                        dot={{ r: 3 }}
                        activeDot={{ r: 5 }}
                    />
                ))}
            </LineChart>
        </ResponsiveContainer>
    );
}
