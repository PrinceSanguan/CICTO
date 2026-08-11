import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

/**
 * Shows the toast a controller flashed with `back()->with('toast', [...])`.
 *
 * Reads the shared `flash.toast` prop rather than listening for a router
 * event: Inertia emits no 'flash' event, so the previous implementation
 * subscribed to something that never fired and every confirmation in the
 * application was silently dropped.
 */
export function useFlashToast(): void {
    const flash = usePage().props.flash as { toast?: FlashToast } | undefined;
    const data = flash?.toast;

    // Inertia keeps the same props object identity across a partial reload, so
    // firing on `data` alone would re-show the last toast on every visit. The
    // ref holds the message that has already been shown.
    const shown = useRef<string | null>(null);

    useEffect(() => {
        if (!data) {
            shown.current = null;

            return;
        }

        const key = `${data.type}:${data.message}`;

        if (shown.current === key) {
            return;
        }

        shown.current = key;

        // `warning` is a real sonner level and is used by the support-ticket
        // flow to say a ticket was recorded but not delivered.
        const show = toast[data.type] ?? toast;
        show(data.message);
    }, [data]);
}
