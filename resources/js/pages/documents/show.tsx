import { Head, Link, useForm } from '@inertiajs/react';
import { Clock, Download, MapPin, QrCode } from 'lucide-react';
import { useState } from 'react';
import DocumentCommentController from '@/actions/App/Http/Controllers/DocumentCommentController';
import DocumentFileController from '@/actions/App/Http/Controllers/DocumentFileController';
import DocumentSignatureController from '@/actions/App/Http/Controllers/DocumentSignatureController';
import DocumentWorkflowController from '@/actions/App/Http/Controllers/DocumentWorkflowController';
import { SignaturePad } from '@/components/documents/signature-pad';
import { ToneBadge } from '@/components/documents/status-badge';
import Heading from '@/components/heading';
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

type Props = {
    document: DocumentDetail;
    timeline: TimelineEntry[];
    officeRollup: OfficeDwell[];
    files: DocumentFileItem[];
    signatures: SignatureItem[];
    comments: DocumentCommentItem[];
    offices: IdNameOption[];
};

export default function ShowDocument({
    document,
    timeline,
    officeRollup,
    files,
    signatures,
    comments,
    offices,
}: Props) {
    const [showQr, setShowQr] = useState(false);

    const action = useForm({
        action: '',
        to_office_id: '',
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

    return (
        <>
            <Head title={document.control_number} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <Heading
                            title={document.title}
                            description={`${document.control_number} · ${document.document_type ?? 'Document'}`}
                        />
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <ToneBadge tone={document.status_tone}>
                                {document.status_label}
                            </ToneBadge>
                            <ToneBadge tone={document.priority_tone}>
                                {document.priority_label}
                            </ToneBadge>
                            {document.due_state !== 'none' && (
                                <ToneBadge tone={document.due_state_tone}>
                                    {document.due_state_label}
                                </ToneBadge>
                            )}
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setShowQr((value) => !value)}
                        >
                            <QrCode className="size-4" />
                            {showQr ? 'Hide QR' : 'Show QR'}
                        </Button>
                        <Button variant="outline" asChild>
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
                </div>

                {showQr && (
                    <div className="flex w-fit flex-col items-center gap-2 rounded-xl border p-4">
                        <img
                            src={documents.qr.url({ document: document.id })}
                            alt={`QR code for ${document.control_number}`}
                            className="size-40"
                        />
                        <p className="font-mono text-xs">
                            {document.control_number}
                        </p>
                    </div>
                )}

                {/* §10 Status Tracking */}
                <section className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border p-4">
                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <MapPin className="size-3.5" /> Currently at
                        </p>
                        <p className="mt-1 font-medium">
                            {document.tracking.current_office ?? '—'}
                        </p>
                    </div>
                    <div className="rounded-xl border p-4">
                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Clock className="size-3.5" /> Time at this office
                        </p>
                        <p className="mt-1 font-medium">
                            {document.tracking.time_at_current_office ?? '—'}
                        </p>
                    </div>
                    <div className="rounded-xl border p-4">
                        <p className="text-xs text-muted-foreground">
                            Expected completion
                        </p>
                        <p className="mt-1 font-medium">
                            {formatDate(
                                document.tracking.expected_completion_at,
                            )}
                        </p>
                    </div>
                </section>

                {/* §9 Approval and routing */}
                {document.available_actions.length > 0 && (
                    <section className="rounded-xl border p-4">
                        <h3 className="mb-3 text-sm font-semibold">Actions</h3>

                        <div className="mb-3 flex flex-wrap gap-2">
                            {document.available_actions.map((available) => (
                                <Button
                                    key={available.value}
                                    size="sm"
                                    variant={
                                        action.data.action === available.value
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
                            ))}
                        </div>

                        {action.data.action && (
                            <div className="space-y-3">
                                {isForward && (
                                    <div className="grid gap-2 sm:max-w-sm">
                                        <label
                                            htmlFor="to_office_id"
                                            className="text-sm font-medium"
                                        >
                                            Send to
                                        </label>
                                        <select
                                            id="to_office_id"
                                            value={action.data.to_office_id}
                                            onChange={(event) =>
                                                action.setData(
                                                    'to_office_id',
                                                    event.target.value,
                                                )
                                            }
                                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                        >
                                            <option value="">
                                                Select an office…
                                            </option>
                                            {offices.map((office) => (
                                                <option
                                                    key={office.id}
                                                    value={office.id}
                                                >
                                                    {office.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={action.errors.to_office_id}
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
                                    {action.processing ? 'Working…' : 'Confirm'}
                                </Button>
                            </div>
                        )}
                    </section>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* §13 Audit trail */}
                    <section className="rounded-xl border p-4">
                        <h3 className="mb-3 text-sm font-semibold">
                            History &amp; routing
                        </h3>

                        {/* §13: how long it stayed at each office. */}
                        {officeRollup.length > 0 && (
                            <dl className="mb-4 space-y-1 rounded-lg bg-muted/40 p-3 text-xs">
                                {officeRollup.map((row) => (
                                    <div
                                        key={row.office}
                                        className="flex justify-between gap-4"
                                    >
                                        <dt>
                                            {row.office}
                                            {row.visits > 1 &&
                                                ` (${row.visits} visits)`}
                                            {row.is_current && ' · here now'}
                                        </dt>
                                        <dd className="font-medium">
                                            {row.duration ?? '—'}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        <ol className="space-y-3">
                            {timeline.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="border-l-2 border-muted pl-3"
                                >
                                    <p className="text-sm">
                                        <span className="font-medium">
                                            {entry.actor ?? 'System'}
                                        </span>{' '}
                                        {entry.verb} this document
                                        {entry.to_office && (
                                            <>
                                                {' '}
                                                {entry.from_office
                                                    ? `from ${entry.from_office} to ${entry.to_office}`
                                                    : `at ${entry.to_office}`}
                                            </>
                                        )}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDateTime(entry.arrived_at)}
                                        {entry.dwell &&
                                            ` · stayed ${entry.dwell}`}
                                        {entry.is_open && ' · still here'}
                                    </p>
                                    {entry.remarks && (
                                        <p className="mt-1 rounded bg-muted/50 p-2 text-xs">
                                            {entry.remarks}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </section>

                    <div className="space-y-4">
                        {/* §14 Version Control */}
                        <section className="rounded-xl border p-4">
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
                                            DocumentFileController.store.url({
                                                document: document.id,
                                            }),
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
                                                event.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                    <InputError message={version.errors.file} />
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

                        {/* §15 Digital Signatures */}
                        <section className="rounded-xl border p-4">
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
                                    <li key={item.id} className="text-sm">
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
                                                    document: document.id,
                                                    signature: item.serial,
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
                                                { document: document.id },
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
                                            signature.setData('image', dataUrl)
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
                                        You will be asked to confirm your
                                        password. Your signature is recorded
                                        against this exact file version — it is
                                        not printed onto the document itself.
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
                        <section className="rounded-xl border p-4">
                            <h3 className="mb-3 text-sm font-semibold">
                                Comments
                            </h3>

                            <ul className="mb-3 space-y-3">
                                {comments.map((item) => (
                                    <li key={item.id} className="text-sm">
                                        <p className="font-medium">
                                            {item.author ?? 'Unknown'}
                                            {item.is_internal && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    internal
                                                </span>
                                            )}
                                            {item.context !== 'comment' && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    {item.context}
                                                </span>
                                            )}
                                        </p>
                                        <p>{item.body}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {formatDateTime(item.created_at)}
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
                                                    document: document.id,
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
                                    <InputError message={comment.errors.body} />
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

                <Link
                    href={documents.index()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    ← Back to Track Documents
                </Link>
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

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : '—';
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
