import { Head, Link } from '@inertiajs/react';

type Props = {
    retention: { scans: number; securityEvents: number };
    contact: string | null;
    office: string;
};

/**
 * RA 10173 (Data Privacy Act) notice.
 *
 * Required because the system logs IP addresses and user agents every time a
 * QR label is scanned, including by members of the public who never signed up
 * for anything. Collecting that quietly is what the Act exists to prevent.
 *
 * Public and unauthenticated by design -- a courier who scans a folder has to
 * be able to read it.
 */
export default function Privacy({ retention, contact, office }: Props) {
    return (
        <>
            <Head title="Privacy notice" />

            <main className="mx-auto max-w-2xl px-6 py-12">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Privacy notice
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    How CICTO handles personal information, under Republic Act
                    10173 (Data Privacy Act of 2012).
                </p>

                <section className="mt-8 space-y-6 text-sm leading-relaxed">
                    <div>
                        <h2 className="font-semibold">What we record</h2>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-muted-foreground">
                            <li>
                                <strong>When a QR label is scanned:</strong> the
                                document scanned, the date and time, the IP
                                address of the device, and the browser
                                identification it sends. If you are signed in,
                                your account is recorded too.
                            </li>
                            <li>
                                <strong>When staff use the system:</strong>{' '}
                                sign-ins and failed sign-in attempts, documents
                                opened and downloaded, and every action taken on
                                a document — who did it and when.
                            </li>
                            <li>
                                <strong>For staff accounts:</strong> name, email
                                address, office, position and contact number.
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h2 className="font-semibold">Why</h2>
                        <p className="mt-2 text-muted-foreground">
                            To track where an official document is, to show how
                            long it stayed at each office, and to keep an
                            accurate record of who handled it. The scan log
                            exists so a document that goes missing can be traced
                            to its last confirmed sighting.
                        </p>
                    </div>

                    <div>
                        <h2 className="font-semibold">How long we keep it</h2>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-muted-foreground">
                            <li>
                                Scan records, including IP addresses:{' '}
                                <strong>{retention.scans} days</strong>.
                            </li>
                            <li>
                                Sign-in and security records:{' '}
                                <strong>{retention.securityEvents} days</strong>
                                .
                            </li>
                            <li>
                                Documents and their handling history are
                                official records and are retained under the{' '}
                                {office}&rsquo;s own records-retention rules.
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h2 className="font-semibold">Your rights</h2>
                        <p className="mt-2 text-muted-foreground">
                            You may ask what personal information we hold about
                            you, ask for it to be corrected, and object to how
                            it is used. Requests go to the {office}&rsquo;s Data
                            Protection Officer:
                        </p>
                        <p className="mt-2 font-medium">
                            {contact ?? (
                                <span className="text-amber-700 dark:text-amber-400">
                                    Not yet nominated — the {office} must name a
                                    Data Protection Officer before this system
                                    goes live.
                                </span>
                            )}
                        </p>
                    </div>
                </section>

                <p className="mt-10 text-xs text-muted-foreground">
                    <Link href="/" className="underline">
                        Back to CICTO
                    </Link>
                </p>
            </main>
        </>
    );
}
