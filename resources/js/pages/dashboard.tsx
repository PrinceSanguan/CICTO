import { Head, Link } from '@inertiajs/react';
import { DocumentTable, StatCard } from '@/components/documents/document-table';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import documents from '@/routes/documents';
import type { DocumentListItem } from '@/types';

type Props = {
    stats: {
        inbox: number;
        overdue: number;
        approaching: number;
        submitted: number;
    };
    recent: DocumentListItem[];
};

export default function Dashboard({ stats, recent }: Props) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title="Dashboard"
                        description="Documents that need your attention."
                    />
                    <Button asChild>
                        <Link href={documents.create()}>Submit Document</Link>
                    </Button>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Open" value={stats.inbox} />
                    <StatCard
                        label="Overdue"
                        value={stats.overdue}
                        tone={stats.overdue > 0 ? 'danger' : 'default'}
                    />
                    <StatCard
                        label="Due soon"
                        value={stats.approaching}
                        tone={stats.approaching > 0 ? 'warning' : 'default'}
                    />
                    <StatCard label="Submitted by me" value={stats.submitted} />
                </div>

                <DocumentTable
                    items={recent}
                    emptyMessage="No open documents right now."
                />
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
