import { Head } from '@inertiajs/react';

type Signature = {
    serial: string;
    control_number: string;
    signer_name: string;
    signer_position: string | null;
    signer_office: string | null;
    purpose: string;
    signed_at: string;
    file_version: number | null;
    valid: boolean;
    superseded: boolean;
};

/**
 * §15 public verification, reached by scanning the QR on a printed Signature
 * Certificate. No session required — whoever is holding the paper has to be
 * able to check it.
 *
 * Shows only what the certificate already shows, plus the verdict. Possession
 * of a serial proves nothing beyond holding the printout, so it buys no access
 * to the document.
 */
export default function VerifySignature({
    signature,
}: {
    signature: Signature | null;
}) {
    return (
        <>
            <Head title="Verify signature" />

            <main className="flex min-h-screen items-center justify-center bg-neutral-50 p-4 dark:bg-neutral-950">
                <div className="w-full max-w-md rounded-xl border bg-white p-6 shadow-sm dark:bg-neutral-900">
                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                        Signature verification
                    </p>

                    {signature === null ? (
                        <>
                            <h1 className="mt-1 text-lg font-semibold">
                                No such certificate
                            </h1>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Nothing matches that serial. Check the printed
                                certificate and try again, or contact the office
                                that issued it.
                            </p>
                        </>
                    ) : (
                        <>
                            <h1 className="mt-1 font-mono text-lg font-semibold">
                                {signature.control_number}
                            </h1>

                            <div
                                className={`mt-4 rounded-lg border p-3 text-sm ${
                                    !signature.valid
                                        ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-200'
                                        : signature.superseded
                                          ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200'
                                          : 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200'
                                }`}
                            >
                                {!signature.valid ? (
                                    <>
                                        <strong>Does not match.</strong> The
                                        file recorded against this signature has
                                        been changed or removed.
                                    </>
                                ) : signature.superseded ? (
                                    <>
                                        <strong>Valid, but superseded.</strong>{' '}
                                        A newer version has been uploaded since
                                        this was signed. It covers version{' '}
                                        {signature.file_version} only.
                                    </>
                                ) : (
                                    <>
                                        <strong>Valid.</strong> The signed file
                                        still matches its recorded fingerprint.
                                    </>
                                )}
                            </div>

                            <dl className="mt-5 space-y-3 text-sm">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Signed by
                                    </dt>
                                    <dd className="font-medium">
                                        {signature.signer_name}
                                        {signature.signer_position &&
                                            ` · ${signature.signer_position}`}
                                    </dd>
                                    {signature.signer_office && (
                                        <dd className="text-muted-foreground">
                                            {signature.signer_office}
                                        </dd>
                                    )}
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Date signed
                                    </dt>
                                    <dd>
                                        {new Date(
                                            signature.signed_at,
                                        ).toLocaleString()}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Serial
                                    </dt>
                                    <dd className="font-mono text-xs break-all">
                                        {signature.serial}
                                    </dd>
                                </div>
                            </dl>

                            <p className="mt-6 border-t pt-4 text-xs text-muted-foreground">
                                This is an electronic signature recorded by
                                CICTO. It is not a PNPKI digital certificate,
                                and no certificate authority has verified the
                                signer&rsquo;s identity.
                            </p>
                        </>
                    )}
                </div>
            </main>
        </>
    );
}
