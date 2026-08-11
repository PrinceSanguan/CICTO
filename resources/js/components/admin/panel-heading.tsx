import { usePage } from '@inertiajs/react';

/**
 * The "Admin Panel" masthead every panel screen carries in the design, with a
 * rule beneath it.
 *
 * One component rather than four copies: the office line under the title is
 * the only thing that varies, and it is the line an office admin uses to
 * confirm which department's register they are looking at -- which makes it
 * exactly the sort of thing that must not drift between pages.
 */
export function PanelHeading({ title }: { title?: string }) {
    const { auth } = usePage().props;
    const office = auth?.office;

    return (
        <header className="border-b border-[#D8E3F2] pb-4">
            <h1 className="text-2xl font-extrabold tracking-tight text-navy">
                {title ??
                    (auth?.role === 'super_admin'
                        ? 'Super Admin Panel'
                        : 'Admin Panel')}
            </h1>

            {office && (
                <p className="mt-1 text-sm text-copy">
                    {office.code} — {office.name}
                </p>
            )}
        </header>
    );
}
