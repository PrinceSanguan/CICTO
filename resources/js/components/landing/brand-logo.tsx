import cictoLogo from '@/assets/cicto-baliwag-logo.png';
import { cn } from '@/lib/utils';

/**
 * The only module that imports the logo binary, so swapping the client's
 * artwork is a one-line change here rather than a hunt through the page.
 *
 * The supplied file is a square, vertically stacked lockup: power-symbol mark
 * above a green "CICTO" over a navy "BALIWAG".
 */
export function BrandLogo({ className }: { className?: string }) {
    return (
        <img
            src={cictoLogo}
            alt="CICTO Baliwag"
            className={cn('w-auto object-contain', className)}
        />
    );
}
