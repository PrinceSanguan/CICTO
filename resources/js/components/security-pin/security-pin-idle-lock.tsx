import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import securityPin from '@/routes/security-pin';
import type { Auth } from '@/types';

/** Last activity in ANY tab of this app, so a background tab never locks one in use. */
const ACTIVITY_KEY = 'cicto.securityPin.lastActivity';
const CHECK_EVERY_MS = 10_000;
const HEARTBEAT_EVERY_MS = 60_000;
const SHARE_EVERY_MS = 5_000;
const ACTIVITY_EVENTS = [
    'pointerdown',
    'pointermove',
    'keydown',
    'wheel',
    'scroll',
    'touchstart',
] as const;

/**
 * Laravel's CSRF token, as Inertia itself sends it.
 *
 * Current browsers pass the same-origin check on their own (Sec-Fetch-Site),
 * but Safari before 16.4 -- still on older office iPads -- does not send that
 * header, and there the heartbeat would be refused with 419 and a person
 * reading would be locked out mid-document.
 */
function xsrfHeader(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}

/**
 * Locks documents again after the idle timeout (client request, 2026-09-25).
 *
 * The adviser's scenario was a computer left signed in WITH A DOCUMENT OPEN.
 * Asking for the PIN only when a document is opened would not cover that: the
 * document is already on screen. So while the session is unlocked, this
 * watches for the person -- mouse, keys, scrolling, touch -- and once nobody
 * has touched ANY tab of the app for `idle_seconds`, it asks the server to
 * lock. The server answers by redrawing the page, and an open document comes
 * back as the PIN prompt.
 *
 * Two halves, because the server cannot see a person reading:
 *  - activity here posts a heartbeat at most once a minute, which keeps the
 *    server's unlock alive while somebody is actually reading;
 *  - silence here is what triggers the lock. The server also lets an unlock
 *    lapse on its own after the same time, so a closed laptop or a killed tab
 *    cannot leave documents open.
 *
 * Mounted once in each signed-in layout; renders nothing.
 */
export function SecurityPinIdleLock() {
    const pin = usePage<{ auth: Auth }>().props.auth?.securityPin;
    const unlocked = pin?.unlocked === true;
    const idleMs = (pin?.idle_seconds ?? 0) * 1000;

    useEffect(() => {
        if (!unlocked || idleMs <= 0) {
            return;
        }

        let lastLocal = Date.now();
        let lastShared = 0;
        let lastBeat = Date.now();
        let locking = false;

        // Wrapped: storage can be blocked (private windows, strict settings),
        // and then each tab simply keeps its own clock.
        const readShared = () => {
            try {
                return Number(window.localStorage.getItem(ACTIVITY_KEY)) || 0;
            } catch {
                return 0;
            }
        };

        const record = (now: number) => {
            lastLocal = now;

            if (now - lastShared < SHARE_EVERY_MS) {
                return;
            }

            lastShared = now;

            try {
                window.localStorage.setItem(ACTIVITY_KEY, String(now));
            } catch {
                // See readShared().
            }
        };

        const lock = () => {
            if (locking) {
                return;
            }

            locking = true;

            // preserveState: on any page but a document, the redraw after the
            // lock must not wipe what the person was typing -- a half-filled
            // Submit Document form survives it. On a document the server
            // answers with a different page (the PIN prompt), which is drawn
            // fresh regardless, so nothing of the document is kept.
            router.post(
                securityPin.lock.url(),
                { reason: 'idle' },
                { preserveState: true, preserveScroll: true },
            );
        };

        const beat = () => {
            fetch(securityPin.heartbeat.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', ...xsrfHeader() },
            })
                .then((response) => {
                    // The server's unlock has already lapsed: catch the page up.
                    if (response.status === 423) {
                        lock();
                    }
                })
                .catch(() => {
                    // Offline for a moment. The idle check still runs.
                });
        };

        const onActivity = () => {
            const now = Date.now();

            record(now);

            if (now - lastBeat >= HEARTBEAT_EVERY_MS) {
                lastBeat = now;
                beat();
            }
        };

        // A request still in flight -- a large upload on a slow line -- is the
        // person waiting, not the person gone. Locking then would cancel it.
        let inFlight = 0;
        const stopStart = router.on('start', () => {
            inFlight++;
        });
        const stopFinish = router.on('finish', () => {
            inFlight = Math.max(0, inFlight - 1);
            record(Date.now());
        });

        const check = () => {
            if (inFlight > 0) {
                record(Date.now());

                return;
            }

            if (Date.now() - Math.max(lastLocal, readShared()) >= idleMs) {
                lock();
            }
        };

        // Timers do not run while a laptop sleeps, so check the moment the
        // page is looked at again instead of up to ten seconds later.
        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                check();
            }
        };

        record(Date.now());

        const timer = window.setInterval(check, CHECK_EVERY_MS);

        ACTIVITY_EVENTS.forEach((event) =>
            window.addEventListener(event, onActivity, {
                capture: true,
                passive: true,
            }),
        );
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            stopStart();
            stopFinish();
            window.clearInterval(timer);
            ACTIVITY_EVENTS.forEach((event) =>
                window.removeEventListener(event, onActivity, {
                    capture: true,
                }),
            );
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [unlocked, idleMs]);

    return null;
}
