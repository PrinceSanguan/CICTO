import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Appearance settings"
                    description="How CICTO renders on your screen"
                />

                {/*
                 * The theme switcher is gone rather than hidden. It used to
                 * offer a dark mode that painted the client's navy headings
                 * onto a black background; a setting that makes the app
                 * unreadable is worse than no setting.
                 */}
                <p className="max-w-prose text-sm text-muted-foreground">
                    CICTO uses a single light theme, matching the City
                    Government's document forms and printed materials. There is
                    nothing to configure here yet.
                </p>
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
