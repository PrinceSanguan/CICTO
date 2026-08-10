import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import admin from '@/routes/admin';

/**
 * Routed placeholder.
 *
 * §4 names this item in the navigation, so it ships now as a labelled,
 * reachable page rather than a dead link or a menu entry that appears later.
 */
export default function UsersPage() {
    return (
        <>
            <Head title="Users" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Users"
                    description="Managing the users in your office ships in Phase 3. Roles and office assignment are already enforced server-side."
                />

                <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Not built yet.
                </div>
            </div>
        </>
    );
}

UsersPage.layout = {
    breadcrumbs: [{ title: 'Users', href: admin.users.index() }],
};
