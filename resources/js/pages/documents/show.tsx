import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, Download } from 'lucide-react';
import { useState } from 'react';
import DocumentCommentController from '@/actions/App/Http/Controllers/DocumentCommentController';
import DocumentFileController from '@/actions/App/Http/Controllers/DocumentFileController';
import DocumentSignatureController from '@/actions/App/Http/Controllers/DocumentSignatureController';
import DocumentWorkflowController from '@/actions/App/Http/Controllers/DocumentWorkflowController';
import {
    DocumentFacts,
    DocumentMark,
    ProgressTimeline,
    StageStepper,
    TrackingMetrics,
    upcomingStages,
} from '@/components/documents/document-tracking';
import {
    OfficeRoutePicker,
    routeError,
} from '@/components/documents/office-route-picker';
import { SignaturePad } from '@/components/documents/signature-pad';
import { ToneBadge } from '@/components/documents/status-badge';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import documents from '@/routes/documents';
import type {
    DocumentCommentItem,
    DocumentDetail,
    DocumentFileItem,
    IdNameOption,
    OfficeDwell,
    SignatureItem,
    TimelineEntry,
} from '@/types';

type LongestStage = {
    office: string | null;
    stage: string;
    duration: string;
} | null;

type Props = {
    document: DocumentDetail;
    timeline: TimelineEntry[];
    longestStage: LongestStage;
    officeRollup: OfficeDwell[];
    files: DocumentFileItem[];
    signatures: SignatureItem[];
    comments: DocumentCommentItem[];
    offices: IdNameOption[];
};

export default function ShowDocument({
    document,
    timeline,
    longestStage,
    officeRollup,
    files,
    signatures,
    comments,
    offices,
}: Props) {
    const [archiveReason, setArchiveReason] = useState('');

    const action = useForm<{
        action: string;
        to_office_ids: number[];
        remarks: string;
    }>({
        action: '',
        to_office_ids: [],
        remarks: '',
    });

    const comment = useForm({ body: '', is_internal: false as boolean });

    // #14 Version Control: a re-upload appends a new immutable version rather
    // than replacing the file in place.
    const version = useForm<{ file: File | null; replace_reason: string }>({
        file: null,
        replace_reason: '',
    });

    // §15. `method` mirrors App\Enums\SignatureMethod.
    const signature = useForm<{ method: string; image: string | null }>({
        method: 'drawn',
        image: null,
    });

    const submitAction = (value: string) => {
        // expected_movement_id is injected here rather than held in form state.
        // useForm captures its initial values once, so a copy taken at first
        // render goes stale the moment the first action succeeds -- and every
        // subsequent action on the same page would then 409 as a "double
        // submit" that never happened. Reading it from props at submit time
        // keeps the guard honest: it still catches a genuinely stale tab,
        // because the props only change when the page actually reloads.
        action.transform((data) => ({
            ...data,
            action: value,
            expected_movement_id: document.expected_movement_id ?? '',
            /*
                Destinations belong to a forward and nowhere else. The picker
                only renders for one, but its value stays in form state, so
                approving used to post an empty `to_office_ids[]` -- a blank
                the server then had to read as "no offices" rather than as one
                unparseable office. It is defended on both sides now; sending
                nothing is simply the honest payload.
            */
            to_office_ids: value === 'forwarded' ? data.to_office_ids : [],
        }));

        action.post(
            DocumentWorkflowController.store.url({ document: document.id }),
            {
                preserveScroll: true,
                onSuccess: () => action.reset(),
            },
        );
    };

    const isForward = action.data.action === 'forwarded';

    // One plain sentence for the Processing Summary panel, derived from the
    // ledger rather than stored, so it can never contradict the timeline.
    const processingSummary = !document.tracking.resting_office
        ? `The document is ${document.status_label.toLowerCase()}.`
        : document.tracking.is_open
          ? `The document is currently ${document.status_label.toLowerCase()} by ${document.tracking.resting_office}.`
          : `The document was ${document.status_label.toLowerCase()} at ${document.tracking.resting_office}.`;

    return (
        <>
            <Head title={document.control_number} />

            <Link
                href={documents.index()}
                className="inline-flex items-center gap-1 text-sm font-bold text-white/90 transition hover:text-white"
            >
                <ChevronLeft className="size-4" />
                Back to Track Document
            </Link>

            <div className="mt-4 flex flex-col gap-4">
                {/* §10 Status Tracking, in the client's "View Documents" shape. */}
                <section className="rounded-xl bg-white p-6 shadow-xl sm:p-8">
                    {/*
                        The mark hangs in the gutter beside the rail, not inline
                        with the heading -- `mt-9` is what drops it off the
                        heading's baseline to sit between the title and the
                        stages, where the design puts it. It is hidden on a
                        phone, where there is no gutter to hang it in.

                        Only this pair is indented. The facts panel below stays
                        a sibling so it still spans the full card, as drawn.
                    */}
                    <div className="flex items-start gap-4 sm:gap-6">
                        <DocumentMark className="mt-9 hidden w-8 shrink-0 sm:block" />

                        <div className="min-w-0 flex-1">
                            <h1 className="text-2xl font-bold text-navy">
                                View Documents
                            </h1>

                            {/*
                                The rail starts further in than the heading
                                above it -- roughly the width of the mark in
                                the gutter -- so the two do not stack on one
                                left edge. Measured off the design.
                            */}
                            <div className="mt-6 overflow-x-auto pb-2 sm:pl-10">
                                <StageStepper status={document.status} />
                            </div>
                        </div>
                    </div>

                    {/*
                        Inset from the card's own padding, not flush with it:
                        the design floats this panel inside the sheet, and the
                        margin here plus the card's padding is what reproduces
                        that. The rule down the middle is drawn too, sitting
                        just clear of the metrics box's own border.
                    */}
                    <div className="mt-8 grid gap-6 rounded-lg border border-[#E4EAF2] p-6 sm:mx-6 lg:mx-8 lg:grid-cols-[minmax(0,4fr)_minmax(0,5fr)]">
                        <DocumentFacts document={document} />

                        <div className="lg:border-l lg:border-[#E4EAF2] lg:pl-4">
                            <TrackingMetrics
                                document={document}
                                longestStage={longestStage}
                            />
                        </div>
                    </div>

                    {/*
                        §13 Audit trail. A bordered panel inside this sheet, not
                        a card of its own: the design is ONE white container
                        holding two outlined panels, so a second shadowed card
                        here read as a separate document rather than the lower
                        half of the same one.

                        Full width of the panel, which is also what puts the
                        Processing Summary beside the stages -- ProgressTimeline
                        places it on a @2xl CONTAINER query, and a half-width
                        column never fires it.
                    */}
                    <div className="mt-6 rounded-lg border border-[#E4EAF2] p-6 sm:mx-6 lg:mx-8">
                        <ProgressTimeline
                            timeline={timeline}
                            summary={processingSummary}
                            upcoming={upcomingStages(
                                document.status,
                                document.tracking.is_open,
                            )}
                        />
                    </div>
                </section>

                {/*
                    Everything that is NOT on the client's "View Documents"
                    sheet, folded behind one bar so the default view is that
                    sheet exactly: two cards and then the skyline, with no
                    stack of panels to scroll past. Nothing was removed --
                    the QR label, routing, archiving, versions, signatures
                    and comments are all one click inside this.
                */}
                <details className="group">
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-4 rounded-xl bg-white px-6 py-4 text-[15px] font-bold text-navy shadow-xl">
                        Actions, files, signatures and comments
                        <ChevronDown
                            aria-hidden="true"
                            className="size-5 shrink-0 text-[#3B72C4] transition group-open:rotate-180"
                        />
                    </summary>

                    <div className="mt-4 flex flex-col gap-4">
                        {/* §13: how long it stayed at each office. The design
                        has no place for this, but it is what answers "which
                        office is slow" -- the question §1 says the client
                        actually has. */}
                        {officeRollup.length > 0 && (
                            <details className="mt-6 rounded-lg bg-[#F4F7FC] p-4">
                                <summary className="cursor-pointer text-sm font-bold text-navy">
                                    Time spent per office
                                </summary>
                                <dl className="mt-3 space-y-1 text-sm">
                                    {officeRollup.map((row) => (
                                        <div
                                            key={row.office}
                                            className="flex justify-between gap-4"
                                        >
                                            <dt className="text-copy">
                                                {row.office}
                                                {row.visits > 1 &&
                                                    ` (${row.visits} visits)`}
                                                {row.is_current &&
                                                    ' · here now'}
                                            </dt>
                                            <dd className="font-bold text-navy">
                                                {row.duration ?? '—'}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </details>
                        )}

                        <section className="rounded-xl bg-white p-6 shadow-xl">
                            <h3 className="mb-3 text-sm font-semibold">
                                Label
                            </h3>
                            {/*
                                Print label only. The "Show QR" toggle that
                                stood beside it -- and the panel it opened below
                                -- moved to Track Documents on 2026-08-27, where
                                every row now carries its own QR button next to
                                View. The client asked for it there because the
                                QR is what gets handed to a courier, and reaching
                                it used to mean opening the document first.
                            */}
                            <div className="flex flex-wrap gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={documents.labels.print.url({
                                            query: { ids: [document.id] },
                                        })}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Print label
                                    </a>
                                </Button>
                            </div>
                        </section>

                        {/*
                    §9's routing plan. Rendered for the whole life of the
                    document, not just while stops are pending: a five-office
                    send has to keep LOOKING like a five-office send, including
                    after a rejection cancelled the tail of it.
                */}
                        {document.route.length > 0 && (
                            <section className="rounded-xl bg-white p-6 shadow-xl">
                                <h3 className="mb-1 text-sm font-semibold">
                                    Route
                                </h3>
                                <p className="mb-3 text-xs text-copy">
                                    Where this document is scheduled to go, in
                                    order.
                                </p>

                                <ol className="grid gap-2">
                                    {document.route.map((stop, index) => (
                                        <li
                                            key={stop.id}
                                            className="flex items-center gap-3 rounded-md border border-[#E4EAF2] px-3 py-2"
                                        >
                                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#E8F0FB] text-xs font-bold text-navy tabular-nums">
                                                {index + 1}
                                            </span>
                                            <span
                                                className={`min-w-0 flex-1 truncate text-sm font-medium ${
                                                    stop.status === 'cancelled'
                                                        ? 'text-copy line-through'
                                                        : 'text-navy'
                                                }`}
                                            >
                                                {stop.office ?? '—'}
                                            </span>
                                            <ToneBadge tone={stop.status_tone}>
                                                {stop.status_label}
                                            </ToneBadge>
                                        </li>
                                    ))}
                                </ol>
                            </section>
                        )}

                        {/* §9 Approval and routing */}
                        {/*
                    §16. Archiving is not a workflow action -- it applies once a
                    document is already finished -- so it sits apart from the
                    routing buttons rather than inside them.
                */}
                        {(document.can.archive || document.is_archived) && (
                            <section className="rounded-xl bg-white p-6 shadow-xl">
                                <h3 className="mb-3 text-sm font-semibold">
                                    Archive
                                </h3>

                                {document.is_archived ? (
                                    <div className="flex flex-wrap items-center gap-3">
                                        <p className="flex-1 text-sm text-copy">
                                            This document is filed in the
                                            archive. Nothing has been deleted.
                                        </p>
                                        {document.can.restore && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    router.delete(
                                                        documents.restore.url({
                                                            document:
                                                                document.id,
                                                        }),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Restore to active list
                                            </Button>
                                        )}
                                    </div>
                                ) : (
                                    <div className="flex flex-wrap items-end gap-3">
                                        <div className="min-w-56 flex-1">
                                            <label
                                                htmlFor="archive_reason"
                                                className="text-sm font-medium"
                                            >
                                                Reason (optional)
                                            </label>
                                            <Input
                                                id="archive_reason"
                                                value={archiveReason}
                                                onChange={(event) =>
                                                    setArchiveReason(
                                                        event.target.value,
                                                    )
                                                }
                                                maxLength={500}
                                                placeholder="Why is this being filed away?"
                                                className="mt-1"
                                            />
                                        </div>
                                        <Button
                                            onClick={() =>
                                                router.post(
                                                    documents.archive.url({
                                                        document: document.id,
                                                    }),
                                                    { reason: archiveReason },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Archive document
                                        </Button>
                                    </div>
                                )}
                            </section>
                        )}

                        {document.available_actions.length > 0 && (
                            <section className="rounded-xl bg-white p-6 shadow-xl">
                                <h3 className="mb-3 text-sm font-semibold">
                                    Actions
                                </h3>

                                <div className="mb-3 flex flex-wrap gap-2">
                                    {document.available_actions.map(
                                        (available) => (
                                            <Button
                                                key={available.value}
                                                size="sm"
                                                variant={
                                                    action.data.action ===
                                                    available.value
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                onClick={() =>
                                                    action.setData(
                                                        'action',
                                                        available.value,
                                                    )
                                                }
                                            >
                                                {available.value === 'forwarded'
                                                    ? 'Send to Another Office'
                                                    : available.label}
                                            </Button>
                                        ),
                                    )}
                                </div>

                                {action.data.action && (
                                    <div className="space-y-3">
                                        {isForward && (
                                            <div className="grid gap-2">
                                                <OfficeRoutePicker
                                                    offices={offices}
                                                    value={
                                                        action.data
                                                            .to_office_ids
                                                    }
                                                    disabled={action.processing}
                                                    onChange={(next) =>
                                                        action.setData(
                                                            'to_office_ids',
                                                            next,
                                                        )
                                                    }
                                                />
                                                <InputError
                                                    message={routeError(
                                                        action.errors,
                                                    )}
                                                />
                                            </div>
                                        )}

                                        <div className="grid gap-2">
                                            <label
                                                htmlFor="remarks"
                                                className="text-sm font-medium"
                                            >
                                                Remarks
                                                {requiresRemarks(
                                                    document,
                                                    action.data.action,
                                                )
                                                    ? ''
                                                    : ' (optional)'}
                                            </label>
                                            <textarea
                                                id="remarks"
                                                rows={3}
                                                value={action.data.remarks}
                                                onChange={(event) =>
                                                    action.setData(
                                                        'remarks',
                                                        event.target.value,
                                                    )
                                                }
                                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                            />
                                            <InputError
                                                message={action.errors.remarks}
                                            />
                                            <InputError
                                                message={action.errors.action}
                                            />
                                        </div>

                                        <Button
                                            onClick={() =>
                                                submitAction(action.data.action)
                                            }
                                            disabled={action.processing}
                                        >
                                            {action.processing
                                                ? 'Working…'
                                                : 'Confirm'}
                                        </Button>
                                    </div>
                                )}
                            </section>
                        )}
                        <div className="grid gap-4 lg:grid-cols-2">
                            <section className="rounded-xl bg-white p-6 shadow-xl">
                                <h3 className="mb-3 text-sm font-semibold">
                                    Attachments
                                    {files.length > 1 && (
                                        <span className="ml-2 font-normal text-muted-foreground">
                                            {files.length} versions
                                        </span>
                                    )}
                                </h3>

                                {files.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        No file was attached.
                                    </p>
                                )}

                                <ul className="space-y-2">
                                    {files.map((file, index) => (
                                        <li
                                            key={file.id}
                                            className="flex items-center justify-between gap-2 text-sm"
                                        >
                                            <span className="min-w-0 flex-1 truncate">
                                                <span className="font-medium">
                                                    v{file.version}
                                                </span>
                                                {/* files arrive newest-first */}
                                                {index === 0 && (
                                                    <span className="ml-1 text-xs text-emerald-700 dark:text-emerald-400">
                                                        current
                                                    </span>
                                                )}{' '}
                                                · {file.original_name}
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    ({file.size})
                                                </span>
                                                {file.replace_reason && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {file.replace_reason}
                                                    </span>
                                                )}
                                            </span>
                                            {file.is_purged ? (
                                                <span
                                                    className="text-xs text-muted-foreground"
                                                    title="The file itself was removed under the retention policy. The version record remains."
                                                >
                                                    purged
                                                </span>
                                            ) : (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    asChild
                                                >
                                                    <a
                                                        href={documents.files.download.url(
                                                            {
                                                                document:
                                                                    document.id,
                                                                file: file.id,
                                                            },
                                                        )}
                                                    >
                                                        <Download className="size-4" />
                                                    </a>
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>

                                {document.can.uploadVersion && (
                                    <form
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            version.post(
                                                DocumentFileController.store.url(
                                                    {
                                                        document: document.id,
                                                    },
                                                ),
                                                {
                                                    preserveScroll: true,
                                                    forceFormData: true,
                                                    onSuccess: () =>
                                                        version.reset(),
                                                },
                                            );
                                        }}
                                        className="mt-4 space-y-2 border-t pt-4"
                                    >
                                        <label
                                            htmlFor="version-file"
                                            className="text-sm font-medium"
                                        >
                                            Upload a corrected version
                                        </label>
                                        <Input
                                            id="version-file"
                                            type="file"
                                            onChange={(event) =>
                                                version.setData(
                                                    'file',
                                                    event.target.files?.[0] ??
                                                        null,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={version.errors.file}
                                        />
                                        <Input
                                            placeholder="What changed? (optional)"
                                            value={version.data.replace_reason}
                                            onChange={(event) =>
                                                version.setData(
                                                    'replace_reason',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Earlier versions stay downloadable —
                                            nothing is overwritten.
                                        </p>
                                        <Button
                                            size="sm"
                                            type="submit"
                                            disabled={
                                                version.processing ||
                                                !version.data.file
                                            }
                                        >
                                            {version.processing
                                                ? 'Uploading…'
                                                : 'Upload version'}
                                        </Button>
                                    </form>
                                )}
                            </section>

                            <div className="space-y-4">
                                {/* §15 Digital Signatures */}
                                <section className="rounded-xl bg-white p-6 shadow-xl">
                                    <h3 className="mb-3 text-sm font-semibold">
                                        Signatures
                                    </h3>

                                    {signatures.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            Not signed yet.
                                        </p>
                                    )}

                                    <ul className="space-y-3">
                                        {signatures.map((item) => (
                                            <li
                                                key={item.id}
                                                className="text-sm"
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0">
                                                        <p className="font-medium">
                                                            {item.signer_name}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {item.signer_position ??
                                                                'Signatory'}
                                                            {item.signer_office &&
                                                                ` · ${item.signer_office}`}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {new Date(
                                                                item.signed_at,
                                                            ).toLocaleString()}
                                                            {item.file_version !==
                                                                null &&
                                                                ` · signed v${item.file_version}`}
                                                        </p>
                                                    </div>
                                                    <ToneBadge
                                                        tone={
                                                            !item.valid
                                                                ? 'red'
                                                                : item.superseded
                                                                  ? 'amber'
                                                                  : 'emerald'
                                                        }
                                                    >
                                                        {!item.valid
                                                            ? 'Mismatch'
                                                            : item.superseded
                                                              ? 'Superseded'
                                                              : 'Valid'}
                                                    </ToneBadge>
                                                </div>
                                                <a
                                                    href={documents.signatures.certificate.url(
                                                        {
                                                            document:
                                                                document.id,
                                                            signature:
                                                                item.serial,
                                                        },
                                                    )}
                                                    target="_blank"
                                                    rel="noopener"
                                                    className="text-xs underline"
                                                >
                                                    Signature certificate (PDF)
                                                </a>
                                            </li>
                                        ))}
                                    </ul>

                                    {document.can.sign && (
                                        <form
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                signature.post(
                                                    DocumentSignatureController.store.url(
                                                        {
                                                            document:
                                                                document.id,
                                                        },
                                                    ),
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            signature.reset(),
                                                    },
                                                );
                                            }}
                                            className="mt-4 space-y-3 border-t pt-4"
                                        >
                                            <SignaturePad
                                                onChange={(dataUrl) =>
                                                    signature.setData(
                                                        'image',
                                                        dataUrl,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={signature.errors.image}
                                            />
                                            {/*
                                        Stated up front, not buried in a manual.
                                        The client's expectations are the main
                                        risk in this feature, not the code.
                                    */}
                                            <p className="text-xs text-muted-foreground">
                                                You will be asked to confirm
                                                your password. Your signature is
                                                recorded against this exact file
                                                version — it is not printed onto
                                                the document itself.
                                            </p>
                                            <Button
                                                size="sm"
                                                type="submit"
                                                disabled={
                                                    signature.processing ||
                                                    !signature.data.image
                                                }
                                            >
                                                {signature.processing
                                                    ? 'Signing…'
                                                    : 'Sign document'}
                                            </Button>
                                        </form>
                                    )}
                                </section>

                                {/* §16 Comments */}
                                <section className="rounded-xl bg-white p-6 shadow-xl">
                                    <h3 className="mb-3 text-sm font-semibold">
                                        Comments
                                    </h3>

                                    <ul className="mb-3 space-y-3">
                                        {comments.map((item) => (
                                            <li
                                                key={item.id}
                                                className="text-sm"
                                            >
                                                <p className="font-medium">
                                                    {item.author ?? 'Unknown'}
                                                    {item.is_internal && (
                                                        <span className="ml-2 text-xs text-muted-foreground">
                                                            internal
                                                        </span>
                                                    )}
                                                    {item.context !==
                                                        'comment' && (
                                                        <span className="ml-2 text-xs text-muted-foreground">
                                                            {item.context}
                                                        </span>
                                                    )}
                                                </p>
                                                <p>{item.body}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDateTime(
                                                        item.created_at,
                                                    )}
                                                </p>
                                            </li>
                                        ))}
                                        {comments.length === 0 && (
                                            <li className="text-sm text-muted-foreground">
                                                No comments yet.
                                            </li>
                                        )}
                                    </ul>

                                    {document.can.comment && (
                                        <form
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                comment.post(
                                                    DocumentCommentController.store.url(
                                                        {
                                                            document:
                                                                document.id,
                                                        },
                                                    ),
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            comment.reset(),
                                                    },
                                                );
                                            }}
                                            className="space-y-2"
                                        >
                                            <textarea
                                                rows={2}
                                                value={comment.data.body}
                                                onChange={(event) =>
                                                    comment.setData(
                                                        'body',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Add a remark…"
                                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                            />
                                            <InputError
                                                message={comment.errors.body}
                                            />
                                            <Button
                                                size="sm"
                                                type="submit"
                                                disabled={comment.processing}
                                            >
                                                Comment
                                            </Button>
                                        </form>
                                    )}
                                </section>
                            </div>
                        </div>
                    </div>
                </details>
            </div>
        </>
    );
}

function requiresRemarks(document: DocumentDetail, value: string): boolean {
    return (
        document.available_actions.find((a) => a.value === value)
            ?.requires_remarks ?? false
    );
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
