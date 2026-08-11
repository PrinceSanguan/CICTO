import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    FileText,
    Layers,
    Lock,
    QrCode,
    User,
} from 'lucide-react';
import help from '@/routes/help';

const ICONS: Record<string, typeof FileText> = {
    'file-text': FileText,
    layers: Layers,
    'qr-code': QrCode,
    user: User,
    lock: Lock,
    'alert-triangle': AlertTriangle,
};

type Props = {
    article: {
        slug: string;
        title: string;
        summary: string;
        category: string;
        icon: string;
        intro: string;
        steps?: string[];
        sections?: { title: string; body: string }[];
        closing?: { label: string | null; text: string };
        unavailable_without_mail?: string;
    };
    support: { mail_configured: boolean };
};

/**
 * A knowledge base article, to the client's article design: a white card with
 * the article's icon beside its title, a one-line intro, numbered steps and/or
 * titled sections, then a closing tip.
 *
 * Text supports `**bold**` and `` `code` `` only, rendered by splitting on the
 * markers rather than pulling in a markdown parser. The content is first-party
 * PHP in KnowledgeBase.php, not user input, but keeping the renderer this small
 * means there is no HTML injection surface at all.
 */
export default function HelpArticle({ article, support }: Props) {
    const Icon = ICONS[article.icon] ?? FileText;

    return (
        <>
            <Head title={article.title} />

            <Link
                href={help.knowledgeBase()}
                className="inline-flex items-center gap-1 text-sm font-bold text-white/90 no-underline transition hover:text-white"
            >
                <ChevronLeft aria-hidden="true" className="size-4" />
                Back
            </Link>

            <article className="mt-4 rounded-xl bg-white px-6 py-8 shadow-xl sm:px-12 sm:py-12">
                {/* Icon beside a centred title block, as the design has it. */}
                <header className="flex items-center justify-center gap-5">
                    <Icon
                        aria-hidden="true"
                        strokeWidth={1.5}
                        className="size-12 shrink-0 text-navy"
                    />

                    <div>
                        <h1 className="text-2xl font-extrabold tracking-tight text-navy sm:text-3xl">
                            {article.title}
                        </h1>
                        <p className="text-[15px] font-bold text-navy">
                            {article.summary}
                        </p>
                    </div>
                </header>

                <div className="mt-8 space-y-6 text-[17px] leading-relaxed font-bold text-navy">
                    {/*
                        Shown above the steps, not below them: somebody reading
                        this article is already stuck, and finding out on step 4
                        that the whole procedure cannot work here is worse than
                        being told before starting.
                    */}
                    {article.unavailable_without_mail &&
                        !support.mail_configured && (
                            <p
                                role="status"
                                className="flex gap-3 rounded-lg bg-[#FDF3DC] px-5 py-4 text-[15px] leading-relaxed font-semibold text-[#7A5B12]"
                            >
                                <AlertTriangle
                                    aria-hidden="true"
                                    className="mt-0.5 size-5 shrink-0"
                                />
                                <span>{article.unavailable_without_mail}</span>
                            </p>
                        )}

                    <p>
                        <Inline text={article.intro} />
                    </p>

                    {article.steps && article.steps.length > 0 && (
                        <div>
                            <p>Steps:</p>

                            {/*
                                An ordered list, not hand-numbered text: a
                                screen reader announces "list, 5 items" and the
                                numbers stay correct if one is ever inserted.
                            */}
                            <ol className="mt-4 space-y-1">
                                {article.steps.map((step, index) => (
                                    <li
                                        key={index}
                                        className="flex gap-2"
                                        aria-setsize={article.steps?.length}
                                        aria-posinset={index + 1}
                                    >
                                        <span aria-hidden="true">
                                            {index + 1}.
                                        </span>
                                        <span>
                                            <Inline text={step} />
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    )}

                    {article.sections?.map((section) => (
                        <div key={section.title}>
                            <p>{section.title}</p>
                            <p className="font-bold">
                                <Inline text={section.body} />
                            </p>
                        </div>
                    ))}

                    {article.closing && (
                        <p>
                            {article.closing.label && (
                                <>{article.closing.label}: </>
                            )}
                            <Inline text={article.closing.text} />
                        </p>
                    )}
                </div>
            </article>
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
                    // The body is already bold, so emphasis is carried by the
                    // link colour rather than a second weight nobody can see.
                    return (
                        <strong key={index} className="text-link">
                            {part.slice(2, -2)}
                        </strong>
                    );
                }

                if (part.startsWith('`') && part.endsWith('`')) {
                    return (
                        <code
                            key={index}
                            className="rounded bg-[#F1F5FA] px-1.5 py-0.5 font-mono text-[0.9em] font-semibold text-navy"
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
