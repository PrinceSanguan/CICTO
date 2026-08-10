import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Download,
    Eye,
    Files,
    MoreVertical,
    Search,
    XCircle,
} from 'lucide-react';
import { lazy, Suspense, useEffect, useMemo, useRef, useState } from 'react';
import type { TileTone } from '@/components/admin/stat-tile';
import { StatTile } from '@/components/admin/stat-tile';
import { ToneBadge } from '@/components/documents/status-badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import admin from '@/routes/admin';
import documents from '@/routes/documents';
import type { DocumentListItem } from '@/types';

const AdminChartBundle = lazy(
    () => import('@/components/admin/admin-chart-bundle'),
);

type AdminRow = DocumentListItem & {
    uploaded_by: string | null;
    updated_at: string | null;
    bucket: 'pending' | 'approved' | 'rejected';
    current_file_id: number | null;
};

type Props = {
    office: { id: number; code: string; name: string } | null;
    filters: { q: string; sort: string; status: string | null };
    stats: {
        total: number;
        pending: number;
        approved: number;
        rejected: number;
    };
    documents: {
        data: AdminRow[];
        from: number | null;
        to: number | null;
        total: number;
        current_page: number;
        last_page: number;
    };
    trend: {
        month: string;
        label: string;
        approved: number;
        pending: number;
        rejected: number;
    }[];
    pending: AdminRow[];
};

/** §4 Admin Panel → Document Management, scoped to the admin's own office. */
export default function AdminDashboard({
    office,
    filters,
    stats,
    documents: page,
    trend,
    pending,
}: Props) {
    return (
        <>
            <Head title="Admin Panel" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 lg:p-6">
                <header>
                    <h1 className="text-2xl font-bold text-navy">
                        Admin Panel
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {office
                            ? `${office.code} — ${office.name}`
                            : 'You have not been assigned to an office yet, so you can only see what you submitted.'}
                    </p>
                    <hr className="mt-4 border-hairline" />
                </header>

                <DocumentManagement
                    stats={stats}
                    filters={filters}
                    page={page}
                />

                <ReportsCard trend={trend} />

                <PendingCard rows={pending} />
            </div>
        </>
    );
}

function Card({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-2xl bg-card p-5 shadow-sm ring-1 ring-hairline">
            <h2 className="mb-4 text-lg font-bold text-navy">{title}</h2>
            {children}
        </section>
    );
}

function DocumentManagement({
    stats,
    filters,
    page,
}: {
    stats: Props['stats'];
    filters: Props['filters'];
    page: Props['documents'];
}) {
    const tiles: {
        tone: TileTone;
        label: string;
        value: number;
        icon: typeof Files;
        bucket: string | null;
    }[] = [
        {
            tone: 'total',
            label: 'Total Documents',
            value: stats.total,
            icon: Files,
            bucket: null,
        },
        {
            tone: 'pending',
            label: 'Pending Documents',
            value: stats.pending,
            icon: Clock,
            bucket: 'pending',
        },
        {
            tone: 'approved',
            label: 'Approved Documents',
            value: stats.approved,
            icon: CheckCircle2,
            bucket: 'approved',
        },
        {
            tone: 'rejected',
            label: 'Rejected Documents',
            value: stats.rejected,
            icon: XCircle,
            bucket: 'rejected',
        },
    ];

    const apply = (changes: Record<string, string | null>) => {
        router.get(
            admin.dashboard().url,
            {
                q: filters.q || undefined,
                sort: filters.sort,
                status: filters.status ?? undefined,
                ...changes,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Card title="Document Management">
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {tiles.map((tile) => (
                        <StatTile
                            key={tile.label}
                            tone={tile.tone}
                            label={tile.label}
                            value={tile.value}
                            icon={tile.icon}
                            active={filters.status === tile.bucket}
                            onSelect={() =>
                                apply({
                                    // Clicking the active tile clears the
                                    // filter, so a tile is never a one-way door.
                                    status:
                                        filters.status === tile.bucket
                                            ? null
                                            : tile.bucket,
                                })
                            }
                        />
                    ))}
                </div>
            </Card>

            <section className="rounded-2xl bg-card p-5 shadow-sm ring-1 ring-hairline">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <SearchBox
                        initial={filters.q}
                        onSearch={(q) => apply({ q: q || null })}
                    />

                    <Select
                        value={filters.sort}
                        onValueChange={(sort) => apply({ sort })}
                    >
                        <SelectTrigger className="w-[190px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="priority">
                                Priority (urgent first)
                            </SelectItem>
                            <SelectItem value="updated">
                                Date Updated
                            </SelectItem>
                            <SelectItem value="created">
                                Date Submitted
                            </SelectItem>
                            <SelectItem value="title">Title (A–Z)</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="mt-4 overflow-x-auto">
                    <DocumentRows rows={page.data} withActions />
                </div>

                <Pagination
                    page={page}
                    onGo={(n) => apply({ page: String(n) })}
                />
            </section>
        </>
    );
}

/**
 * Submitting the form searches immediately, which is what a keyboard user
 * expects from Enter; typing is debounced.
 */
function SearchBox({
    initial,
    onSearch,
}: {
    initial: string;
    onSearch: (value: string) => void;
}) {
    const [value, setValue] = useState(initial);
    const [lastInitial, setLastInitial] = useState(initial);

    // React's documented "adjust state when a prop changes" pattern: done
    // during render rather than in an effect, so there is no extra commit and
    // no flash of the stale term. Re-syncs when the server sends a different
    // filter back -- a tile click, or the back button.
    if (initial !== lastInitial) {
        setLastInitial(initial);
        setValue(initial);
    }

    // Held in a ref because the parent re-creates this callback every render;
    // depending on it directly would restart the debounce on each keystroke and
    // the search would never fire.
    const onSearchRef = useRef(onSearch);

    useEffect(() => {
        onSearchRef.current = onSearch;
    }, [onSearch]);

    // Debounced so typing does not fire a request per keystroke.
    useEffect(() => {
        if (value === initial) {
            return;
        }

        const timer = setTimeout(() => onSearchRef.current(value), 350);

        return () => clearTimeout(timer);
    }, [value, initial]);

    return (
        <form
            role="search"
            onSubmit={(event) => {
                event.preventDefault();
                onSearch(value);
            }}
            className="relative w-full sm:max-w-xs"
        >
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                type="search"
                value={value}
                onChange={(event) => setValue(event.target.value)}
                placeholder="Search documents…"
                aria-label="Search documents"
                className="rounded-full pl-9"
            />
        </form>
    );
}

function DocumentRows({
    rows,
    withActions = false,
}: {
    rows: AdminRow[];
    withActions?: boolean;
}) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Document Title</TableHead>
                    <TableHead>Uploaded By</TableHead>
                    <TableHead>Date Updated</TableHead>
                    <TableHead>Status</TableHead>
                    {withActions && (
                        <TableHead className="text-right">Actions</TableHead>
                    )}
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.length === 0 && (
                    <TableRow>
                        <TableCell
                            colSpan={withActions ? 5 : 4}
                            className="py-10 text-center text-muted-foreground"
                        >
                            No documents match this view.
                        </TableCell>
                    </TableRow>
                )}

                {rows.map((row) => (
                    <TableRow key={row.id}>
                        <TableCell className="max-w-72">
                            <Link
                                href={documents.show(row.id)}
                                className="font-medium hover:underline"
                            >
                                {row.title}
                            </Link>
                            <span className="block font-mono text-xs text-muted-foreground">
                                {row.control_number}
                            </span>
                        </TableCell>
                        <TableCell className="text-sm">
                            {row.uploaded_by ?? '—'}
                        </TableCell>
                        <TableCell className="text-sm whitespace-nowrap">
                            <FormattedDate iso={row.updated_at} />
                        </TableCell>
                        <TableCell>
                            <ToneBadge tone={row.status_tone}>
                                {row.status_label}
                            </ToneBadge>
                        </TableCell>
                        {withActions && (
                            <TableCell>
                                <RowActions row={row} />
                            </TableCell>
                        )}
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * Rendered client-side from the ISO string so every reader sees the date in
 * their own locale, rather than whatever the server happened to format.
 */
function FormattedDate({ iso }: { iso: string | null }) {
    const text = useMemo(() => {
        if (!iso) {
            return '—';
        }

        return new Date(iso).toLocaleDateString(undefined, {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        });
    }, [iso]);

    return <>{text}</>;
}

function RowActions({ row }: { row: AdminRow }) {
    return (
        <div className="flex items-center justify-end gap-1">
            <Link
                href={documents.show(row.id)}
                title={`Open ${row.control_number}`}
                aria-label={`Open ${row.control_number}`}
                className="rounded-md border p-1.5 text-muted-foreground transition hover:bg-accent hover:text-foreground"
            >
                <Eye className="size-4" />
            </Link>

            {row.current_file_id === null ? (
                <span
                    title="No file to download"
                    aria-label={`${row.control_number} has no downloadable file`}
                    className="rounded-md border p-1.5 text-muted-foreground/40"
                >
                    <Download className="size-4" />
                </span>
            ) : (
                <a
                    href={
                        documents.files.download({
                            document: row.id,
                            file: row.current_file_id,
                        }).url
                    }
                    title={`Download the current file of ${row.control_number}`}
                    aria-label={`Download the current file of ${row.control_number}`}
                    className="rounded-md border p-1.5 text-muted-foreground transition hover:bg-accent hover:text-foreground"
                >
                    <Download className="size-4" />
                </a>
            )}

            <DropdownMenu>
                <DropdownMenuTrigger
                    aria-label={`More actions for ${row.control_number}`}
                    className="rounded-md border p-1.5 text-muted-foreground transition hover:bg-accent hover:text-foreground"
                >
                    <MoreVertical className="size-4" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem asChild>
                        <Link href={documents.show(row.id)}>Open document</Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link
                            href={documents.labels.print({
                                query: { 'ids[]': row.id },
                            })}
                        >
                            Print QR label
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function Pagination({
    page,
    onGo,
}: {
    page: Props['documents'];
    onGo: (n: number) => void;
}) {
    if (page.total === 0) {
        return null;
    }

    // A window around the current page, so 200 pages does not render 200
    // buttons. The first and last are always reachable.
    const numbers: (number | 'gap')[] = [];
    const window = 1;

    for (let n = 1; n <= page.last_page; n++) {
        const near = Math.abs(n - page.current_page) <= window;

        if (n === 1 || n === page.last_page || near) {
            numbers.push(n);
        } else if (numbers[numbers.length - 1] !== 'gap') {
            numbers.push('gap');
        }
    }

    return (
        <nav
            aria-label="Pagination"
            className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <p className="text-xs text-muted-foreground">
                Showing {page.from ?? 0}–{page.to ?? 0} of {page.total}{' '}
                {page.total === 1 ? 'document' : 'documents'}
            </p>

            <div className="flex items-center gap-1">
                <PageButton
                    label="Previous page"
                    disabled={page.current_page <= 1}
                    onClick={() => onGo(page.current_page - 1)}
                >
                    <ChevronLeft className="size-4" />
                </PageButton>

                {numbers.map((n, index) =>
                    n === 'gap' ? (
                        <span
                            key={`gap-${index}`}
                            className="px-1 text-xs text-muted-foreground"
                        >
                            …
                        </span>
                    ) : (
                        <PageButton
                            key={n}
                            label={`Page ${n}`}
                            current={n === page.current_page}
                            onClick={() => onGo(n)}
                        >
                            {n}
                        </PageButton>
                    ),
                )}

                <PageButton
                    label="Next page"
                    disabled={page.current_page >= page.last_page}
                    onClick={() => onGo(page.current_page + 1)}
                >
                    <ChevronRight className="size-4" />
                </PageButton>
            </div>
        </nav>
    );
}

function PageButton({
    children,
    label,
    onClick,
    disabled = false,
    current = false,
}: {
    children: React.ReactNode;
    label: string;
    onClick: () => void;
    disabled?: boolean;
    current?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            aria-current={current ? 'page' : undefined}
            className={`flex size-8 items-center justify-center rounded-md border text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-40 ${
                current ? 'border-brand bg-brand text-white' : 'hover:bg-accent'
            }`}
        >
            {children}
        </button>
    );
}

function ReportsCard({ trend }: { trend: Props['trend'] }) {
    return (
        <Card title="Reports">
            <Suspense
                fallback={
                    <div className="h-[280px] animate-pulse rounded-lg bg-muted" />
                }
            >
                <AdminChartBundle trend={trend} />
            </Suspense>
        </Card>
    );
}

function PendingCard({ rows }: { rows: AdminRow[] }) {
    return (
        <Card title="Pending Document">
            <div className="overflow-x-auto">
                <DocumentRows rows={rows} />
            </div>
        </Card>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [{ title: 'Admin Panel', href: admin.dashboard() }],
};
