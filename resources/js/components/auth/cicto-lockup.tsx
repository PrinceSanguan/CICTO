import cictoLogo from '@/assets/cicto-baliwag-logo.png';
import { cn } from '@/lib/utils';

/**
 * The horizontal CICTO lockup used on the auth screens: mark on the left,
 * "CICTO" over "Baliwag City" on the right.
 *
 * The supplied artwork is a SQUARE, VERTICALLY STACKED lockup -- the mark above
 * a green "CICTO" over a navy "BALIWAG" -- so the mark is cropped out of it
 * rather than re-drawn. Re-drawing the client's mark by hand would put a
 * not-quite-right logo on the first screen anyone sees.
 *
 * MARK_FRACTION is the share of the image height the mark occupies. It is the
 * one number to touch if the client ever supplies new artwork; everything else
 * is derived from it.
 */
const MARK_FRACTION = 0.67;

/**
 * `markClassName` and `wordmarkClassName` exist for the one caller that has to
 * shrink the mark and drop the words without redrawing the lockup: the panel
 * sidebar, collapsed to its 3rem icon rail. Both default to nothing, so every
 * other caller is unaffected.
 */
export function CictoLockup({
    className,
    markClassName,
    wordmarkClassName,
}: {
    className?: string;
    markClassName?: string;
    wordmarkClassName?: string;
}) {
    return (
        <div className={cn('flex items-center gap-3', className)}>
            <div
                className={cn(
                    'relative aspect-square w-14 shrink-0 overflow-hidden',
                    markClassName,
                )}
                aria-hidden="true"
            >
                <img
                    src={cictoLogo}
                    alt=""
                    className="absolute top-0 left-1/2 max-w-none -translate-x-1/2"
                    style={{ width: `${100 / MARK_FRACTION}%` }}
                />
            </div>

            <div className={cn('leading-none', wordmarkClassName)}>
                <span className="block text-3xl font-extrabold tracking-tight text-navy">
                    CICTO
                </span>
                <span className="mt-1 block text-sm font-semibold text-navy-soft">
                    Baliwag City
                </span>
            </div>
        </div>
    );
}
