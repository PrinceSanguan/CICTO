import { Head, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import help from '@/routes/help';

type Props = {
    article: {
        slug: string;
        title: string;
        summary: string;
        category: string;
        body: string[];
    };
    categories: Record<string, string>;
};

/**
 * A knowledge base article.
 *
 * Paragraphs support `**bold**` and `` `code` `` only, rendered by splitting on
 * the markers rather than by pulling in a markdown parser. The content is
 * first-party PHP in KnowledgeBase.php, not user input, but keeping the
 * renderer this small means there is no HTML injection surface at all.
 */
export default function HelpArticle({ article, categories }: Props) {
    return (
        <>
            <Head title={article.title} />

            <Link
                href={help.knowledgeBase()}
                className="inline-flex items-center gap-1 text-sm font-bold text-white/90 transition hover:text-white"
            >
                <ChevronLeft className="size-4" />
                Back to Knowledge Base
            </Link>

            <article className="mt-4 rounded-xl bg-white p-6 shadow-xl sm:p-10">
                <p className="text-xs font-bold tracking-wide text-link uppercase">
                    {categories[article.category] ?? 'Help'}
                </p>

                <h1 className="mt-1 text-2xl font-bold text-navy sm:text-3xl">
                    {article.title}
                </h1>
                <p className="mt-1 text-[15px] text-copy">{article.summary}</p>

                <div className="mt-6 space-y-4">
                    {article.body.map((paragraph, index) => (
                        <p
                            key={index}
                            className="text-[15px] leading-relaxed text-copy"
                        >
                            <Inline text={paragraph} />
                        </p>
                    ))}
                </div>
            </article>

            <aside className="mt-6 flex flex-col items-center gap-4 rounded-xl bg-white/85 p-5 text-center shadow-xl sm:flex-row sm:text-left">
                <div className="flex-1">
                    <p className="text-lg font-bold text-navy">
                        Still need help?
                    </p>
                    <p className="text-sm text-copy">
                        Submit a ticket or contact our support team
                    </p>
                </div>

                <Link
                    href={help.contact()}
                    className="rounded-md bg-[#3B72C4] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#31629F]"
                >
                    Contact Support
                </Link>
            </aside>
        </>
    );
}

/** Renders `**bold**` and `` `code` `` as elements, everything else as text. */
function Inline({ text }: { text: string }) {
    const parts = text.split(/(\*\*[^*]+\*\*|`[^`]+`)/g);

    return (
        <>
            {parts.map((part, index) => {
                if (part.startsWith('**') && part.endsWith('**')) {
                    return (
                        <strong key={index} className="font-bold text-navy">
                            {part.slice(2, -2)}
                        </strong>
                    );
                }

                if (part.startsWith('`') && part.endsWith('`')) {
                    return (
                        <code
                            key={index}
                            className="rounded bg-[#F1F5FA] px-1.5 py-0.5 font-mono text-[0.9em] text-navy"
                        >
                            {part.slice(1, -1)}
                        </code>
                    );
                }

                return <span key={index}>{part}</span>;
            })}
        </>
    );
}

HelpArticle.layout = {
    breadcrumbs: [
        { title: 'Help', href: help.index() },
        { title: 'Knowledge Base', href: help.knowledgeBase() },
    ],
};
