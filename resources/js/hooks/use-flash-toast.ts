import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

/**
 * Shows the toast a controller flashed with `back()->with('toast', [...])`.
 *
 * Driven by Inertia's own `flash` router event rather than `usePage()`, because
 * <Toaster /> is mounted in `withApp` -- a SIBLING of the Inertia app, not a
 * child of it. Anything in there is outside the page context, so calling
 * usePage() throws "usePage must be used within the Inertia component" and
 * takes the whole component down with it.
 *
 * The event carries `page.flash`, which HandleInertiaRequests populates from
 * the Laravel session.
 */
export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash as
                { toast?: FlashToast } | undefined;

            const data = flash?.toast;

            if (!data) {
                return;
            }

            // `warning` is a real sonner level and the support-ticket flow uses
            // it to say a ticket was recorded but not delivered.
            const show = toast[data.type] ?? toast;
            show(data.message);
        });
    }, []);
}
