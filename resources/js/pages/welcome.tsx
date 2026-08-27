import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { AppTopNav } from '@/components/app-top-nav';
import { GET_STARTED, HERO, ORG } from '@/components/landing/content';
import {
    GetStarted,
    getStartedActionClass,
} from '@/components/landing/get-started';
import { Hero, heroActionClass } from '@/components/landing/hero';
import { SiteNav } from '@/components/landing/site-nav';
import { Skyline } from '@/components/landing/skyline';
import { WhyChoose } from '@/components/landing/why-choose';
import { login } from '@/routes';

/**
 * Home, for both audiences. One route, one composition, two states.
 *
 * The client asked on 2026-08-26 for the signed-in home page to be this page
 * -- "ganito rin dapat yung home page, same as the home/login page" -- with
 * three things changed: no "Login to Your Account", no "Ready to Get Started?"
 * or "Login Now", the feature cards clickable, and the app's own top
 * navigation instead of the landing bar. That is what `signedIn` switches:
 *
 *   nav      SiteNav (logo only)        ->  AppTopNav (Track/Reports/Help)
 *   hero     "Login to Your Account"    ->  nothing under the sub-line
 *   cards    inert articles             ->  links to the screen each names
 *   closing  "Ready to Get Started?"    ->  the skyline alone
 *
 * Everything else -- the gradient, the art, the panel, "Why Choose CICTO" --
 * is shared, which is the point: the two are the same page.
 *
 * Because this is now Home for a signed-in user too, `/` is where the LOCKUP
 * in either bar points. The main nav's own "Home" item was retired on
 * 2026-08-27 -- the client asked for the logo to be the only way home -- so
 * both states reach this page the same way. See `lib/nav.ts`.
 *
 * The design carries a single action, in brand blue, so there is no Register
 * link and therefore no `register()` call anywhere on this page. That is also
 * why the Chisel `registration` markers are gone: with nothing to guard they
 * would be noise, and `php artisan chisel` simply finds no section to strip
 * either way. If a Register link is added later it must live in THIS file
 * wrapped in `/* @chisel-registration *` markers -- `chisel-paths.php` maps
 * the `welcome` key to this exact path and knows nothing about
 * `components/landing/`.
 *
 * The `cicto-landing` class also has to be managed here: `app.blade.php` sets
 * it server-side for the first paint, but an Inertia visit to /login never
 * re-renders the shell, so it has to be released on unmount.
 */
export default function Welcome() {
    const { auth } = usePage().props;
    const signedIn = Boolean(auth.user);

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

            {signedIn ? <AppTopNav /> : <SiteNav />}

            <Hero
                linked={signedIn}
                authSlot={
                    signedIn ? null : (
                        <Link href={login()} className={heroActionClass}>
                            {HERO.action}
                        </Link>
                    )
                }
            />

            <WhyChoose linked={signedIn} />

            {/*
                A signed-in visitor is already started, so the closing band is
                the skyline on its own -- the same art GetStarted paints behind
                its heading, just without the heading and the button. It needs
                its own top margin because GetStarted's padding was what stood
                the band off the "Why Choose" cards.
            */}
            {signedIn ? (
                <Skyline className="mt-16 lg:mt-24" />
            ) : (
                <GetStarted
                    actionSlot={
                        <Link href={login()} className={getStartedActionClass}>
                            {GET_STARTED.action}
                        </Link>
                    }
                />
            )}
        </>
    );
}
