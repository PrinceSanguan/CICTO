import { CictoLockup } from '@/components/auth/cicto-lockup';

/**
 * A white bar carrying the lockup, sitting above the hero gradient.
 *
 * It used to be transparent -- the nav painted straight onto the gradient --
 * with four centred links: Home, Track Documents, Reports and Help. Both went
 * on 2026-08-25, when the client supplied a comp showing a plain white bar
 * with nothing in it but the logo. `NAV` has been deleted from content.ts
 * rather than left as an unreferenced export.
 *
 * Dropping the links costs the landing page nothing structurally: every one of
 * the three feature links pointed at a screen behind auth, so a visitor still
 * reaches them the same way they always did in practice -- through the Login
 * action, after which RoleAwareLoginResponse replays the intended URL. `#home`
 * scrolled to the top of a page that opens at the top.
 *
 * The logo is `CictoLockup`, the same horizontal mark-plus-wordmark the auth
 * screens and the signed-in top nav use, and that is what the comp draws: the
 * mark on the left with a navy "CICTO" over "Baliwag City". It replaced
 * `BrandLogo`, which drew the client's raw artwork instead -- a SQUARE,
 * vertically stacked lockup with a *green* "CICTO" over "BALIWAG". That was
 * both the wrong arrangement and, at `h-12`, visibly smaller than the comp;
 * the swap fixes the proportion and the size in one move, and it means a
 * visitor sees the same lockup on the landing page and on the login screen
 * they are sent to. `brand-logo.tsx` had no other caller and is deleted.
 *
 * `py-4` rather than `py-3` because the lockup is 56px tall against the old
 * image's 48px: the comp's bar is a little over 1.5x the lockup's height, and
 * 16px of padding reproduces that. It is the whole reason the bar grew.
 *
 * Full-bleed padding rather than the page's `max-w-7xl` container, because the
 * comp sets the logo ~30px from the frame edge -- well left of the feature
 * cards below it, which do sit on the centred grid.
 *
 * The bar is opaque, so it covers the top of the gradient it is mounted on
 * (see hero.tsx). That is intentional and cheaper than lifting the nav out of
 * the hero section: the gradient simply becomes visible from the bar's lower
 * edge down.
 */
export function SiteNav() {
    return (
        <header className="relative z-20 bg-white shadow-[0_1px_3px_rgb(16_42_82/0.06)]">
            <div className="flex items-center px-6 py-4 lg:px-8">
                {/*
                  No `alt` to manage here: CictoLockup hides the cropped mark
                  from the accessibility tree and sets the wordmark as real
                  text, so the link names itself "CICTO Baliwag City".
                */}
                <a href="#home" className="shrink-0">
                    <CictoLockup />
                </a>
            </div>
        </header>
    );
}
