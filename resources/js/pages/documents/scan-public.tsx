import { Head } from '@inertiajs/react';
import { ToneBadge } from '@/components/documents/status-badge';
import type { Tone } from '@/types';

type Props = {
    document: {
        control_number: string;
        status_label: string;
        status_tone: Tone;
        current_office: string | null;
        updated_at: string | null;
        is_complete: boolean;
    };
};

/**
 * §7 public scan result.
 *
 * A SEPARATE page from the staff view, not the staff page with fields hidden.
 * Hiding fields in the component still ships them in the Inertia payload, which
 * is exactly how confidential data leaks.
 *
 * Everything here is either already printed on the label the viewer is holding,
 * or is the status they scanned to find out.
 */
export default function ScanPublic({ document }: Props) {
    return (
        <>
            <Head title={`Document ${document.control_number}`} />

            <main className="flex min-h-screen items-center justify-center bg-neutral-50 p-4 dark:bg-neutral-950">
                <div className="w-full max-w-sm rounded-xl border bg-white p-6 shadow-sm dark:bg-neutral-900">
                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                        Document status
                    </p>
                    <h1 className="mt-1 font-mono text-lg font-semibold">
                        {document.control_number}
                    </h1>

                    <dl className="mt-6 space-y-4 text-sm">
                        <div>
                            <dt className="text-muted-foreground">Status</dt>
                            <dd className="mt-1">
                                <ToneBadge tone={document.status_tone}>
                                    {document.status_label}
                                </ToneBadge>
                            </dd>
                        </div>

                        <div>
                            <dt className="text-muted-foreground">
                                {document.is_complete
                                    ? 'Last handled by'
                                    : 'Currently at'}
                            </dt>
                            <dd className="mt-1 font-medium">
                                {document.current_office ?? 'Not yet recorded'}
                            </dd>
                        </div>

                        <div>
                            <dt className="text-muted-foreground">
                                Last updated
                            </dt>
                            <dd className="mt-1">
                                {document.updated_at
                                    ? new Date(
                                          document.updated_at,
                                      ).toLocaleString()
                                    : '—'}
                            </dd>
                        </div>
                    </dl>

                    <p className="mt-6 border-t pt-4 text-xs text-muted-foreground">
                        Staff can sign in to see the full history and
                        attachments.
                    </p>
                    {/* RA 10173: this scan was logged with your IP address, so
                        the notice has to be reachable from here. */}
                    <p className="mt-2 text-xs text-muted-foreground">
                        This lookup was recorded.{' '}
                        <a href="/privacy" className="underline">
                            Privacy notice
                        </a>
                    </p>
                </div>
            </main>
        </>
    );
}
