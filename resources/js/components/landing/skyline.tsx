import buildingSrc from '@/assets/building.png';
import { cn } from '@/lib/utils';

/**
 * The decorative city band that closes the page.
 *
 * This is the client's building.png (1440x237, transparent above the
 * rooflines), not a drawing of it: it replaces a hand-built SVG <pattern> that
 * approximated the same skyline out of rounded rects and repeated a 900px
 * tile. The asset is a single composition sized to the Figma frame, so it
 * spans the frame once instead of tiling.
 *
 * The band paints no background of its own. The art is transparent above the
 * rooflines, so whatever it sits on carries through -- the hero gradient's
 * tail under AppTopLayout, the surface ground on the landing page -- and the
 * asset's own solid strip closes the page beneath it. That strip is #B5D6FB,
 * within a hair of --color-brand-soft where the gradient ends, which is why
 * the seam does not read.
 *
 * Sizing: from `lg` up it spans the width at its authored 6.08:1. Below that
 * the same ratio would collapse the band to ~60px on a phone, so it takes a
 * fixed height and crops horizontally instead -- `object-bottom` keeps the
 * ground strip and the skyline simply shows fewer towers.
 *
 * `shrink-0` is vestigial for the two callers that position this absolutely
 * (AppTopLayout and the landing page's GetStarted) -- it costs nothing there
 * and still guards any future caller that drops the band into a flex column,
 * where a long list would otherwise compress it rather than scroll past it.
 */
export function Skyline({ className }: { className?: string }) {
    return (
        <img
            src={buildingSrc}
            alt=""
            aria-hidden="true"
            className={cn(
                'block h-[130px] w-full shrink-0 object-cover object-bottom sm:h-[180px] lg:h-auto',
                className,
            )}
        />
    );
}
