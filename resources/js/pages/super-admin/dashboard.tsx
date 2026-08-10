import { Head } from '@inertiajs/react';
import { DocumentTable, StatCard } from '@/components/documents/document-table';
import Heading from '@/components/heading';
import superAdmin from '@/routes/super-admin';
import type { DocumentListItem } from '@/types';

type Props = {
    stats: {
        documents: number;
        open: number;
        overdue: number;
        offices: number;
        users: number;
    };
    recent: DocumentListItem[];
};

/** §4 Super Admin Panel → All Documents. System-wide. */
export default function SuperAdminDashboard({ stats, recent }: Props) {
    return (
        <>
            <Head title="All Documents" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="All Documents"
                    description="Every document across every office."
                />

                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <StatCard label="Documents" value={stats.documents} />
                    <StatCard label="Open" value={stats.open} />
                    <StatCard
                        label="Overdue"
                        value={stats.overdue}
                        tone={stats.overdue > 0 ? 'danger' : 'default'}
                    />
                    <StatCard label="Offices" value={stats.offices} />
                    <StatCard label="Users" value={stats.users} />
                </div>

                <DocumentTable
                    items={recent}
                    emptyMessage="No documents yet."
                />
            </div>
        </>
    );
}

SuperAdminDashboard.layout = {
    breadcrumbs: [{ title: 'All Documents', href: superAdmin.dashboard() }],
};
