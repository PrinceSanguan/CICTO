import { useEffect, useState } from 'react';
import { flushSync } from 'react-dom';

/**
 * True while the browser is laying the page out for paper.
 *
 * For what CSS cannot do on its own. Recharts sizes its charts by measuring the
 * screen, so a chart drawn for a 1280px window prints clipped or overflowing on
 * a sheet of bond paper; the Reports page redraws its charts at fixed,
 * paper-sized dimensions while this is true.
 *
 * `flushSync` because the browser takes its print snapshot as soon as the
 * `beforeprint` handler returns -- a normal batched update would land after it
 * (the same reason as PrintMasthead's timestamp). Both the event and the print
 * media query are watched: Ctrl+P and the Print button both fire the event, and
 * the query covers browsers that print without it.
 */
export function usePrinting(): boolean {
    const [printing, setPrinting] = useState(false);

    useEffect(() => {
        const start = () => flushSync(() => setPrinting(true));
        const stop = () => setPrinting(false);
        const media = window.matchMedia('print');
        const onMedia = (event: MediaQueryListEvent) =>
            event.matches ? start() : stop();

        window.addEventListener('beforeprint', start);
        window.addEventListener('afterprint', stop);
        media.addEventListener('change', onMedia);

        return () => {
            window.removeEventListener('beforeprint', start);
            window.removeEventListener('afterprint', stop);
            media.removeEventListener('change', onMedia);
        };
    }, []);

    return printing;
}
