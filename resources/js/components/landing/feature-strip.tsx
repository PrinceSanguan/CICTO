import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { HERO_FEATURES } from './content';
import { FeatureGlyph } from './icons';

/**
 * The three cards sitting in the surface panel that overlaps the hero.
 * Icon on the left, title over one line of body copy on the right.
 *
 * `linked` makes each card open the screen it names. It is on for a signed-in
 * visitor and off for everybody else -- see the note on `Feature.href` in
 * content.ts. The two states are the same box either way; only the element
 * changes, so a card can never look clickable while being inert.
 */
export function FeatureStrip({ linked = false }: { linked?: boolean }) {
    return (
        <div className="mx-auto grid max-w-7xl gap-5 px-6 sm:grid-cols-3 lg:gap-10">
            {HERO_FEATURES.map((feature) => {
                const shell =
                    'flex items-center gap-4 rounded-lg border border-hairline bg-panel px-5 py-4 shadow-[0_1px_3px_rgb(16_42_82/0.06)]';

                const inner = (
                    <>
                        <FeatureGlyph
                            name={feature.icon}
                            className="size-11 shrink-0"
                        />
                        <div className="min-w-0">
                            <h3 className="text-[15px] leading-tight font-bold text-link">
                                {feature.title}
                            </h3>
                            <p className="mt-1 text-[13px] leading-snug text-copy">
                                {feature.body}
                            </p>
                        </div>
                    </>
                );

                return linked ? (
                    <Link
                        key={feature.title}
                        href={feature.href}
                        className={cn(
                            shell,
                            'transition hover:shadow-[0_4px_12px_rgb(16_42_82/0.12)]',
                        )}
                    >
                        {inner}
                    </Link>
                ) : (
                    <article key={feature.title} className={shell}>
                        {inner}
                    </article>
                );
            })}
        </div>
    );
}
