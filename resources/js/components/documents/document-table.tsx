import { Link } from '@inertiajs/react';
import { ToneBadge } from '@/components/documents/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import documents from '@/routes/documents';
import type { DocumentListItem } from '@/types';

/** Shared between the dashboards and the office queue so they cannot drift. */
export function DocumentTable({
    items,
    emptyMessage = 'Nothing here.',
}: {
    items: DocumentListItem[];
    emptyMessage?: string;
}) {
    return (
        <>
            {/*
                Phone: cards. The five columns need ~640px, so below `md`
                everything past "Title" is off-screen with no affordance --
                including the status, which is the reason to look at the list.
            */}
            <div className="rounded-xl border md:hidden">
                {items.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        {emptyMessage}
                    </p>
                ) : (
                    <ul className="divide-y">
                        {items.map((item) => (
                            <li key={item.id} className="p-4">
                                <Link
                                    href={documents.show(item.id)}
                                    className="block font-medium break-words hover:underline"
                                >
                                    {item.title}
                                </Link>
                                <span className="mt-0.5 block font-mono text-xs text-muted-foreground">
                                    {item.control_number}
                                </span>

                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    <ToneBadge tone={item.status_tone}>
                                        {item.status_label}
                                    </ToneBadge>
                                    {item.due_state !== 'none' && (
                                        <ToneBadge tone={item.due_state_tone}>
                                            {item.due_state_label}
                                        </ToneBadge>
                                    )}
                                </div>

                                <p className="mt-2 text-xs text-muted-foreground">
                                    Currently at{' '}
                                    <span className="font-medium text-foreground">
                                        {item.current_office ?? '—'}
                                    </span>
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="hidden rounded-xl border md:block">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Control number</TableHead>
                            <TableHead>Title</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Currently at</TableHead>
                            <TableHead>Deadline</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {items.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={5}
                                    className="py-8 text-center text-muted-foreground"
                                >
                                    {emptyMessage}
                                </TableCell>
                            </TableRow>
                        )}

                        {items.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell className="font-mono text-xs font-medium">
                                    <Link
                                        href={documents.show(item.id)}
                                        className="hover:underline"
                                    >
                                        {item.control_number}
                                    </Link>
                                </TableCell>
                                <TableCell className="max-w-64 truncate">
                                    {item.title}
                                </TableCell>
                                <TableCell>
                                    <ToneBadge tone={item.status_tone}>
                                        {item.status_label}
                                    </ToneBadge>
                                </TableCell>
                                <TableCell>
                                    {item.current_office ?? '—'}
                                </TableCell>
                                <TableCell>
                                    {item.due_state === 'none' ? (
                                        '—'
                                    ) : (
                                        <ToneBadge tone={item.due_state_tone}>
                                            {item.due_state_label}
                                        </ToneBadge>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </>
    );
}

export function StatCard({
    label,
    value,
    tone,
}: {
    label: string;
    value: number | string;
    tone?: 'default' | 'warning' | 'danger';
}) {
    const toneClass =
        tone === 'danger'
            ? 'text-red-600 dark:text-red-400'
            : tone === 'warning'
              ? 'text-amber-600 dark:text-amber-400'
              : '';

    return (
        <div className="rounded-xl border p-4">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${toneClass}`}>
                {value}
            </p>
        </div>
    );
}
