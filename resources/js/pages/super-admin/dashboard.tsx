import { Head, Link, router } from '@inertiajs/react';
import { Archive, Eye, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { PanelHeading } from '@/components/admin/panel-heading';
import { StatusPill } from '@/components/documents/status-pill';
import documents from '@/routes/documents';
import superAdmin from '@/routes/super-admin';
import type { DocumentListItem } from '@/types';

type Row = DocumentListItem & { uploaded_by: string | null };

type Props = {
    stats: {
        documents: number;
        open: number;
        overdue: number;
        offices: number;
        users: number;
    };
    documents: {
        data: Row[];
        filters: { q: string; status: string | null };
        from: number | null;
        to: number | null;
        total: number;
        current_page: number;
        last_page: number;
    };
};

/** §4's All Documents screen: the whole register, across every office. */
export default function AllDocuments({ stats, documents: page }: Props) {
    const [q, setQ] = useState(page.filters.q ?? '');
    const [selected, setSelected] = useState<number[]>([]);

    // Read through a ref rather than closed over: the filters object is new on
    // every response, so a useCallback keyed on it rebuilds `apply`, which
    // re-runs the debounce effect, which fires another request.
    const latest = useRef(page.filters);

    useEffect(() => {
        latest.current = page.filters;
    }, [page.filters]);

    const apply = useCallback(
        (next: Partial<{ q: string; status: string }>) => {
            router.get(
                superAdmin.dashboard.url(),
                { ...latest.current, ...next, page: undefined },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['documents'],
                },
            );
        },
        [],
    );

    const mounted = useRef(false);

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true;

            return;
        }

        const timer = setTimeout(() => apply({ q: q || undefined }), 300);

        return () => clearTimeout(timer);
    }, [q, apply]);

    /*
     * Selection is narrowed to what is on screen, rather than cleared by an
     * effect when the page changes.
     *
     * Same outcome -- a row you cannot see can never be acted on -- without
     * writing state during an effect, which double-renders and races the
     * navigation that caused it. Derived state should be derived.
     */
    const visible = selected.filter((id) =>
        page.data.some((row) => row.id === id),
    );

    const allShown =
        page.data.length > 0 && visible.length === page.data.length;

    return (
        <>
            <Head title="All Documents" />

            <PanelHeading />

            <section className="mt-6 rounded-xl bg-white p-6 shadow-sm">
                <h2 className="text-2xl font-extrabold tracking-tight text-navy">
                    All Documents
                </h2>

                <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                    <Stat label="Documents" value={stats.documents} />
                    <Stat label="Open" value={stats.open} />
                    <Stat label="Overdue" value={stats.overdue} />
                    <Stat label="Offices" value={stats.offices} />
                    <Stat label="Users" value={stats.users} />
                </dl>

                <div className="mt-6 flex flex-col gap-3 sm:flex-row">
                    <div className="relative min-w-0 flex-1">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#8A9AAE]"
                        />
                        <input
                            type="search"
                            value={q}
                            onChange={(event) => setQ(event.target.value)}
                            placeholder="Search every office"
                            aria-label="Search all documents by control number or title"
                            className="h-11 w-full rounded-lg border border-[#E4EAF3] pr-4 pl-12 text-[15px] text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none"
                        />
                    </div>

                    <label className="sr-only" htmlFor="status">
                        Filter by status
                    </label>
                    <select
                        id="status"
                        value={page.filters.status ?? ''}
                        onChange={(event) =>
                            apply({ status: event.target.value || undefined })
                        }
                        className="h-11 rounded-lg border border-[#E4EAF3] bg-white px-3 text-[15px] font-medium text-navy focus-visible:ring-2 focus-visible:ring-[#3B72C4] focus-visible:outline-none sm:w-52"
                    >
                        <option value="">All statuses</option>
                        {[
                            ['initiated', 'Initiated'],
                            ['under_review', 'Under Review'],
                            ['approved', 'Approved'],
                            ['returned', 'Returned'],
                            ['rejected', 'Rejected'],
                            ['completed', 'Completed'],
                        ].map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>

                {visible.length > 0 && <BulkBar ids={visible} />}

                {/* Phone: cards, so Status and the actions stay reachable. */}
                <ul className="mt-4 divide-y divide-[#EEF2F7] md:hidden">
                    {page.data.length === 0 && (
                        <li className="py-10 text-center text-sm text-copy">
                            No documents match these filters.
                        </li>
                    )}

                    {page.data.map((row) => (
                        <li key={row.id} className="py-4">
                            <Link
                                href={documents.show(row.id)}
                                className="block text-[15px] font-bold text-navy hover:underline"
                            >
                                {row.title}
                            </Link>
                            <p className="font-mono text-xs text-copy">
                                {row.control_number}
                            </p>
                            <p className="mt-1 text-sm text-copy">
                                {row.document_type ?? '—'} ·{' '}
                                {row.resting_office}
                            </p>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <StatusPill
                                    tone={row.status_tone}
                                    label={row.status_label}
                                    className="min-w-0 px-3 py-1.5 text-xs"
                                />
                                <RowActions id={row.id} />
                            </div>
                        </li>
                    ))}
                </ul>

                <div className="mt-4 hidden overflow-x-auto md:block">
                    <table className="w-full min-w-[720px] text-left">
                        <thead>
                            <tr className="border-b border-[#EEF2F7]">
                                <th scope="col" className="w-10 px-3 py-4">
                                    <input
                                        type="checkbox"
                                        checked={allShown}
                                        onChange={(event) =>
                                            setSelected(
                                                event.target.checked
                                                    ? page.data.map(
                                                          (row) => row.id,
                                                      )
                                                    : [],
                                            )
                                        }
                                        aria-label="Select every document on this page"
                                        className="size-4 rounded border-[#C9D4E4]"
                                    />
                                </th>
                                {[
                                    'Document Name',
                                    'Type',
                                    'Status',
                                    'Action',
                                ].map((heading) => (
                                    <th
                                        key={heading}
                                        scope="col"
                                        className="px-3 py-4 text-[15px] font-bold text-navy"
                                    >
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>

                        <tbody>
                            {page.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-3 py-12 text-center text-sm text-copy"
                                    >
                                        No documents match these filters.
                                    </td>
                                </tr>
                            )}

                            {page.data.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-b border-[#EEF2F7] last:border-0"
                                >
                                    <td className="px-3 py-4">
                                        <input
                                            type="checkbox"
                                            checked={visible.includes(row.id)}
                                            onChange={(event) =>
                                                setSelected((current) =>
                                                    event.target.checked
                                                        ? [...current, row.id]
                                                        : current.filter(
                                                              (id) =>
                                                                  id !== row.id,
                                                          ),
                                                )
                                            }
                                            aria-label={`Select ${row.control_number}`}
                                            className="size-4 rounded border-[#C9D4E4]"
                                        />
                                    </td>
                                    <td className="px-3 py-4">
                                        <Link
                                            href={documents.show(row.id)}
                                            className="font-bold text-navy hover:underline"
                                        >
                                            {row.title}
                                        </Link>
                                        <span className="mt-0.5 block font-mono text-xs text-copy">
                                            {row.control_number}
                                        </span>
                                    </td>
                                    <td className="px-3 py-4 text-sm text-copy">
                                        {row.document_type ?? '—'}
                                    </td>
                                    <td className="px-3 py-4">
                                        <StatusPill
                                            tone={row.status_tone}
                                            label={row.status_label}
                                        />
                                    </td>
                                    <td className="px-3 py-4">
                                        <RowActions id={row.id} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <nav
                    aria-label="Pagination"
                    className="mt-4 flex flex-wrap items-center justify-between gap-3"
                >
                    <p className="text-sm text-copy">
                        Showing {page.data.length} out of {page.total} documents
                    </p>

                    {page.last_page > 1 && (
                        <div className="flex flex-wrap gap-1">
                            {Array.from(
                                { length: page.last_page },
                                (_, index) => index + 1,
                            ).map((number) => (
                                <button
                                    key={number}
                                    type="button"
                                    aria-current={
                                        number === page.current_page
                                            ? 'page'
                                            : undefined
                                    }
                                    onClick={() =>
                                        router.get(
                                            superAdmin.dashboard.url(),
                                            { ...page.filters, page: number },
                                            {
                                                preserveState: true,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                    className={`min-w-9 rounded-md border px-3 py-1.5 text-sm font-bold transition ${
                                        number === page.current_page
                                            ? 'border-[#3B72C4] bg-[#3B72C4] text-white'
                                            : 'border-[#D8E3F2] text-navy hover:bg-[#E8F0FB]'
                                    }`}
                                >
                                    {number}
                                </button>
                            ))}
                        </div>
                    )}
                </nav>
            </section>
        </>
    );
}

/**
 * Per-row actions.
 *
 * The design's third action is Delete. It is Archive here, and deliberately:
 * this is a register of municipal documents with an append-only movement
 * ledger behind it, and destroying a row would take its history with it --
 * which is the one thing a document tracking system exists to prevent.
 * Archiving files a document away, removes it from the working lists, and can
 * be undone from the Archive screen. Nothing in the app hard-deletes a
 * document, so there is no honest way to label this button Delete.
 */
function RowActions({ id }: { id: number }) {
    return (
        <div className="flex flex-wrap gap-2">
            <Link
                href={documents.show(id)}
                className="inline-flex items-center gap-1.5 rounded-md bg-[#3B72C4] px-4 py-2 text-sm font-bold text-white no-underline transition hover:bg-[#31629F]"
            >
                <Eye aria-hidden="true" className="size-4" />
                View
            </Link>

            <Link
                href={documents.archive(id)}
                as="button"
                className="inline-flex items-center gap-1.5 rounded-md border border-[#D8E3F2] bg-white px-4 py-2 text-sm font-bold text-navy transition hover:bg-[#F2F6FC]"
            >
                <Archive aria-hidden="true" className="size-4" />
                Archive
            </Link>
        </div>
    );
}

/** Bulk archive, for the checkbox column the design shows. */
function BulkBar({ ids }: { ids: number[] }) {
    return (
        <div
            role="status"
            className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-[#E8F0FB] px-4 py-3"
        >
            <p className="text-sm font-bold text-navy">{ids.length} selected</p>

            <button
                type="button"
                onClick={() => {
                    // One request per document rather than a bulk endpoint:
                    // archiving writes a movement row and has to run through
                    // the same action, and same-origin requests are cheap next
                    // to a second code path that could drift from it.
                    ids.forEach((id) =>
                        router.post(
                            documents.archive.url(id),
                            {},
                            { preserveScroll: true },
                        ),
                    );
                }}
                className="inline-flex items-center gap-1.5 rounded-md bg-[#3B72C4] px-4 py-2 text-sm font-bold text-white transition hover:bg-[#31629F]"
            >
                <Archive aria-hidden="true" className="size-4" />
                Archive selected
            </button>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border border-[#E4EAF3] px-4 py-3">
            <dt className="text-xs font-semibold text-copy">{label}</dt>
            <dd className="mt-0.5 text-2xl font-extrabold text-navy">
                {value}
            </dd>
        </div>
    );
}

AllDocuments.layout = {
    breadcrumbs: [{ title: 'All Documents', href: superAdmin.dashboard() }],
};
