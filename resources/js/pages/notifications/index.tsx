import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import notifications from '@/routes/notifications';
import type { Paginated } from '@/types';

type NotificationItem = {
    id: number;
    type: string;
    icon: string;
    title: string;
    body: string | null;
    control_number: string;
    document_id: number;
    read: boolean;
    created_at: string | null;
};

export default function NotificationsIndex({
    notifications: page,
}: {
    notifications: Paginated<NotificationItem>;
}) {
    return (
        <>
            <Head title="Notifications" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title="Notifications"
                        description="Documents assigned to, forwarded to, or overdue in your office."
                    />
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(
                                notifications.readAll.url(),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Mark all as read
                    </Button>
                </div>

                <ul className="divide-y rounded-xl border">
                    {page.data.length === 0 && (
                        <li className="p-8 text-center text-sm text-muted-foreground">
                            Nothing yet.
                        </li>
                    )}

                    {page.data.map((item) => (
                        <li
                            key={item.id}
                            className={item.read ? '' : 'bg-muted/40'}
                        >
                            <Link
                                href={notifications.go(item.id)}
                                className="block p-4 hover:bg-muted/60"
                            >
                                <p className="text-sm font-medium">
                                    {item.title}
                                    {!item.read && (
                                        <span className="ml-2 inline-block size-2 rounded-full bg-sky-500" />
                                    )}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {item.body}
                                </p>
                                <p className="mt-1 font-mono text-xs text-muted-foreground">
                                    {item.control_number}
                                    {item.created_at &&
                                        ` · ${new Date(item.created_at).toLocaleString()}`}
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

NotificationsIndex.layout = {
    breadcrumbs: [{ title: 'Notifications', href: notifications.index() }],
};
