import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { GET_STARTED, HERO, ORG } from '@/components/landing/content';
import {
    GetStarted,
    getStartedActionClass,
} from '@/components/landing/get-started';
import { Hero, heroActionClass } from '@/components/landing/hero';
import { WhyChoose } from '@/components/landing/why-choose';
import { login, logout } from '@/routes';

/**
 * CICTO landing page, built to the client's Figma.
 *
 * The design carries a single action, in brand blue, so there is no Register
 * link and therefore no `register()` call anywhere on this page. That is also why the
 * Chisel `registration` markers are gone: with nothing to guard they would be
 * noise, and `php artisan chisel` simply finds no section to strip either way.
 * If a Register link is added later it must live in THIS file wrapped in
 * `/* @chisel-registration *` markers -- `chisel-paths.php` maps the `welcome`
 * key to this exact path and knows nothing about `components/landing/`.
 *
 * Both the Hero and the closing GetStarted band take their action as a prop
 * so that constraint stays easy to honour -- every auth link on the page is
 * built here. The Hero, not the nav, is where that action is rendered: the client
 * asked on 2026-08-23 for the Login button to sit in the hero copy, beneath
 * the "Welcome to CICTO Document Tracking System" line, instead of in the
 * top-right corner of the nav where it was easy to miss.
 *
 * The `cicto-landing` class also has to be managed here: `app.blade.php` sets
 * it server-side for the first paint, but an Inertia visit to /login never
 * re-renders the shell, so it has to be released on unmount.
 */
export default function Welcome() {
    const { auth } = usePage().props;

    useEffect(() => {
        const root = document.documentElement;

        root.classList.add('cicto-landing');

        return () => root.classList.remove('cicto-landing');
    }, []);

    return (
        <>
            <Head title={ORG.system}>
                <meta
                    name="description"
                    content="Track, manage and monitor your documents efficiently. The CICTO Baliwag document tracking system with QR code scanning, real-time tracking and detailed reports."
                />
            </Head>

            <Hero
                authSlot={
                    auth.user ? (
                        <Link
                            href={logout()}
                            as="button"
                            className={heroActionClass}
                        >
                            Logout
                        </Link>
                    ) : (
                        <Link href={login()} className={heroActionClass}>
                            {HERO.action}
                        </Link>
                    )
                }
            />

            <WhyChoose />

            <GetStarted
                actionSlot={
                    auth.user ? (
                        <Link
                            href={logout()}
                            as="button"
                            className={getStartedActionClass}
                        >
                            Logout
                        </Link>
                    ) : (
                        <Link href={login()} className={getStartedActionClass}>
                            {GET_STARTED.action}
                        </Link>
                    )
                }
            />
        </>
    );
}
