import { Head } from '@inertiajs/react';
import { lazy, Suspense } from 'react';
import { PanelHeading } from '@/components/admin/panel-heading';
import type { SeriesPoint } from '@/components/admin/series-chart';
import superAdmin from '@/routes/super-admin';

const Charts = lazy(
    () => import('@/components/admin/super-admin-chart-bundle'),
);

type Props = {
    trend: SeriesPoint[];
    activity: SeriesPoint[];
    processing: SeriesPoint[];
};

/**
 * §4's Reports & Analytics screen: the workflow trend across the whole
 * building, then user activity and processing throughput beside each other.
 *
 * Every line is counted from records the system already keeps -- documents,
 * file versions and the §21 security log. Nothing here is estimated, because
 * somebody will eventually make a staffing decision from this page.
 */
export default function SuperAdminReports({
    trend,
    activity,
    processing,
}: Props) {
    return (
        <>
            <Head title="Reports & Analytics" />

            <PanelHeading title="Super Admin Panel" />

            <Card title="Reports & Analytics" className="mt-6">
                <ChartFrame>
                    <Charts chart="workflow" data={trend} />
                </ChartFrame>
            </Card>

            <div className="mt-6 grid gap-6 xl:grid-cols-2">
                <Card title="User Activity">
                    <ChartFrame height={240}>
                        <Charts chart="activity" data={activity} height={240} />
                    </ChartFrame>

                    {/*
                        The caveat is on the page, not just in the code. Admin
                        Logins counts sign-ins by anyone who holds an admin role
                        NOW, so promoting somebody reclassifies their history --
                        which matters if this chart is ever quoted at anyone.
                    */}
                    <p className="mt-3 text-xs leading-relaxed text-copy">
                        Admin Logins counts sign-ins by accounts that currently
                        hold an Admin or Super Admin role, so a promotion
                        reclassifies that account&rsquo;s earlier sign-ins too.
                    </p>
                </Card>

                <Card title="Document Processing Trends">
                    <ChartFrame height={240}>
                        <Charts
                            chart="processing"
                            data={processing}
                            height={240}
                        />
                    </ChartFrame>

                    <p className="mt-3 text-xs leading-relaxed text-copy">
                        Counted by when each thing happened, not by a
                        document&rsquo;s current status — so one document can
                        appear as New in one month and Approved in another.
                    </p>
                </Card>
            </div>
        </>
    );
}

function ChartFrame({
    children,
    height = 280,
}: {
    children: React.ReactNode;
    height?: number;
}) {
    return (
        <Suspense
            fallback={
                <div
                    className="animate-pulse rounded-lg bg-[#EEF2F7]"
                    style={{ height }}
                />
            }
        >
            {children}
        </Suspense>
    );
}

function Card({
    title,
    children,
    className = '',
}: {
    title: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section
            className={`rounded-xl bg-white p-6 shadow-sm ${className}`.trim()}
        >
            <h2 className="text-xl font-extrabold tracking-tight text-navy">
                {title}
            </h2>
            <div className="mt-4">{children}</div>
        </section>
    );
}

SuperAdminReports.layout = {
    breadcrumbs: [
        { title: 'Reports & Analytics', href: superAdmin.reports.index() },
    ],
};
