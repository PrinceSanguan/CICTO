import { usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { UploadError } from '@/components/documents/upload-error-dialog';
import type { UploadRules } from '@/types';

/**
 * What is wrong with a picked file, in the server's own words, or null.
 *
 * Name, emptiness and size only. The browser cannot see what a file really is,
 * so a .txt renamed to .pdf passes here and is refused by the server's content
 * check -- which is why the guard also takes server errors (reject()).
 */
export function uploadProblem(file: File, rules: UploadRules): string | null {
    const dot = file.name.lastIndexOf('.');
    const extension = dot === -1 ? '' : file.name.slice(dot + 1).toLowerCase();

    if (!rules.extensions.includes(extension)) {
        return rules.messages.type;
    }

    if (file.size === 0) {
        return rules.messages.empty;
    }

    // Laravel's max: on a file compares kilobytes, so compare the same way.
    if (file.size / 1024 > rules.maxKb) {
        return rules.messages.size;
    }

    return null;
}

/**
 * Refuses a wrong file with a pop-up, when it is picked and when the server
 * sends it back. Render <UploadErrorDialog {...guard.dialog} /> once per form.
 */
export function useUploadGuard() {
    const { uploads } = usePage().props;
    const [error, setError] = useState<UploadError | null>(null);

    // Separate from `error` so closing keeps the text on screen while the
    // dialog animates out, instead of the box collapsing as it fades.
    const [open, setOpen] = useState(false);

    const show = (next: UploadError) => {
        setError(next);
        setOpen(true);
    };

    // The last file that got past check(), so a server refusal can name it
    // without every form threading the name through.
    const lastChecked = useRef<string | null>(null);

    /** The file if it may be sent; otherwise opens the pop-up and returns null. */
    const check = (file: File | null | undefined): File | null => {
        if (!file) {
            return null;
        }

        const message = uploadProblem(file, uploads);

        if (message !== null) {
            show({ fileName: file.name, message });

            return null;
        }

        lastChecked.current = file.name;

        return file;
    };

    /** Opens the pop-up for the server's file error, if there is one. */
    const reject = (message: string | undefined) => {
        if (message) {
            show({ fileName: lastChecked.current, message });
        }
    };

    return {
        check,
        reject,
        dialog: {
            open,
            error,
            allowed: uploads.allowed,
            maxLabel: `${Number((uploads.maxKb / 1024).toFixed(1))} MB`,
            onClose: () => setOpen(false),
        },
    };
}
