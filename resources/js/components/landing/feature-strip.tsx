import { HERO_FEATURES } from './content';
import { FeatureGlyph } from './icons';

/**
 * The three cards sitting in the surface panel that overlaps the hero.
 * Icon on the left, title over one line of body copy on the right.
 */
export function FeatureStrip() {
    return (
        <div className="mx-auto grid max-w-7xl gap-5 px-6 sm:grid-cols-3 lg:gap-10">
            {HERO_FEATURES.map((feature) => (
                <article
                    key={feature.title}
                    className="flex items-center gap-4 rounded-lg border border-hairline bg-panel px-5 py-4 shadow-[0_1px_3px_rgb(16_42_82/0.06)]"
                >
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
                </article>
            ))}
        </div>
    );
}
