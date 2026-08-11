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
export function PanelHeading({
    title = 'Admin Panel',
    subtitle,
}: {
    title?: string;
    subtitle?: string;
}) {
    const { auth } = usePage().props;
    const office = auth?.office;

    /*
     * The title comes from the ROUTE, not the role.
     *
     * It used to branch on the viewer's role, so a Super Admin opening the
     * Admin Panel was told they were in the Super Admin Panel. The pages under
     * /super-admin pass their own title; everything else is the Admin Panel
     * whoever is looking at it.
     */
    const line =
        subtitle ??
        (office
            ? `${office.code} — ${office.name}`
            : /*
               * An account with no office is a Super Admin, and DocumentBuilder
               * shows them everything. The old copy here said the opposite --
               * "you can only see what you submitted" -- directly above tiles
               * counting every document in the building.
               */
              auth?.role === 'super_admin'
              ? 'Every office in the system.'
              : null);

    return (
        <header className="border-b border-[#D8E3F2] pb-4">
            <h1 className="text-2xl font-extrabold tracking-tight text-navy">
                {title}
            </h1>

            {line && <p className="mt-1 text-sm text-copy">{line}</p>}
        </header>
    );
}
