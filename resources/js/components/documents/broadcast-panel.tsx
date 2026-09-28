import { router } from '@inertiajs/react';
import { Lock, Megaphone } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import documents from '@/routes/documents';
import type { DocumentDetail, DocumentListItem } from '@/types';

// "on September 25, 2026 at 11:26 PM" -- the page's other dates read the same way.
const when = (value: string): string =>
    `on ${new Date(value).toLocaleString('en-US', { dateStyle: 'long', timeStyle: 'short' })}`;

/** The same two facts, one word each, for a row in a list. */
export function AccessTag({ document }: { document: DocumentListItem }) {
    if (document.is_confidential) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-[#F1E8FB] px-2 py-0.5 text-[11px] font-bold text-[#5B2E8A]">
                <Lock className="size-3" aria-hidden="true" />
                Confidential
            </span>
        );
    }

    if (document.is_broadcast) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-[#E6F0FB] px-2 py-0.5 text-[11px] font-bold text-navy">
                <Megaphone className="size-3" aria-hidden="true" />
                All offices
            </span>
        );
    }

    return null;
}

/**
 * On the sheet itself, under the stages: what kind of document this is before
 * anything else is read. Confidential and broadcast never both apply -- a
 * Confidential document cannot be broadcast.
 */
export function AccessNotice({ document }: { document: DocumentDetail }) {
    if (document.is_confidential) {
        return (
            <div
                role="note"
                className="mt-6 flex items-start gap-2 rounded-lg border border-[#E3D3F5] bg-[#F7F1FD] p-4 text-sm text-[#4B2A74] sm:mx-6 lg:mx-8 dark:border-purple-400/30 dark:bg-purple-400/10 dark:text-purple-200 print:mx-0"
            >
                <Lock className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <p>
                    <span className="font-bold">Confidential.</span> Only the
                    person who filed it and the people of the City Mayor&apos;s
                    Office or HRMO it was sent to can see this document.
                </p>
            </div>
        );
    }

    if (document.broadcast === null) {
        return null;
    }

    return (
        <div
            role="note"
            className="mt-6 flex items-start gap-2 rounded-lg border border-[#CFE0F5] bg-[#EEF4FD] p-4 text-sm text-navy sm:mx-6 lg:mx-8 dark:border-sky-400/30 dark:bg-sky-400/10 dark:text-sky-100 print:mx-0"
        >
            <Megaphone className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <div>
                <p>
                    <span className="font-bold">Sent to every office</span>{' '}
                    {when(document.broadcast.at)}
                    {document.broadcast.by && ` by ${document.broadcast.by}`}
                    {document.broadcast.office &&
                        ` (${document.broadcast.office})`}
                    .
                </p>
                {document.read_only_broadcast && (
                    <p className="mt-1 text-xs">
                        You can read it and download its file. Receiving,
                        signing and comments stay with the offices it passes
                        through.
                    </p>
                )}
            </div>
        </div>
    );
}

/**
 * "Broadcast to ALL offices" (client, 2026-09-25), for the types that have
 * it. Two steps, not a browser confirm(): it notifies every account in the
 * city and cannot be taken back, so the second press says so first.
 */
export function BroadcastPanel({ document }: { document: DocumentDetail }) {
    const [confirming, setConfirming] = useState(false);
    const [sending, setSending] = useState(false);

    if (!document.allows_broadcast) {
        return null;
    }

    if (document.broadcast === null && !document.can.broadcast) {
        return null;
    }

    return (
        <section className="rounded-xl bg-white p-6 shadow-xl">
            <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                <Megaphone className="size-4" aria-hidden="true" />
                Broadcast to all offices
            </h3>

            {document.broadcast !== null ? (
                <p className="text-sm text-copy">
                    Sent to every office {when(document.broadcast.at)}
                    {document.broadcast.by && ` by ${document.broadcast.by}`}.
                    Every office was notified and can read it.
                </p>
            ) : confirming ? (
                <div className="space-y-3">
                    <p
                        role="alert"
                        className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200"
                    >
                        Every office will be notified, and everyone in them will
                        be able to open this document and its file. This cannot
                        be undone.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={sending}
                            onClick={() =>
                                router.post(
                                    documents.broadcast.url({
                                        document: document.id,
                                    }),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setSending(true),
                                        onFinish: () => {
                                            setSending(false);
                                            setConfirming(false);
                                        },
                                    },
                                )
                            }
                        >
                            {sending ? 'Sending…' : 'Yes, send to every office'}
                        </Button>
                        <Button
                            variant="outline"
                            disabled={sending}
                            onClick={() => setConfirming(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                </div>
            ) : (
                <div className="flex flex-wrap items-center gap-3">
                    <p className="min-w-56 flex-1 text-sm text-copy">
                        Tell every office about this document and let them read
                        it. The document itself stays on its route.
                    </p>
                    <Button onClick={() => setConfirming(true)}>
                        Broadcast
                    </Button>
                </div>
            )}
        </section>
    );
}
