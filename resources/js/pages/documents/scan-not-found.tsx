import { Head } from '@inertiajs/react';

/**
 * A mistyped or unknown token.
 *
 * Rendered as a page rather than a 404 because couriers will mistype, and
 * because it must not reveal whether a token merely does not exist or exists
 * but is not visible to this viewer.
 */
export default function ScanNotFound({ token }: { token: string }) {
    return (
        <>
            <Head title="Document not found" />

            <main className="flex min-h-screen items-center justify-center bg-neutral-50 p-4 dark:bg-neutral-950">
                <div className="w-full max-w-sm rounded-xl border bg-white p-6 text-center shadow-sm dark:bg-neutral-900">
                    <h1 className="text-lg font-semibold">
                        Document not found
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        No document matches that code. Check the label and try
                        again, or ask the office that issued it.
                    </p>
                    {token && (
                        <p className="mt-4 font-mono text-xs break-all text-muted-foreground">
                            {token}
                        </p>
                    )}
                </div>
            </main>
        </>
    );
}
