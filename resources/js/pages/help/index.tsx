import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import help from '@/routes/help';

/**
 * Routed placeholder.
 *
 * §4 names this item in the navigation, so it ships now as a labelled,
 * reachable page rather than a dead link or a menu entry that appears later.
 */
export default function HelpPage() {
    return (
        <>
            <Head title="Help" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Help"
                    description="Knowledge base, support tickets and contact details. Spec §23 is not costed in the signed breakdown — the scope of this page is still to be agreed."
                />

                <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Not built yet.
                </div>
            </div>
        </>
    );
}

HelpPage.layout = {
    breadcrumbs: [{ title: 'Help', href: help.index() }],
};
