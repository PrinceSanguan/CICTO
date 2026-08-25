import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { WHY } from './content';
import { FeatureGlyph } from './icons';

/**
 * Centred heading over a 2x2 card grid, on the same surface as the panel above.
 *
 * No `id="reports"` here any more. The nav's Reports item used to scroll to this
 * block, which is a "Why Choose CICTO" pitch and not a report; it now opens the
 * actual Reports screen. Nothing else linked here.
 *
 * `linked` works exactly as it does in FeatureStrip: on for a signed-in
 * visitor, off for everybody else.
 */
export function WhyChoose({ linked = false }: { linked?: boolean }) {
    return (
        <section className="scroll-mt-20 bg-surface pt-16 lg:pt-24">
            <div className="mx-auto max-w-7xl px-6">
                <h2 className="text-center text-[clamp(1.4rem,2.6vw,2rem)] leading-tight font-bold text-balance text-navy">
                    {WHY.heading}
                </h2>

                <div className="mt-12 grid gap-6 md:grid-cols-2 lg:mt-14 lg:gap-x-9 lg:gap-y-8">
                    {WHY.items.map((item) => {
                        const shell =
                            'flex items-center gap-6 rounded-xl border border-hairline bg-panel px-7 py-7 shadow-[0_2px_6px_rgb(16_42_82/0.05)]';

                        const inner = (
                            <>
                                <FeatureGlyph
                                    name={item.icon}
                                    className="size-14 shrink-0"
                                />
                                <div className="min-w-0">
                                    <h3 className="text-[17px] leading-tight font-bold text-link">
                                        {item.title}
                                    </h3>
                                    <p className="mt-1.5 text-[13px] leading-snug text-copy">
                                        {item.body}
                                    </p>
                                </div>
                            </>
                        );

                        return linked ? (
                            <Link
                                key={item.title}
                                href={item.href}
                                className={cn(
                                    shell,
                                    'transition hover:shadow-[0_6px_16px_rgb(16_42_82/0.12)]',
                                )}
                            >
                                {inner}
                            </Link>
                        ) : (
                            <article key={item.title} className={shell}>
                                {inner}
                            </article>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
