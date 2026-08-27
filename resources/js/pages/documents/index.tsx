import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { DocumentCards } from '@/components/documents/document-cards';
import { DocumentQrButton } from '@/components/documents/document-qr-button';
import { StatusPill } from '@/components/documents/status-pill';
import documents from '@/routes/documents';
import type { DocumentFilters, DocumentListItem, Paginated } from '@/types';

type Props = {
    documents: Paginated<DocumentListItem>;
    filters: DocumentFilters;
    statuses: { value: string; label: string; statuses: string[] }[];
};

/** §8 Track Documents: search by control number, filter by status. */
export default function DocumentsIndex({
    documents: page,
    filters,
    statuses,
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

            <div className="flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                    Track Documents
                </h1>

                {/*
                    Navy, not blue. This is the one primary action sitting
                    directly on the hero gradient, and every blue tried here has
                    blended into it.

                    #3B72C4 -- the app's action colour everywhere else, where
                    the ground is a white card -- is rgb(59,114,196) against a
                    gradient starting at rgb(45,111,203): same hue, near
                    identical value, so it read as a faint rectangle. #3B6FE0
                    replaced it and pushed saturation ~15% on the theory that
                    chroma would do the separating. The client screenshotted it
                    again on 2026-08-27, so it did not.

                    The thing two blues of one hue cannot differ in enough is
                    LIGHTNESS, which is what the eye reads an edge from. --color-navy
                    is L~16% against the gradient's L~45%, about 3.1:1 as a
                    non-text boundary, where #3B6FE0 was under 1.2:1. It is
                    already the palette's darkest brand colour, so this borrows
                    a token rather than inventing a competing one -- and it
                    cannot be confused with the white Search control below it
                    the way a white button would have been.
                */}
                <Link
                    href={documents.create()}
                    className="flex items-center gap-2 rounded-lg bg-navy px-5 py-3 text-sm font-bold text-white shadow-lg transition duration-200 ease-out hover:bg-[#232c73] active:scale-[0.98]"
                >
                    <Plus className="size-5" />
                    Submit Document
                </Link>
            </div>

            <section className="mt-6 overflow-hidden rounded-xl shadow-xl">
                {/* Search bar, on the deeper blue band from the design. */}
                <div className="bg-[#3F6FBE] p-4">
                    <form
                        role="search"
                        onSubmit={(event) => {
                            event.preventDefault();
                            apply({ q: q || undefined });
                        }}
                        className="flex flex-col gap-3 sm:flex-row"
                    >
                        <div className="relative flex-1">
                            <Search
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#8A9AAE]"
                            />
                            <input
                                type="search"
                                value={q}
                                onChange={(event) => setQ(event.target.value)}
                                placeholder="Search by Control Number"
                                aria-label="Search by control number or title"
                                className="h-12 w-full rounded-lg border-0 bg-white pr-4 pl-12 text-[15px] text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            />
                        </div>

                        <button
                            type="submit"
                            className="h-12 rounded-lg bg-white px-8 text-lg font-bold text-link transition hover:bg-[#F2F6FC] sm:w-40"
                        >
                            Search
                        </button>

                        <label className="sr-only" htmlFor="status">
                            Filter by status
                        </label>
                        <select
                            id="status"
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                apply({
                                    status: event.target.value || undefined,
                                })
                            }
                            className="h-12 rounded-lg border-0 bg-white px-4 text-lg font-bold text-link focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none sm:w-56"
                        >
                            <option value="">Status:</option>
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </form>
                </div>

                {/* Phone: cards, so Status and the View button are reachable. */}
                <div className="bg-white md:hidden">
                    <DocumentCards items={page.data} />
                </div>

                <div className="hidden overflow-x-auto bg-white md:block">
                    <table className="w-full min-w-[720px] text-left">
                        <thead className="bg-[#E8F0FB]">
                            <tr>
                                {[
                                    'Control No.',
                                    'Title',
                                    'Department',
                                    'Status',
                                    'Date',
                                    'Action',
                                ].map((heading) => (
                                    <th
                                        key={heading}
                                        scope="col"
                                        className="px-5 py-4 text-center text-[15px] font-bold text-navy first:text-left"
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
                                        colSpan={6}
                                        className="px-5 py-12 text-center text-sm text-copy"
                                    >
                                        No documents match these filters.
                                    </td>
                                </tr>
                            )}

                            {page.data.map((document) => (
                                <tr
                                    key={document.id}
                                    className="border-t border-[#EEF2F7]"
                                >
                                    <td className="px-5 py-4 font-mono text-sm font-semibold text-navy">
                                        <Link
                                            href={documents.show(document.id)}
                                            className="hover:underline"
                                        >
                                            {document.control_number}
                                        </Link>
                                    </td>
                                    <td className="max-w-64 truncate px-5 py-4 text-center text-[15px] font-bold text-navy">
                                        {document.title}
                                    </td>
                                    <td className="px-5 py-4 text-center text-[15px] font-bold text-navy">
                                        {document.resting_office ?? '—'}
                                    </td>
                                    <td className="px-5 py-4 text-center">
                                        <StatusPill
                                            tone={document.status_tone}
                                            label={document.status_label}
                                        />
                                    </td>
                                    <td className="px-5 py-4 text-center text-sm whitespace-nowrap text-copy">
                                        <FormattedDate
                                            iso={document.created_at}
                                        />
                                    </td>
                                    <td className="px-5 py-4">
                                        {/*
                                            The QR sits beside View rather than
                                            in a column of its own: it acts on
                                            the same row and the header already
                                            calls this one "Action". A seventh
                                            column would also push the table
                                            past its min-w-[720px] and put the
                                            whole thing back into horizontal
                                            scroll at `md`.
                                        */}
                                        <div className="flex items-center justify-center gap-2">
                                            <Link
                                                href={documents.show(
                                                    document.id,
                                                )}
                                                className="inline-block rounded-md bg-[#3B72C4] px-7 py-2.5 text-sm font-bold text-white transition duration-200 ease-out hover:bg-[#31629F] active:scale-[0.97]"
                                            >
                                                View
                                            </Link>

                                            <DocumentQrButton
                                                id={document.id}
                                                controlNumber={
                                                    document.control_number
                                                }
                                            />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {page.last_page > 1 && (
                    <nav
                        aria-label="Pagination"
                        className="flex flex-wrap items-center justify-center gap-1 border-t border-[#EEF2F7] bg-white px-4 py-3"
                    >
                        {page.links.map((link, index) => (
                            <button
                                key={index}
                                type="button"
                                disabled={!link.url}
                                aria-current={link.active ? 'page' : undefined}
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
                                className={`min-w-9 rounded-md px-3 py-1.5 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-40 ${
                                    link.active
                                        ? 'bg-[#3B72C4] text-white'
                                        : 'text-navy hover:bg-[#E8F0FB]'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </nav>
                )}
            </section>
        </>
    );
}

/** Rendered client-side so each reader sees their own locale. */
function FormattedDate({ iso }: { iso: string | null | undefined }) {
    if (!iso) {
        return <>—</>;
    }

    return (
        <>
            {new Date(iso).toLocaleDateString(undefined, {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
            })}
        </>
    );
}

DocumentsIndex.layout = {
    breadcrumbs: [{ title: 'Track Documents', href: documents.index() }],
};
