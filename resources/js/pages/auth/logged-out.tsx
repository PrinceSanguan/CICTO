import { Head, Link } from '@inertiajs/react';
import { LogIn, MoveRight } from 'lucide-react';
import cictoLogo from '@/assets/cicto-baliwag-logo.png';
import { home, login } from '@/routes';

/** Share of the square artwork's height that the mark occupies. */
const MARK_FRACTION = 0.67;

/**
 * §4's sign-out confirmation.
 *
 * Fortify used to drop you on the landing page, which looks identical whether
 * or not the sign-out actually happened -- on a shared counter terminal that
 * ambiguity is the whole problem. This page says it plainly.
 *
 * Standalone rather than inside the app shell: there is no session left by the
 * time it renders, so a sidebar of links that all bounce to the login screen
 * would be worse than none.
 */
export default function LoggedOut() {
    return (
        <>
            <Head title="Signed out" />

            <main className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-[#3B72C4] via-[#8FB4E4] to-[#EAF2FD] px-6 py-16">
                <div className="w-full max-w-md rounded-2xl bg-white px-6 py-10 text-center shadow-2xl sm:px-10">
                    {/*
                        The mark only, cropped out of the square stacked
                        artwork the same way CictoLockup does it -- the design
                        shows the roundel here without the wordmark beneath.
                    */}
                    <div
                        aria-hidden="true"
                        className="relative mx-auto aspect-square w-20 overflow-hidden"
                    >
                        <img
                            src={cictoLogo}
                            alt=""
                            className="absolute top-0 left-1/2 max-w-none -translate-x-1/2"
                            style={{ width: `${100 / MARK_FRACTION}%` }}
                        />
                    </div>

                    <h1 className="mt-6 text-2xl font-extrabold tracking-tight text-navy">
                        You&rsquo;ve been logged out
                    </h1>

                    <p className="mx-auto mt-3 max-w-[18rem] text-[15px] leading-relaxed text-copy">
                        You have been securely logged out of your account.
                    </p>

                    <Link
                        href={login()}
                        className="mt-8 flex w-full items-center justify-center gap-2 rounded-lg bg-[#3B72C4] px-6 py-3.5 text-[15px] font-bold text-white no-underline shadow-lg transition hover:bg-[#31629F]"
                    >
                        <LogIn aria-hidden="true" className="size-5" />
                        Back to login
                    </Link>

                    <Link
                        href={home()}
                        className="mt-4 inline-flex items-center gap-1.5 text-sm font-bold text-link no-underline hover:underline"
                    >
                        Go to Homepage
                        <MoveRight aria-hidden="true" className="size-4" />
                    </Link>
                </div>
            </main>
        </>
    );
}
