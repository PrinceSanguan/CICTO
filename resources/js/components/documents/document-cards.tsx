import { Link } from '@inertiajs/react';
import { DocumentQrButton } from '@/components/documents/document-qr-button';
import { StatusPill } from '@/components/documents/status-pill';
import documents from '@/routes/documents';
import type { DocumentListItem } from '@/types';

/**
 * The phone rendering of a document list.
 *
 * A six-column table inside `overflow-x-auto` technically fits on a 375px
 * screen, but only the first two columns are visible and there is no affordance
 * saying the rest exists -- so Status, Date and the View button, which is the
 * whole point of the row, are simply gone. Horizontal scroll inside a
 * vertically scrolling page is also awkward on touch.
 *
 * Below `md` each row becomes a card with its fields labelled and a full-width
 * action. The table is still rendered from `md` up, where it reads better.
 */
export function DocumentCards({ items }: { items: DocumentListItem[] }) {
    if (items.length === 0) {
        return (
            <p className="px-5 py-10 text-center text-sm text-copy">
                No documents match these filters.
            </p>
        );
    }

    return (
        <ul className="divide-y divide-[#EEF2F7]">
            {items.map((document) => (
                <li key={document.id} className="p-4">
                    <Link
                        href={documents.show(document.id)}
                        className="block text-[15px] font-bold text-navy hover:underline"
                    >
                        {document.title}
                    </Link>

                    <p className="mt-0.5 font-mono text-xs text-copy">
                        {document.control_number}
                    </p>

                    <dl className="mt-3 space-y-1.5 text-sm">
                        <Row label="Department">
                            {document.resting_office ?? '—'}
                        </Row>
                        <Row label="Date">
                            {document.created_at
                                ? new Date(
                                      document.created_at,
                                  ).toLocaleDateString(undefined, {
                                      year: 'numeric',
                                      month: '2-digit',
                                      day: '2-digit',
                                  })
                                : '—'}
                        </Row>
                        <div className="flex items-center gap-2 pt-1">
                            <dt className="text-copy">Status</dt>
                            <dd>
                                <StatusPill
                                    tone={document.status_tone}
                                    label={document.status_label}
                                    className="min-w-0 px-3 py-1.5 text-xs"
                                />
                            </dd>
                        </div>
                    </dl>

                    {/* The same pairing as the table's Action cell, stacked
                        for the narrower measure: View takes the remaining
                        width, the QR keeps its square. */}
                    <div className="mt-4 flex items-center gap-2">
                        <Link
                            href={documents.show(document.id)}
                            className="flex-1 rounded-md bg-[#3B72C4] py-2.5 text-center text-sm font-bold text-white transition duration-200 ease-out hover:bg-[#31629F] active:scale-[0.97]"
                        >
                            View
                        </Link>

                        <DocumentQrButton
                            id={document.id}
                            controlNumber={document.control_number}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-baseline gap-2">
            <dt className="text-copy">{label}</dt>
            <dd className="font-semibold text-navy">{children}</dd>
        </div>
    );
}
