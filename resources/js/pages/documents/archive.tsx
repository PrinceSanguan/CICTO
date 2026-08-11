import { Head, Link, router } from '@inertiajs/react';
import { Archive, RotateCcw, Search } from 'lucide-react';
import { useState } from 'react';
import { StatusPill } from '@/components/documents/status-pill';
import archive from '@/routes/archive';
import documents from '@/routes/documents';
import type { DocumentListItem, Paginated } from '@/types';

type ArchivedItem = DocumentListItem & {
    archived_at: string | null;
    archived_by: string | null;
    archive_reason: string | null;
    can: { restore: boolean };
};

type Props = {
    documents: Paginated<ArchivedItem>;
    filters: { q: string };
};

/** §16 Archive: filed documents, and the way back out. */
export default function DocumentArchive({ documents: page, filters }: Props) {
    const [q, setQ] = useState(filters.q ?? '');

    return (
        <>
            <Head title="Archive" />

            <h1 className="flex items-center gap-3 text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                <Archive aria-hidden="true" className="size-8" />
                Archive
            </h1>
            <p className="mt-1 max-w-2xl text-[15px] font-medium text-white/90">
                Documents that have been filed away. Nothing here is deleted —
                every file, signature and history entry is intact, and an
                administrator can restore any of them to the active list.
            </p>

            <form
                role="search"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get(
                        archive.index.url(),
                        { q: q || undefined },
                        { preserveState: true, replace: true },
                    );
                }}
                className="mt-6 flex max-w-xl gap-3"
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
                        placeholder="Search the archive"
                        aria-label="Search archived documents"
                        className="h-12 w-full rounded-lg border-0 bg-white pr-4 pl-12 text-[15px] text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                    />
                </div>
                <button
                    type="submit"
                    className="h-12 rounded-lg bg-white px-8 font-bold text-link transition hover:bg-[#F2F6FC]"
                >
                    Search
                </button>
            </form>

            <div className="mt-6 rounded-xl bg-white shadow-xl">
                {page.data.length === 0 ? (
                    <p className="p-10 text-center text-sm text-copy">
                        Nothing has been archived yet.
                    </p>
                ) : (
                    <ul className="divide-y divide-[#EEF2F7]">
                        {page.data.map((document) => (
                            <li
                                key={document.id}
                                className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center"
                            >
                                <div className="min-w-0 flex-1">
                                    <Link
                                        href={documents.show(document.id)}
                                        className="text-[15px] font-bold text-navy hover:underline"
                                    >
                                        {document.title}
                                    </Link>
                                    <p className="font-mono text-xs text-copy">
                                        {document.control_number}
                                    </p>

                                    <p className="mt-1 text-xs text-copy">
                                        Filed{' '}
                                        {document.archived_at
                                            ? new Date(
                                                  document.archived_at,
                                              ).toLocaleDateString()
                                            : '—'}
                                        {document.archived_by
                                            ? ` by ${document.archived_by}`
                                            : ''}
                                    </p>

                                    {document.archive_reason && (
                                        <p className="mt-1 text-xs text-copy italic">
                                            “{document.archive_reason}”
                                        </p>
                                    )}
                                </div>

                                <StatusPill
                                    tone={document.status_tone}
                                    label={document.status_label}
                                    className="min-w-0 self-start px-3 py-1.5 text-xs sm:self-auto"
                                />

                                {document.can.restore && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.delete(
                                                documents.restore.url({
                                                    document: document.id,
                                                }),
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="flex items-center justify-center gap-2 rounded-md border border-[#DCE4EE] px-5 py-2.5 text-sm font-bold text-navy transition hover:bg-[#F4F7FC]"
                                    >
                                        <RotateCcw className="size-4" />
                                        Restore
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {page.last_page > 1 && (
                    <nav
                        aria-label="Pagination"
                        className="flex flex-wrap justify-center gap-1 border-t border-[#EEF2F7] px-4 py-3"
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
                                        { preserveScroll: true },
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
            </div>
        </>
    );
}

DocumentArchive.layout = {
    breadcrumbs: [{ title: 'Archive', href: archive.index() }],
};
