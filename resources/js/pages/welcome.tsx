import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { login, logout } from '@/routes';
import { ORG } from '@/components/landing/content';
import { Hero } from '@/components/landing/hero';
import { navActionClass } from '@/components/landing/site-nav';
import { Skyline } from '@/components/landing/skyline';
import { WhyChoose } from '@/components/landing/why-choose';

/**
 * CICTO landing page, built to the client's Figma.
 *
 * The design's nav carries a single red action, so there is no Register link
 * and therefore no `register()` call anywhere on this page. That is also why
 * the Chisel `registration` markers are gone: with nothing to guard they would
 * be noise, and `php artisan chisel` simply finds no section to strip either
 * way. If a Register link is added later it must live in THIS file wrapped in
 * `/* @chisel-registration *` markers -- `chisel-paths.php` maps the `welcome`
 * key to this exact path and knows nothing about `components/landing/`.
 *
 * The nav still takes its action as a prop so that constraint stays easy to
 * honour.
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
                            className={navActionClass}
                        >
                            Logout
                        </Link>
                    ) : (
                        <Link href={login()} className={navActionClass}>
                            Login
                        </Link>
                    )
                }
            />

            <WhyChoose />

            <Skyline />
        </>
    );
}
