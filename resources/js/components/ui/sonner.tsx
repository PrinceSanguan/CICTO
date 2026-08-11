import { Toaster as Sonner, type ToasterProps } from 'sonner';
import { useFlashToast } from '@/hooks/use-flash-toast';

function Toaster({ ...props }: ToasterProps) {
    useFlashToast();

    return (
        <Sonner
            /*
             * Light, to match the rest of the app -- the theme switcher is gone
             * and every screen renders light, so reading the appearance hook
             * here would only ever return the same answer.
             */
            theme="light"
            /*
             * richColors gives success, warning and error toasts their own
             * green / amber / red treatment. Without it every toast was the
             * same dark pill and the only thing separating "registered" from
             * "not delivered" was a small icon, which is not enough of a
             * difference for a message that disappears after four seconds.
             */
            richColors
            className="toaster group"
            position="bottom-right"
            {...props}
        />
    );
}

export { Toaster };
