import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ToneBadge } from '@/components/documents/status-badge';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import documents from '@/routes/documents';
import type {
    DocumentFilters,
    DocumentListItem,
    IdNameOption,
    Paginated,
    SelectOption,
} from '@/types';

type Props = {
    documents: Paginated<DocumentListItem>;
    filters: DocumentFilters;
    statuses: { value: string; label: string; statuses: string[] }[];
    priorities: SelectOption[];
    offices: IdNameOption[];
    documentTypes: IdNameOption[];
};

/** §8 Track Documents: search by control number, filter by status. */
export default function DocumentsIndex({
    documents: page,
    filters,
    statuses,
    priorities,
    offices,
}: Props) {
    const [q, setQ] = useState(filters.q ?? '');

    /**
     * Filter state lives in the URL, so a filtered view is bookmarkable and can
     * be sent to a colleague. replace:true keeps one history entry instead of
     * one per keystroke; `only` makes it a partial reload.
     *
     * `filters` is read through a ref rather than closed over. It is a prop
     * that changes on every response, so a useCallback keyed on it produces a
     * new `apply` each time -- which re-runs the debounce effect below, which
     * fires another request, which returns new filters. That is an endless
     * request loop, and it only shows up once the server starts echoing
     * filters back.
     */
    const latestFilters = useRef(filters);

    // Written in an effect, not during render: refs must not be mutated while
    // rendering. The timer below fires 300ms later, long after this has run.
    useEffect(() => {
        latestFilters.current = filters;
    }, [filters]);

    const apply = useCallback((next: Partial<DocumentFilters>) => {
        router.get(
            documents.index.url(),
            { ...latestFilters.current, ...next, page: undefined },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['documents', 'filters'],
            },
        );
    }, []);

    // Debounced search. Skips the first run so opening the page does not
    // immediately re-request what the server just rendered.
    const mounted = useRef(false);

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;

            return;
        }

        const timer = setTimeout(() => apply({ q: q || undefined }), 300);

        return () => clearTimeout(timer);
    }, [q, apply]);

    return (
        <>
            <Head title="Track Documents" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title="Track Documents"
                        description="Search by control number and filter by status."
                    />
                    <Button asChild>
                        <Link href={documents.create()}>Submit Document</Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-64 flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder="Control number or title…"
                            className="pl-8"
                            aria-label="Search documents"
                        />
                    </div>

                    <select
                        value={filters.status ?? ''}
                        onChange={(event) =>
                            apply({ status: event.target.value || undefined })
                        }
                        aria-label="Filter by status"
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">All statuses</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters.priority ?? ''}
                        onChange={(event) =>
                            apply({ priority: event.target.value || undefined })
                        }
                        aria-label="Filter by priority"
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Any priority</option>
                        {priorities.map((priority) => (
                            <option key={priority.value} value={priority.value}>
                                {priority.label}
                            </option>
                        ))}
                    </select>

                    <select
                        value={String(filters.office_id ?? '')}
                        onChange={(event) =>
                            apply({
                                office_id: event.target.value || undefined,
                            })
                        }
                        aria-label="Filter by holding office"
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Any office</option>
                        {offices.map((office) => (
                            <option key={office.id} value={office.id}>
                                {office.name}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters.due ?? ''}
                        onChange={(event) =>
                            apply({ due: event.target.value || undefined })
                        }
                        aria-label="Filter by deadline"
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Any deadline</option>
                        <option value="overdue">Overdue</option>
                        <option value="approaching">Due soon</option>
                    </select>
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Control number</TableHead>
                                <TableHead>Title</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Currently at</TableHead>
                                <TableHead>Priority</TableHead>
                                <TableHead>Deadline</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {page.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        No documents match these filters.
                                    </TableCell>
                                </TableRow>
                            )}

                            {page.data.map((document) => (
                                <TableRow key={document.id}>
                                    <TableCell className="font-mono text-xs font-medium">
                                        <Link
                                            href={documents.show(document.id)}
                                            className="hover:underline"
                                        >
                                            {document.control_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="max-w-72 truncate">
                                        {document.title}
                                    </TableCell>
                                    <TableCell>
                                        <ToneBadge tone={document.status_tone}>
                                            {document.status_label}
                                        </ToneBadge>
                                    </TableCell>
                                    <TableCell>
                                        {document.current_office ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <ToneBadge
                                            tone={document.priority_tone}
                                        >
                                            {document.priority_label}
                                        </ToneBadge>
                                    </TableCell>
                                    <TableCell>
                                        {document.due_state === 'none' ? (
                                            '—'
                                        ) : (
                                            <ToneBadge
                                                tone={document.due_state_tone}
                                            >
                                                {document.due_state_label}
                                            </ToneBadge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                {page.last_page > 1 && (
                    <nav
                        className="flex flex-wrap items-center gap-1"
                        aria-label="Pagination"
                    >
                        {page.links.map((link, index) => (
                            <Button
                                key={index}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                disabled={!link.url}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </nav>
                )}
            </div>
        </>
    );
}

DocumentsIndex.layout = {
    breadcrumbs: [{ title: 'Track Documents', href: documents.index() }],
};
