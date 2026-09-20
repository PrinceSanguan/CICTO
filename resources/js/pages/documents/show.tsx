import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, Download, Eye } from 'lucide-react';
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
import { FilePreviewDialog } from '@/components/documents/file-preview-dialog';
import {
    OfficeRoutePicker,
    routeError,
} from '@/components/documents/office-route-picker';
import { SignWithDocument } from '@/components/documents/sign-with-document';
import { ToneBadge } from '@/components/documents/status-badge';
import { UploadErrorDialog } from '@/components/documents/upload-error-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { Input } from '@/components/ui/input';
import { useUploadGuard } from '@/hooks/use-upload-guard';
import type { StampPlacement } from '@/lib/pdf-stamp';
import { stampFailure, useSignatureStamp } from '@/lib/use-signature-stamp';
import documents from '@/routes/documents';
import type {
    DocumentAction,
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

    /*
     * Which version is open in the viewer, if any.
     *
     * Held by id rather than by object so it survives a partial reload -- the
     * file rows are re-serialised on every workflow action, and a held object
     * would be a stale copy of one.
     *
     * Opened on demand, never on page render: a preview is an audited read
     * (SecurityEventType::FilePreviewed), and streaming every attachment into a
     * hidden frame on every page view would bury the log it is written to.
     */
    const [previewFileId, setPreviewFileId] = useState<number | null>(null);
    const previewFile = files.find((file) => file.id === previewFileId) ?? null;

    /** The version a signature made right now would bind to. files arrive newest-first. */
    const currentFile = files[0] ?? null;

    /*
     * A returned document has exactly one way on, and it carries the corrected
     * file. So it starts SELECTED: the client, looking at a bare Resubmit button
     * under a notice saying "attach the corrected document", reported that
     * there was no way to upload one (2026-09-16). The upload field only renders
     * once Resubmit is chosen, so choosing it for them is what puts it on screen.
     */
    const onlyResubmit =
        document.available_actions.length === 1 &&
        document.available_actions[0].value === 'resubmitted';

    const action = useForm<{
        action: string;
        to_office_ids: number[];
        remarks: string;
        signature_method: string;
        signature_image: string | null;
        // The corrected document, attached to a Return or a Resubmit.
        file: File | null;
        replace_reason: string;
    }>({
        action: onlyResubmit ? 'resubmitted' : '',
        to_office_ids: [],
        remarks: '',
        // Mirrors App\Enums\SignatureMethod. The pad reports `drawn` or
        // `uploaded` with every mark; a typed name has no canvas to come from.
        signature_method: 'drawn',
        signature_image: null,
        file: null,
        replace_reason: '',
    });

    /*
     * §15 handoff. Signing before sending a document onward is OFFERED, never
     * required, so the pad stays closed until somebody asks for it — an
     * unopened pad must post exactly what a forward posted before this existed.
     */
    const [signOnSend, setSignOnSend] = useState(false);

    const comment = useForm({ body: '', is_internal: false as boolean });

    // #14 Version Control: a re-upload appends a new immutable version rather
    // than replacing the file in place.
    const version = useForm<{ file: File | null; replace_reason: string }>({
        file: null,
        replace_reason: '',
    });

    // One guard per upload, so a refusal names the file from its own form.
    const correctedUpload = useUploadGuard();
    const versionUpload = useUploadGuard();

    // §15. `method` mirrors App\Enums\SignatureMethod.
    const signature = useForm<{
        method: string;
        image: string | null;

        // Added by transform() only when the mark is being printed onto the
        // page, but declared here so the server's refusal has a field to
        // land on -- errors are typed from this shape.
        stamped_pdf?: File | null;
        placement?: StampPlacement | null;
    }>({
        method: 'drawn',
        image: null,
    });

    /*
     * Bumped after each successful signature, to remount the pad. reset()
     * empties the form, but the pad keeps its own canvas -- so when the form
     * stays on screen (an approval signed, a release still to sign) it showed
     * a mark the form no longer held, beside a Sign button that would not press.
     */
    const [signaturePadKey, setSignaturePadKey] = useState(0);

    /** Which signature's Undo is in flight, so only that one row reads busy. */
    const [undoing, setUndoing] = useState<number | null>(null);

    /*
     * §15 stamping. One per form, because the two are independent: an office
     * can place its release mark in the Forward panel while an approval sits
     * half-placed in the section below, and a shared position would drag both.
     * Only one placer is ever mounted at a time -- the Forward one appears
     * with the tickbox -- so this costs nothing until it is used.
     */
    const approvalStamp = useSignatureStamp();
    const releaseStamp = useSignatureStamp();

    /** What the stamped version is called. Named after what it came from. */
    const stampedName = currentFile?.original_name ?? 'signed.pdf';

    const submitAction = async (value: string) => {
        /*
         * The signed copy is composed BEFORE anything is posted, and a failure
         * here abandons the whole submit.
         *
         * Forwarding and signing go in one request, so a stamp that failed
         * after the forward had already been sent would leave the folder at
         * the next office carrying an unsigned page and no way to go back and
         * add the mark -- the version it would have stamped is no longer the
         * one on the signer's desk.
         */
        let stampedRelease: File | null = null;

        if (
            value === 'forwarded' &&
            signOnSend &&
            action.data.signature_image
        ) {
            try {
                stampedRelease = await releaseStamp.buildStampedFile(
                    action.data.signature_image,
                    stampedName,
                );
            } catch (error) {
                releaseStamp.setError(stampFailure(error));

                return;
            }
        }

        // expected_movement_id is injected here rather than held in form state.
        // useForm captures its initial values once, so a copy taken at first
        // render goes stale the moment the first action succeeds -- and every
        // subsequent action on the same page would then 409 as a "double
        // submit" that never happened. Reading it from props at submit time
        // keeps the guard honest: it still catches a genuinely stale tab,
        // because the props only change when the page actually reloads.
        action.transform((data) => {
            const forwarding = value === 'forwarded';

            // The corrected file rides along with a return or a resubmit and
            // nothing else; the server refuses a file on any other action
            // rather than silently dropping it.
            const correcting =
                (value === 'resubmitted' || value === 'returned') &&
                data.file !== null;

            // Only a forward that was actually signed carries the block. The
            // server reads a PRESENT signature_method as "this submit signs",
            // and asks the policy about it — so posting an empty one would
            // refuse ordinary forwards from anyone who may not sign.
            const signing = forwarding && signOnSend && !!data.signature_image;

            return {
                action: value,
                remarks: data.remarks,
                expected_movement_id: document.expected_movement_id ?? '',
                /*
                    Destinations belong to a forward and nowhere else. The picker
                    only renders for one, but its value stays in form state, so
                    approving used to post an empty `to_office_ids[]` -- a blank
                    the server then had to read as "no offices" rather than as one
                    unparseable office. It is defended on both sides now; sending
                    nothing is simply the honest payload.
                */
                to_office_ids: forwarding ? data.to_office_ids : [],
                ...(signing
                    ? {
                          signature_method: data.signature_method,
                          signature_image: data.signature_image,
                      }
                    : {}),
                ...(signing &&
                stampedRelease !== null &&
                releaseStamp.placement !== null
                    ? {
                          signature_stamped_pdf: stampedRelease,
                          signature_placement: releaseStamp.placement,
                      }
                    : {}),
                ...(correcting
                    ? {
                          file: data.file,
                          replace_reason: data.replace_reason,
                      }
                    : {}),
            };
        });

        action.post(
            DocumentWorkflowController.store.url({ document: document.id }),
            {
                preserveScroll: true,
                forceFormData:
                    ((value === 'resubmitted' || value === 'returned') &&
                        action.data.file !== null) ||
                    stampedRelease !== null,
                onError: (errors) => correctedUpload.reject(errors.file),
                onSuccess: () => {
                    // reset() restores the defaults captured at first render,
                    // which pre-select Resubmit on a returned document. Once
                    // it has gone that is stale, so nothing stays selected.
                    action.reset();
                    action.setData('action', '');
                    setSignOnSend(false);
                },
            },
        );
    };

    const isForward = action.data.action === 'forwarded';

    // Sends the folder back to the office that filed it, so it is the one
    // action on this panel that says what it will do before it is confirmed.
    const isReturn = action.data.action === 'returned';

    // A returned document's only way on, and the one that carries the fix.
    const isResubmit = action.data.action === 'resubmitted';

    const returnNotice = document.return_notice;

    const canResubmit = document.available_actions.some(
        (available) => available.value === 'resubmitted',
    );

    // A returned document is acted on from the Actions panel, which sits in
    // the collapsed section below -- so for the office that has to fix it, the
    // section starts open. Held in state rather than read from the prop, so
    // the section does not snap shut the moment the resubmit succeeds.
    const [detailsOpen] = useState(canResubmit);

    // One plain sentence for the Processing Summary panel, derived from the
    // ledger rather than stored, so it can never contradict the timeline.
    // A returned document reads "Pending" in lists, and "currently pending by"
    // would hide the one thing a reader needs: that it is waiting on a fix.
    const processingSummary = returnNotice
        ? `The document was returned to ${document.originating_office ?? 'its originating office'} for correction, and is waiting to be resubmitted to ${returnNotice.returned_by_office ?? 'the office that returned it'}.`
        : !document.tracking.resting_office
          ? `The document is ${document.status_label.toLowerCase()}.`
          : document.tracking.is_open
            ? `The document is currently ${document.status_label.toLowerCase()} by ${document.tracking.resting_office}.`
            : `The document was ${document.status_label.toLowerCase()} at ${document.tracking.resting_office}.`;

    return (
        <>
            <Head title={document.control_number} />

            <UploadErrorDialog {...correctedUpload.dialog} />
            <UploadErrorDialog {...versionUpload.dialog} />

            <FilePreviewDialog
                documentId={document.id}
                file={previewFile}
                onOpenChange={(open) => {
                    if (!open) {
                        setPreviewFileId(null);
                    }
                }}
            />

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
                    {/*
                        Why a returned document is back where it started. On
                        the sheet itself rather than in the Actions panel,
                        because the reason is the first thing anybody opening
                        it needs -- including the office that returned it.
                    */}
                    {returnNotice && (
                        <div
                            role="status"
                            className="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 sm:mx-6 lg:mx-8 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200"
                        >
                            <p className="font-bold">
                                Returned for correction
                                {returnNotice.returned_by_office &&
                                    ` by ${returnNotice.returned_by_office}`}
                            </p>
                            {returnNotice.remarks && (
                                <p className="mt-1 break-words whitespace-pre-line">
                                    {returnNotice.remarks}
                                </p>
                            )}
                            <p className="mt-1 text-xs">
                                {returnNotice.returned_by &&
                                    `${returnNotice.returned_by} · `}
                                {formatDateTime(returnNotice.returned_at)}
                            </p>
                            <p className="mt-2 text-xs">
                                {canResubmit
                                    ? 'Under Actions below, attach the corrected document and press Resubmit.'
                                    : `Waiting for ${document.originating_office ?? 'the originating office'} to correct and resubmit it.`}{' '}
                                It keeps the same control number and QR code.
                            </p>
                        </div>
                    )}

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
                <details className="group" open={detailsOpen}>
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
                                    Where this document started, and where it is
                                    scheduled to go, in order.
                                </p>

                                <ol className="grid gap-2">
                                    {/*
                                        Step one is the ORIGINATING OFFICE, and
                                        it is not one of `route` -- it has no
                                        document_route_stops row. The §5 form
                                        picks the departments as one ordered
                                        list and registers the document under
                                        the first of them, so listing only the
                                        stops drew a five-department submit as a
                                        four-department route, missing the very
                                        department it started at. That is the
                                        bug the client reported on 2026-09-13.

                                        Numbered ahead of the stops rather than
                                        set apart from them, because to the
                                        person who filled the form in it IS the
                                        first department they picked.
                                    */}
                                    {document.route_origin && (
                                        <li className="flex items-center gap-3 rounded-md border border-[#E4EAF2] px-3 py-2">
                                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#E8F0FB] text-xs font-bold text-navy tabular-nums">
                                                1
                                            </span>
                                            <span className="min-w-0 flex-1 truncate text-sm font-medium text-navy">
                                                {document.route_origin.office ??
                                                    '—'}
                                            </span>
                                            <ToneBadge
                                                tone={
                                                    document.route_origin
                                                        .status_tone
                                                }
                                            >
                                                {
                                                    document.route_origin
                                                        .status_label
                                                }
                                            </ToneBadge>
                                        </li>
                                    )}

                                    {document.route.map((stop, index) => (
                                        <li
                                            key={stop.id}
                                            className="flex items-center gap-3 rounded-md border border-[#E4EAF2] px-3 py-2"
                                        >
                                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#E8F0FB] text-xs font-bold text-navy tabular-nums">
                                                {index +
                                                    (document.route_origin
                                                        ? 2
                                                        : 1)}
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

                        {/*
                    The other copies one simultaneous submit produced.
                    Deliberately NOT the Route panel above: a route is one
                    document moving between offices, this is several documents
                    that never move together and never wait for each other. Two
                    different shapes deserve two different panels, or the flat
                    submit reads as a route whose stops are all stuck at once.
                */}
                        {document.submitted_with.length > 0 && (
                            <section className="rounded-xl bg-white p-6 shadow-xl">
                                <h3 className="mb-1 text-sm font-semibold">
                                    Submitted at the same time
                                </h3>
                                <p className="mb-3 text-xs text-copy">
                                    One submit,{' '}
                                    {document.submitted_with.length + 1}{' '}
                                    departments. Each has its own control number
                                    and deadline, and none waits for another.
                                </p>

                                <ul className="grid gap-2">
                                    {document.submitted_with.map((sibling) => (
                                        <li
                                            key={sibling.id}
                                            className="flex items-center gap-3 rounded-md border border-[#E4EAF2] px-3 py-2"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <Link
                                                    href={documents.show(
                                                        sibling.id,
                                                    )}
                                                    className="block truncate text-sm font-medium text-link hover:underline"
                                                >
                                                    {sibling.control_number}
                                                </Link>
                                                <span className="block truncate text-xs text-copy">
                                                    {sibling.office ?? '—'}
                                                </span>
                                            </div>
                                            <ToneBadge
                                                tone={sibling.status_tone}
                                            >
                                                {sibling.status_label}
                                            </ToneBadge>
                                        </li>
                                    ))}
                                </ul>
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
                                                    action.data.action !==
                                                    available.value
                                                        ? 'outline'
                                                        : 'default'
                                                }
                                                onClick={() =>
                                                    action.setData(
                                                        'action',
                                                        available.value,
                                                    )
                                                }
                                            >
                                                {actionLabel(available)}
                                            </Button>
                                        ),
                                    )}
                                </div>

                                {action.data.action && (
                                    <div className="space-y-3">
                                        {/*
                                            Said before the click, not only in
                                            the toast after it. At the last
                                            office of a route Received is also
                                            Completed (client, 2026-09-19), and
                                            completing cannot be undone.
                                        */}
                                        {action.data.action === 'received' &&
                                            document.receipt_completes && (
                                                <p className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-200">
                                                    There are no more offices on
                                                    this document&rsquo;s route,
                                                    so receiving it also marks
                                                    it Completed.
                                                </p>
                                            )}

                                        {isReturn && (
                                            <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                                                Returning sends this document
                                                back to{' '}
                                                {document.originating_office ??
                                                    'the office that filed it'}{' '}
                                                for correction. Say what needs
                                                fixing below — the reason is
                                                recorded on the document and the
                                                submitter is notified. Offices
                                                still queued on its route wait,
                                                and it comes back to your office
                                                once it is resubmitted, under
                                                the same control number and QR
                                                code.
                                            </p>
                                        )}

                                        {/*
                                            The corrected file travels WITH the
                                            return or the resubmit, so it is one
                                            step rather than an upload in one
                                            panel and a send in another. Offered
                                            on Return since 2026-09-16: the
                                            client asked for the returning office
                                            to be able to attach the corrected
                                            copy itself. Optional either way: a
                                            correction can be a signature on the
                                            paper folder.
                                        */}
                                        {(isResubmit || isReturn) && (
                                            <div className="grid gap-2 rounded-md border border-dashed p-3">
                                                <p className="text-xs text-copy">
                                                    {isResubmit ? (
                                                        <>
                                                            Resubmitting sends
                                                            this document back
                                                            to{' '}
                                                            <span className="font-medium text-navy">
                                                                {returnNotice?.returned_by_office ??
                                                                    'the office that returned it'}
                                                            </span>
                                                            . It keeps its
                                                            control number, QR
                                                            code and history.
                                                        </>
                                                    ) : (
                                                        <>
                                                            If your office
                                                            already has the
                                                            corrected copy,
                                                            attach it here. It
                                                            goes back to{' '}
                                                            <span className="font-medium text-navy">
                                                                {document.originating_office ??
                                                                    'the office that filed it'}
                                                            </span>{' '}
                                                            as the current
                                                            version, so they
                                                            only need to check
                                                            it and resubmit.
                                                        </>
                                                    )}
                                                </p>
                                                <label
                                                    htmlFor="corrected-file"
                                                    className="text-sm font-medium"
                                                >
                                                    Corrected document
                                                    (optional)
                                                </label>
                                                <Input
                                                    id="corrected-file"
                                                    type="file"
                                                    disabled={action.processing}
                                                    onChange={(event) => {
                                                        const file =
                                                            correctedUpload.check(
                                                                event.target
                                                                    .files?.[0],
                                                            );

                                                        // Refused: clear it so
                                                        // Confirm cannot send it.
                                                        if (file === null) {
                                                            event.target.value =
                                                                '';
                                                        }

                                                        action.setData(
                                                            'file',
                                                            file,
                                                        );
                                                    }}
                                                />
                                                <InputError
                                                    message={action.errors.file}
                                                />
                                                <Input
                                                    placeholder="What changed? (optional)"
                                                    value={
                                                        action.data
                                                            .replace_reason
                                                    }
                                                    maxLength={500}
                                                    disabled={action.processing}
                                                    onChange={(event) =>
                                                        action.setData(
                                                            'replace_reason',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        action.errors
                                                            .replace_reason
                                                    }
                                                />
                                                <p className="text-xs text-muted-foreground">
                                                    Saved as the next version of
                                                    this document. Earlier
                                                    versions stay downloadable —
                                                    nothing is overwritten.
                                                </p>
                                            </div>
                                        )}

                                        {isForward && (
                                            <>
                                                <div className="grid gap-2">
                                                    <OfficeRoutePicker
                                                        offices={offices}
                                                        value={
                                                            action.data
                                                                .to_office_ids
                                                        }
                                                        disabled={
                                                            action.processing
                                                        }
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

                                                {/*
                                                    §15 handoff signature. An
                                                    office may sign the exact
                                                    version it is releasing, at
                                                    the moment it releases it.
                                                    Optional throughout: nothing
                                                    here blocks the send.
                                                */}
                                                <div className="grid gap-2 rounded-md border border-dashed p-3">
                                                    {document.release_signature ? (
                                                        <p className="text-xs text-emerald-700 dark:text-emerald-400">
                                                            Signed for release
                                                            by{' '}
                                                            <span className="font-medium">
                                                                {
                                                                    document
                                                                        .release_signature
                                                                        .signer_name
                                                                }
                                                            </span>{' '}
                                                            on{' '}
                                                            {new Date(
                                                                document
                                                                    .release_signature
                                                                    .signed_at,
                                                            ).toLocaleString()}
                                                            {document
                                                                .release_signature
                                                                .file_version !==
                                                                null &&
                                                                ` · v${document.release_signature.file_version}`}
                                                            .
                                                        </p>
                                                    ) : document.can
                                                          .signRelease ? (
                                                        <>
                                                            <label className="flex items-center gap-2 text-sm font-medium">
                                                                <input
                                                                    type="checkbox"
                                                                    className="size-4 rounded border-input"
                                                                    checked={
                                                                        signOnSend
                                                                    }
                                                                    disabled={
                                                                        action.processing
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) => {
                                                                        setSignOnSend(
                                                                            event
                                                                                .target
                                                                                .checked,
                                                                        );

                                                                        // Untick and the mark goes
                                                                        // with it, or an abandoned
                                                                        // drawing would still post.
                                                                        if (
                                                                            !event
                                                                                .target
                                                                                .checked
                                                                        ) {
                                                                            action.setData(
                                                                                'signature_image',
                                                                                null,
                                                                            );
                                                                        }
                                                                    }}
                                                                />
                                                                Sign this
                                                                version before
                                                                sending
                                                                (optional)
                                                            </label>

                                                            {signOnSend && (
                                                                <>
                                                                    {/*
                                                                        §15 binds a signature to one exact version, so
                                                                        that version is rendered right here. Reading it
                                                                        used to mean opening a dialog over the pad and
                                                                        closing it again before you could sign (client,
                                                                        2026-09-20).
                                                                    */}
                                                                    <SignWithDocument
                                                                        documentId={
                                                                            document.id
                                                                        }
                                                                        file={
                                                                            currentFile
                                                                        }
                                                                        disabled={
                                                                            action.processing
                                                                        }
                                                                        onOpenFullScreen={
                                                                            currentFile?.is_previewable
                                                                                ? () =>
                                                                                      setPreviewFileId(
                                                                                          currentFile.id,
                                                                                      )
                                                                                : undefined
                                                                        }
                                                                        onChange={(
                                                                            dataUrl,
                                                                            method,
                                                                        ) =>
                                                                            action.setData(
                                                                                (
                                                                                    data,
                                                                                ) => ({
                                                                                    ...data,
                                                                                    signature_image:
                                                                                        dataUrl,
                                                                                    signature_method:
                                                                                        method,
                                                                                }),
                                                                            )
                                                                        }
                                                                        signaturePng={
                                                                            action
                                                                                .data
                                                                                .signature_image
                                                                        }
                                                                        placement={
                                                                            releaseStamp.placement
                                                                        }
                                                                        onPlacementChange={
                                                                            releaseStamp.setPlacement
                                                                        }
                                                                        onBytes={
                                                                            releaseStamp.setBytes
                                                                        }
                                                                        stampError={
                                                                            releaseStamp.error
                                                                        }
                                                                        notice={
                                                                            <p className="text-xs text-muted-foreground">
                                                                                {releaseStamp.placement ===
                                                                                null
                                                                                    ? 'Your signature is recorded against this exact file version as your office’s release.'
                                                                                    : `Your signature will be printed on page ${releaseStamp.placement.page} and saved as a new version. The version you signed is kept unchanged.`}
                                                                            </p>
                                                                        }
                                                                    />
                                                                </>
                                                            )}
                                                            <InputError
                                                                message={
                                                                    action
                                                                        .errors
                                                                        .signature_image ??
                                                                    action
                                                                        .errors
                                                                        .signature_method
                                                                }
                                                            />
                                                        </>
                                                    ) : (
                                                        <p className="text-xs text-muted-foreground">
                                                            This version has not
                                                            been signed by your
                                                            office.
                                                        </p>
                                                    )}
                                                </div>
                                            </>
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
                                                void submitAction(
                                                    action.data.action,
                                                )
                                            }
                                            disabled={action.processing}
                                        >
                                            {action.processing
                                                ? 'Working…'
                                                : isReturn
                                                  ? 'Return document'
                                                  : isResubmit
                                                    ? 'Resubmit document'
                                                    : isForward &&
                                                        signOnSend &&
                                                        action.data
                                                            .signature_image
                                                      ? 'Sign & send'
                                                      : 'Confirm'}
                                        </Button>
                                    </div>
                                )}
                            </section>
                        )}
                        {/*
                            §15 signing, given the whole width.

                            Offered on its own, not only inside the Forward panel. On a
                            routed document the office moves it on by pressing Received,
                            never Forward, so the release pad there never opened and no
                            office down the route could sign. Both flags are Admin-only
                            (client, 2026-09-15). Approval wins when both are allowed;
                            the release signature is still offered afterwards, because
                            they are different attestations.

                            Lifted out of the narrow Signatures card on 2026-09-20: the
                            version being signed is now rendered beside the pad, and a
                            PDF in a half-width column is a PDF nobody reads. The card
                            below still lists what has been signed.
                        */}
                        {(document.can.sign || document.can.signRelease) && (
                            <section className="rounded-xl bg-white p-6 shadow-xl">
                                <h3 className="mb-3 text-sm font-semibold">
                                    {document.can.sign
                                        ? 'Sign this document'
                                        : 'Sign for release'}
                                </h3>

                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();

                                        void (async () => {
                                            /*
                                                The signed copy is composed before anything is
                                                posted. A failure abandons the submit rather
                                                than quietly recording a signature that never
                                                reached the page the signer put it on.
                                            */
                                            let stamped: File | null = null;

                                            try {
                                                stamped =
                                                    await approvalStamp.buildStampedFile(
                                                        signature.data.image,
                                                        stampedName,
                                                    );
                                            } catch (error) {
                                                approvalStamp.setError(
                                                    stampFailure(error),
                                                );

                                                return;
                                            }

                                            const placement =
                                                approvalStamp.placement;

                                            signature.transform((data) => ({
                                                ...data,
                                                purpose: document.can.sign
                                                    ? 'approval'
                                                    : 'release',
                                                ...(stamped !== null &&
                                                placement !== null
                                                    ? {
                                                          stamped_pdf: stamped,
                                                          placement,
                                                      }
                                                    : {}),
                                            }));

                                            signature.post(
                                                DocumentSignatureController.store.url(
                                                    {
                                                        document: document.id,
                                                    },
                                                ),
                                                {
                                                    preserveScroll: true,
                                                    forceFormData:
                                                        stamped !== null,
                                                    onSuccess: () => {
                                                        signature.reset();
                                                        approvalStamp.reset();
                                                        setSignaturePadKey(
                                                            (key) => key + 1,
                                                        );
                                                    },
                                                },
                                            );
                                        })();
                                    }}
                                >
                                    <SignWithDocument
                                        documentId={document.id}
                                        file={currentFile}
                                        padKey={signaturePadKey}
                                        disabled={signature.processing}
                                        onOpenFullScreen={
                                            currentFile?.is_previewable
                                                ? () =>
                                                      setPreviewFileId(
                                                          currentFile.id,
                                                      )
                                                : undefined
                                        }
                                        onChange={(dataUrl, method) =>
                                            signature.setData((data) => ({
                                                ...data,
                                                image: dataUrl,
                                                method,
                                            }))
                                        }
                                        signaturePng={signature.data.image}
                                        placement={approvalStamp.placement}
                                        onPlacementChange={
                                            approvalStamp.setPlacement
                                        }
                                        onBytes={approvalStamp.setBytes}
                                        stampError={approvalStamp.error}
                                        notice={
                                            /*
                                                Stated up front, not buried in a manual. The client's
                                                expectations are the main risk in this feature, not the
                                                code -- and now that the mark really can land on the
                                                page, the sentence has to say which of the two just
                                                happened rather than always denying it.
                                            */
                                            <p className="text-xs text-muted-foreground">
                                                {approvalStamp.placement ===
                                                null ? (
                                                    <>
                                                        Your signature is saved
                                                        as soon as you sign. It
                                                        is recorded against this
                                                        exact file version
                                                        {document.can.sign
                                                            ? ''
                                                            : ' as your office’s release to the next office'}
                                                        .
                                                    </>
                                                ) : (
                                                    <>
                                                        Your signature will be
                                                        printed on page{' '}
                                                        {
                                                            approvalStamp
                                                                .placement.page
                                                        }{' '}
                                                        and saved as a new
                                                        version. The version you
                                                        signed is kept
                                                        unchanged.
                                                    </>
                                                )}
                                            </p>
                                        }
                                    >
                                        <InputError
                                            message={signature.errors.image}
                                        />
                                        <InputError
                                            message={
                                                signature.errors.stamped_pdf
                                            }
                                        />
                                        <Button
                                            size="sm"
                                            type="submit"
                                            disabled={
                                                signature.processing ||
                                                !signature.data.image ||
                                                (approvalStamp.stampable &&
                                                    approvalStamp.placement ===
                                                        null)
                                            }
                                        >
                                            {signature.processing
                                                ? 'Signing…'
                                                : document.can.sign
                                                  ? 'Sign document'
                                                  : 'Sign for release'}
                                        </Button>
                                    </SignWithDocument>
                                </form>
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
                                            {/*
                                                `truncate` used to sit on this
                                                wrapper, and its
                                                white-space: nowrap was
                                                inherited by the note below --
                                                so once signing started writing
                                                "Signed by X (Office). Signature
                                                serial ..." into replace_reason,
                                                every version line was cut off
                                                mid-word with no way to read the
                                                rest (client, 2026-09-20).
                                                Only the FILE NAME needs
                                                containing; the note wraps.
                                            */}
                                            <span className="min-w-0 flex-1">
                                                <span className="font-medium">
                                                    v{file.version}
                                                </span>
                                                {/* files arrive newest-first */}
                                                {index === 0 && (
                                                    <span className="ml-1 text-xs text-emerald-700 dark:text-emerald-400">
                                                        current
                                                    </span>
                                                )}{' '}
                                                ·{' '}
                                                <span className="break-all">
                                                    {file.original_name}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    ({file.size})
                                                </span>
                                                {file.replace_reason && (
                                                    <span className="mt-0.5 block text-xs break-words text-muted-foreground">
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
                                                <span className="flex shrink-0 items-center">
                                                    {/*
                                                        Offered only for what can
                                                        actually be rendered --
                                                        the file's own bytes, or
                                                        the HTML the server makes
                                                        of a .docx or .xlsx. Older
                                                        .doc and .xls have no
                                                        viewer, and a button that
                                                        opens an empty frame is
                                                        worse than no button.
                                                    */}
                                                    {file.is_previewable && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            title={`Preview version ${file.version}`}
                                                            onClick={() =>
                                                                setPreviewFileId(
                                                                    file.id,
                                                                )
                                                            }
                                                        >
                                                            <Eye className="size-4" />
                                                            <span className="sr-only">
                                                                Preview version{' '}
                                                                {file.version}
                                                            </span>
                                                        </Button>
                                                    )}
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
                                                            title={`Download version ${file.version}`}
                                                        >
                                                            <Download className="size-4" />
                                                            <span className="sr-only">
                                                                Download version{' '}
                                                                {file.version}
                                                            </span>
                                                        </a>
                                                    </Button>
                                                </span>
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
                                                    onError: (errors) =>
                                                        versionUpload.reject(
                                                            errors.file,
                                                        ),
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
                                            onChange={(event) => {
                                                const file =
                                                    versionUpload.check(
                                                        event.target.files?.[0],
                                                    );

                                                if (file === null) {
                                                    event.target.value = '';
                                                }

                                                version.setData('file', file);
                                            }}
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
                                                            {item.purpose_label}
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
                                                    {/*
                                                        "Replaced", not "Superseded" -- the client's word,
                                                        2026-09-20. A records clerk should not have to know
                                                        what supersession is to read their own register.

                                                        The badge is only ever a word, so the sentence that
                                                        explains it lives in a tooltip on hover and focus.
                                                        TooltipProvider is mounted app-wide in app.tsx.
                                                    */}
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span
                                                                tabIndex={0}
                                                                className="cursor-help"
                                                            >
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
                                                                          ? 'Replaced'
                                                                          : 'Valid'}
                                                                </ToneBadge>
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent className="max-w-xs">
                                                            <p>
                                                                {!item.valid
                                                                    ? 'The file no longer matches the fingerprint recorded when this was signed. Someone replaced the bytes without going through the register.'
                                                                    : item.superseded
                                                                      ? `A newer version has been uploaded since this was signed${
                                                                            item.file_version ===
                                                                            null
                                                                                ? ''
                                                                                : ` — this signature covers v${item.file_version}`
                                                                        }. It still records what was signed and when; it just no longer describes the current file.`
                                                                      : 'This signature covers the version the document is on now, and the file still matches the fingerprint recorded when it was signed.'}
                                                            </p>
                                                        </TooltipContent>
                                                    </Tooltip>
                                                </div>
                                                <div className="mt-1 flex flex-wrap items-center gap-3">
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
                                                        Signature certificate
                                                        (PDF)
                                                    </a>

                                                    {/*
                                                        §15 undo, client request 2026-09-20. Offered only where
                                                        DocumentSignaturePolicy said yes -- while the folder is
                                                        still on your office's desk, with nobody having signed
                                                        or uploaded after you.

                                                        A plain button, no confirm dialog: the whole point is
                                                        that the mark was a mistake, and the page already says
                                                        underneath what pressing it takes away. Browser dialogs
                                                        are also the one thing the scan console must never
                                                        raise, so the app avoids the habit.
                                                    */}
                                                    {item.can_undo && (
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                undoing ===
                                                                item.id
                                                            }
                                                            onClick={() => {
                                                                setUndoing(
                                                                    item.id,
                                                                );
                                                                router.delete(
                                                                    documents.signatures.destroy.url(
                                                                        {
                                                                            document:
                                                                                document.id,
                                                                            signature:
                                                                                item.serial,
                                                                        },
                                                                    ),
                                                                    {
                                                                        preserveScroll: true,
                                                                        onFinish:
                                                                            () =>
                                                                                setUndoing(
                                                                                    null,
                                                                                ),
                                                                    },
                                                                );
                                                            }}
                                                            className="text-xs text-danger underline disabled:opacity-50"
                                                        >
                                                            {undoing === item.id
                                                                ? 'Removing…'
                                                                : 'Undo my signature'}
                                                        </button>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
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

/**
 * The button verb, where the ledger's past-tense name does not make one.
 *
 * `available.label` comes from App\Enums\MovementAction, which names what a
 * movement row RECORDS -- "Rejected", "Forwarded". That reads correctly in the
 * timeline and wrongly on a button, which is an instruction rather than a
 * report. Overridden here for the ones where the difference matters; the rest
 * ("Received", "Completed") already read as either.
 */
function actionLabel(action: DocumentAction): string {
    switch (action.value) {
        case 'forwarded':
            return 'Send to Another Office';
        case 'returned':
            return 'Return';
        case 'resubmitted':
            return 'Resubmit';
        case 'rejected':
            return 'Reject';
        default:
            return action.label;
    }
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
