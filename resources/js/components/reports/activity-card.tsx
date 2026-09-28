import { Link } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    FileText,
    Search,
    UserRound,
} from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import documentRoutes from '@/routes/documents';
import activity from '@/routes/reports/activity';

type Tab = 'documents' | 'users';

type Page<T> = { data: T[]; page: number; last_page: number; total: number };

type DocumentRow = {
    id: number;
    control_number: string;
    title: string;
    type: string | null;
    status: string;
    actions: number;
    last_activity_at: string | null;
};

type UserRow = {
    id: number;
    name: string;
    office: string | null;
    actions: number;
    last_activity_at: string | null;
};

type DocumentStep = {
    id: number;
    action: string;
    action_label: string;
    actor: string | null;
    actor_office: string | null;
    office: string | null;
    at: string | null;
};

type UserStep = {
    id: number;
    action: string;
    action_label: string;
    office: string | null;
    at: string | null;
    document: {
        id: number;
        control_number: string;
        title: string;
        type: string | null;
    };
};

/** Actions that MOVE the folder: the office on the step is where it went. */
const MOVES = new Set(['forwarded', 'returned', 'resubmitted']);

// mm/dd/yyyy, as the client asked for this card (2026-09-25).
const dateFormat = new Intl.DateTimeFormat('en-US', {
    month: '2-digit',
    day: '2-digit',
    year: 'numeric',
});
const timeFormat = new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    minute: '2-digit',
});

function day(iso: string | null): string {
    return iso ? dateFormat.format(new Date(iso)) : '—';
}

function dayAndTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const at = new Date(iso);

    return `${dateFormat.format(at)} · ${timeFormat.format(at)}`;
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

async function getJson<T>(url: string, signal: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return (await response.json()) as T;
}

/**
 * §19's "User activity", by document and by person (client request,
 * 2026-09-25).
 *
 * It was one wide table -- staff, office, a count -- that scrolled sideways
 * and said nothing about what anybody did. The client asked for it by
 * document instead ("Affidavit, mm/dd/yyyy"), each one opening onto its
 * trail with the people who moved it, and for a second view beside it for one
 * person's activity. Rows stay folded until clicked so the card is not
 * crowded, ten to a page.
 *
 * Lists and trails come from ReportActivityController as the card needs them,
 * so the Reports page itself carries none of it.
 */
export function ActivityCard({ months }: { months: number }) {
    const [tab, setTab] = useState<Tab>('documents');
    const [query, setQuery] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const tabsId = useId();

    /*
        The answer is kept WITH the request it answers. While a newer request
        is out, the old answer no longer matches and the list shows as loading
        -- without resetting state inside the effect, and without a slow reply
        to an earlier search ever overwriting a faster one.
    */
    const requestKey = `${tab}|${search}|${page}|${months}`;
    const [result, setResult] = useState<{
        key: string;
        list: Page<DocumentRow | UserRow> | null;
        failed: boolean;
    } | null>(null);
    const current = result?.key === requestKey ? result : null;
    const list = current?.list ?? null;
    const failed = current?.failed ?? false;

    // Search as the person types, but not on every keystroke.
    useEffect(() => {
        const timer = window.setTimeout(() => {
            setSearch(query.trim());
            setPage(1);
        }, 300);

        return () => window.clearTimeout(timer);
    }, [query]);

    useEffect(() => {
        const controller = new AbortController();
        const options = { query: { months, page, q: search || undefined } };
        const url =
            tab === 'documents'
                ? activity.documents.url(options)
                : activity.users.url(options);

        getJson<Page<DocumentRow | UserRow>>(url, controller.signal)
            .then((answer) =>
                setResult({ key: requestKey, list: answer, failed: false }),
            )
            .catch((error: unknown) => {
                if ((error as Error).name !== 'AbortError') {
                    setResult({ key: requestKey, list: null, failed: true });
                }
            });

        return () => controller.abort();
    }, [requestKey, tab, search, page, months]);

    const switchTo = (next: Tab) => {
        if (next === tab) {
            return;
        }

        setTab(next);
        setPage(1);
    };

    return (
        <section className="overflow-hidden rounded-xl bg-white shadow-xl print:rounded-lg print:border print:border-[#D8E3F2] print:shadow-none">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4 print:px-3 print:py-2">
                <h3 className="text-sm font-semibold">
                    User activity
                    {/* The tabs do not print; the sheet says which view it is. */}
                    <span className="hidden font-normal text-copy print:inline">
                        {' '}
                        — {tab === 'documents' ? 'by document' : 'by user'},
                        last {plural(months, 'month', 'months')}
                        {search && `, matching “${search}”`}
                    </span>
                </h3>

                <div
                    role="tablist"
                    aria-label="Show activity"
                    className="inline-flex rounded-lg bg-[#EEF3FA] p-1 print:hidden"
                >
                    {(
                        [
                            ['documents', 'By document', FileText],
                            ['users', 'By user', UserRound],
                        ] as const
                    ).map(([value, label, Icon]) => (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            id={`${tabsId}-${value}`}
                            aria-selected={tab === value}
                            aria-controls={`${tabsId}-panel`}
                            onClick={() => switchTo(value)}
                            className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-bold transition ${
                                tab === value
                                    ? 'bg-white text-navy shadow-sm'
                                    : 'text-copy hover:text-navy'
                            }`}
                        >
                            <Icon aria-hidden="true" className="size-3.5" />
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="border-b px-4 py-3 print:hidden">
                <label className="relative block">
                    <span className="sr-only">
                        {tab === 'documents'
                            ? 'Search documents'
                            : 'Search staff'}
                    </span>
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-copy"
                    />
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={
                            tab === 'documents'
                                ? 'Search by type, control number or title'
                                : 'Search by name or office'
                        }
                        className="h-9 w-full rounded-md border border-[#D8E3F2] bg-white pr-3 pl-9 text-sm text-navy placeholder:text-copy focus:border-[#3B72C4] focus:outline-none"
                    />
                </label>
            </div>

            <div
                role="tabpanel"
                id={`${tabsId}-panel`}
                aria-labelledby={`${tabsId}-${tab}`}
            >
                {failed && (
                    <p className="px-4 py-8 text-center text-sm text-copy">
                        The activity could not be loaded. Refresh the page to
                        try again.
                    </p>
                )}

                {!failed && list === null && (
                    <div className="space-y-2 p-4" aria-busy="true">
                        {[0, 1, 2].map((row) => (
                            <Skeleton key={row} className="h-12 w-full" />
                        ))}
                    </div>
                )}

                {list !== null && list.data.length === 0 && (
                    <p className="px-4 py-8 text-center text-sm text-copy">
                        {search
                            ? 'Nothing matches that search in this period.'
                            : `No activity in the last ${plural(months, 'month', 'months')}.`}
                    </p>
                )}

                {list !== null && list.data.length > 0 && (
                    <ul className="divide-y divide-[#EEF2F7]">
                        {tab === 'documents'
                            ? (list.data as DocumentRow[]).map((row) => (
                                  <DocumentItem key={row.id} row={row} />
                              ))
                            : (list.data as UserRow[]).map((row) => (
                                  <UserItem
                                      key={row.id}
                                      row={row}
                                      months={months}
                                  />
                              ))}
                    </ul>
                )}

                {list !== null && list.last_page > 1 && (
                    <nav
                        aria-label="Activity pages"
                        className="flex items-center justify-between gap-3 border-t px-4 py-3 print:px-3 print:py-1.5"
                    >
                        <p className="text-xs text-copy">
                            {list.total}{' '}
                            {tab === 'documents'
                                ? list.total === 1
                                    ? 'document'
                                    : 'documents'
                                : list.total === 1
                                  ? 'person'
                                  : 'people'}{' '}
                            · page {list.page} of {list.last_page}
                        </p>
                        <div className="flex gap-1 print:hidden">
                            <PageButton
                                label="Previous page"
                                disabled={list.page <= 1}
                                onClick={() => setPage(list.page - 1)}
                            >
                                <ChevronLeft className="size-4" />
                            </PageButton>
                            <PageButton
                                label="Next page"
                                disabled={list.page >= list.last_page}
                                onClick={() => setPage(list.page + 1)}
                            >
                                <ChevronRight className="size-4" />
                            </PageButton>
                        </div>
                    </nav>
                )}
            </div>
        </section>
    );
}

function PageButton({
    label,
    disabled,
    onClick,
    children,
}: {
    label: string;
    disabled: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
            className="flex size-8 items-center justify-center rounded-md border border-[#D8E3F2] text-navy transition hover:bg-[#F2F6FC] disabled:opacity-40"
        >
            {children}
        </button>
    );
}

/**
 * One folded row and, once opened, its trail -- fetched the first time it is
 * opened and kept while the row stays on screen.
 */
function useTrail<T>(url: string) {
    const [open, setOpen] = useState(false);
    const [result, setResult] = useState<{ steps: T | null; failed: boolean }>({
        steps: null,
        failed: false,
    });

    useEffect(() => {
        if (!open || result.steps !== null || result.failed) {
            return;
        }

        const controller = new AbortController();

        getJson<T>(url, controller.signal)
            .then((steps) => setResult({ steps, failed: false }))
            .catch((error: unknown) => {
                if ((error as Error).name !== 'AbortError') {
                    setResult({ steps: null, failed: true });
                }
            });

        return () => controller.abort();
    }, [open, result.steps, result.failed, url]);

    // Opening again after a failure is the retry: clear it in the click, so
    // the effect above asks once more.
    const toggle = () => {
        setOpen((value) => !value);
        setResult((value) =>
            value.failed ? { steps: null, failed: false } : value,
        );
    };

    return { open, toggle, steps: result.steps, failed: result.failed };
}

function RowButton({
    open,
    onClick,
    controls,
    icon: Icon,
    title,
    subtitle,
    date,
    count,
}: {
    open: boolean;
    onClick: () => void;
    controls: string;
    icon: typeof FileText;
    title: string;
    subtitle: string;
    date: string;
    count: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-expanded={open}
            aria-controls={controls}
            className="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-[#F7FAFE] print:px-3 print:py-1.5"
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#E8F0FB] text-[#3B72C4] print:hidden">
                <Icon aria-hidden="true" className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
                {/* Wraps on a phone: a type like "Certificate of Closure
                    of Business" cut to "Certificate of Clo…" is no name. */}
                <span className="block text-sm font-bold text-navy sm:truncate print:text-xs">
                    {title}
                </span>
                <span className="block truncate text-xs text-copy print:text-[10px]">
                    {subtitle}
                </span>
            </span>
            <span className="shrink-0 text-right">
                <span className="block text-sm font-bold text-navy tabular-nums print:text-xs">
                    {date}
                </span>
                <span className="block text-xs text-copy print:text-[10px]">
                    {count}
                </span>
            </span>
            <ChevronDown
                aria-hidden="true"
                className={`size-4 shrink-0 text-copy transition-transform print:hidden ${open ? 'rotate-180' : ''}`}
            />
        </button>
    );
}

function TrailShell({
    id,
    failed,
    loading,
    children,
}: {
    id: string;
    failed: boolean;
    loading: boolean;
    children: React.ReactNode;
}) {
    return (
        <div
            id={id}
            className="bg-[#F9FBFE] px-4 pt-1 pb-4 sm:pl-16 print:bg-white print:px-3 print:pb-2 print:pl-8"
        >
            {failed && (
                <p className="py-3 text-sm text-copy">
                    The trail could not be loaded. Close and open it again.
                </p>
            )}
            {!failed && loading && (
                <div className="space-y-2 py-2" aria-busy="true">
                    <Skeleton className="h-9 w-full" />
                    <Skeleton className="h-9 w-3/4" />
                </div>
            )}
            {!failed && !loading && children}
        </div>
    );
}

function DocumentItem({ row }: { row: DocumentRow }) {
    const trailId = useId();
    const { open, toggle, steps, failed } = useTrail<{
        steps: DocumentStep[];
    }>(activity.document.url(row.id));

    return (
        <li>
            <RowButton
                open={open}
                onClick={toggle}
                controls={trailId}
                icon={FileText}
                title={row.type ?? row.title}
                subtitle={`${row.control_number} · ${row.title}`}
                date={day(row.last_activity_at)}
                count={`${plural(row.actions, 'action', 'actions')} · ${row.status}`}
            />

            {open && (
                <TrailShell
                    id={trailId}
                    failed={failed}
                    loading={steps === null}
                >
                    <ol className="relative mt-2 space-y-3 border-l-2 border-[#D8E3F2] pl-5 print:mt-1 print:space-y-1">
                        {steps?.steps.map((step) => (
                            <li
                                key={step.id}
                                className="relative print:break-inside-avoid"
                            >
                                <span
                                    aria-hidden="true"
                                    className="absolute top-1.5 -left-[27px] size-3 rounded-full bg-[#3B72C4] ring-4 ring-[#F9FBFE]"
                                />
                                <p className="text-sm text-navy print:text-[10px]">
                                    <span className="font-bold">
                                        {step.action_label}
                                    </span>
                                    <span className="text-copy">
                                        {' '}
                                        · {dayAndTime(step.at)}
                                    </span>
                                </p>
                                <p className="text-xs text-copy print:text-[10px]">
                                    by{' '}
                                    <span className="font-bold text-navy">
                                        {step.actor ?? 'a removed account'}
                                    </span>
                                    {step.actor_office &&
                                        ` (${step.actor_office})`}
                                    {step.office &&
                                        ` · ${MOVES.has(step.action) ? 'to' : 'at'} ${step.office}`}
                                </p>
                            </li>
                        ))}
                    </ol>

                    <Link
                        href={documentRoutes.show(row.id)}
                        className="mt-4 inline-flex items-center gap-1 text-sm font-bold text-link hover:underline print:hidden"
                    >
                        Open document
                        <ChevronRight aria-hidden="true" className="size-4" />
                    </Link>
                </TrailShell>
            )}
        </li>
    );
}

function UserItem({ row, months }: { row: UserRow; months: number }) {
    const trailId = useId();
    const { open, toggle, steps, failed } = useTrail<{
        steps: UserStep[];
        truncated: boolean;
    }>(activity.user.url(row.id, { query: { months } }));

    return (
        <li>
            <RowButton
                open={open}
                onClick={toggle}
                controls={trailId}
                icon={UserRound}
                title={row.name}
                subtitle={row.office ?? 'No office'}
                date={day(row.last_activity_at)}
                count={plural(row.actions, 'action', 'actions')}
            />

            {open && (
                <TrailShell
                    id={trailId}
                    failed={failed}
                    loading={steps === null}
                >
                    <ol className="mt-2 divide-y divide-[#EEF2F7]">
                        {steps?.steps.map((step) => (
                            <li
                                key={step.id}
                                className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-2 print:break-inside-avoid print:py-1"
                            >
                                <div className="min-w-0">
                                    <p className="text-sm text-navy print:text-[10px]">
                                        <span className="font-bold">
                                            {step.action_label}
                                        </span>{' '}
                                        <span className="text-copy">·</span>{' '}
                                        <Link
                                            href={documentRoutes.show(
                                                step.document.id,
                                            )}
                                            className="font-bold text-link hover:underline"
                                        >
                                            {step.document.type ??
                                                step.document.title}
                                        </Link>
                                    </p>
                                    <p className="truncate text-xs text-copy print:text-[10px]">
                                        {step.document.control_number} ·{' '}
                                        {step.document.title}
                                        {step.office &&
                                            ` · ${MOVES.has(step.action) ? 'to' : 'at'} ${step.office}`}
                                    </p>
                                </div>
                                <p className="shrink-0 text-xs text-copy tabular-nums print:text-[10px]">
                                    {dayAndTime(step.at)}
                                </p>
                            </li>
                        ))}
                    </ol>

                    {steps?.truncated && (
                        <p className="mt-2 text-xs text-copy">
                            Showing this person's latest {steps.steps.length}{' '}
                            actions in the period.
                        </p>
                    )}
                </TrailShell>
            )}
        </li>
    );
}
