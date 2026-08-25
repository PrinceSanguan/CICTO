import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    ChevronLeft,
    FileText,
    Layers,
    Lock,
    QrCode,
    Search,
    User,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import help from '@/routes/help';

type Article = {
    slug: string;
    title: string;
    summary: string;
    category: string;
    icon: string;
};

type Props = {
    articles: Article[];
    categories: Record<string, string>;
};

const ICONS: Record<string, typeof FileText> = {
    'file-text': FileText,
    layers: Layers,
    'qr-code': QrCode,
    user: User,
    lock: Lock,
    'alert-triangle': AlertTriangle,
};

/**
 * §23 knowledge base index: search, category chips, article list.
 *
 * The vertical rhythm here is DELIBERATELY tighter than the Figma, and that is
 * the one place this page knowingly departs from it. The comp is drawn on a
 * ~1030px-tall frame; the app's nav is a fixed `h-20`, so on a 795px viewport
 * -- an ordinary laptop -- the page has 715px to work in and the comp's
 * spacing needs roughly 850. Reproducing it literally is what put a scrollbar
 * on a screen the client wants to fit whole.
 *
 * So the gaps are shaved evenly rather than in one place: no single space is
 * more than a step off the comp, and the page clears the fold with ~60px in
 * hand. The card padding is the exception that improves things -- `py-2.5`
 * puts the article cards at ~59px, which is nearer the comp's 58 than the
 * `py-3` they had.
 *
 * If the client ever wants the comp's exact spacing back, it costs a
 * scrollbar; there is no third option at this nav height.
 */
export default function KnowledgeBaseIndex({ articles, categories }: Props) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<string | null>(null);

    // Filtered in the browser: six articles is not worth a round trip, and it
    // keeps the page usable if the connection drops.
    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return articles.filter((article) => {
            if (category !== null && article.category !== category) {
                return false;
            }

            if (needle === '') {
                return true;
            }

            return (
                article.title.toLowerCase().includes(needle) ||
                article.summary.toLowerCase().includes(needle)
            );
        });
    }, [articles, category, query]);

    return (
        <>
            <Head title="Knowledge Base" />

            <Link
                href={help.index()}
                className="inline-flex items-center gap-1 text-sm font-bold text-white/90 transition hover:text-white"
            >
                <ChevronLeft className="size-4" />
                Back
            </Link>

            <h1 className="mt-3 text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                Knowledge Base
            </h1>
            <p className="mt-1 text-[15px] font-medium text-white/90">
                Browse helpful articles and FAQs to guide you.
            </p>

            {/*
                No `max-w-3xl`. The bar was capped at 768px and left-aligned, so
                on a wide screen it stopped less than halfway across and read as
                floating in the top-left corner rather than as the page's
                primary control -- "pwede pakilakihan nito? nasa center rin
                dapat 'to" on the client's markup. The Figma runs it the full
                width of the container, flush with the heading's left edge and
                the chip row's right edge, which is what spanning the container
                gives for free: no width to keep in sync, and it centres itself.
            */}
            <form
                role="search"
                onSubmit={(event) => event.preventDefault()}
                className="mt-5 flex overflow-hidden rounded-lg shadow-lg"
            >
                <div className="relative flex-1">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-5 size-6 -translate-y-1/2 text-[#8A9AAE]"
                    />
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search articles…"
                        aria-label="Search articles"
                        className="h-14 w-full border-0 bg-white pr-4 pl-14 text-base text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none focus-visible:ring-inset"
                    />
                </div>

                {/*
                    Decorative: filtering already happens as you type, so this
                    only exists to match the design. It is not a submit button
                    pretending to do something.
                */}
                <span
                    aria-hidden="true"
                    className="flex w-28 items-center justify-center bg-[#3B72C4]"
                >
                    <Search className="size-6 text-white" />
                </span>
            </form>

            {/*
                `xl:flex-auto` on the chips themselves, so the row ends flush
                with the search bar's right edge instead of stopping wherever
                the labels happen to run out -- the client marked the two up
                side by side. The Figma's six labels happen to measure the full
                container; ours fall ~90px short of it, and sharing that
                remainder out is what closes the gap without hard-coding a
                width that a seventh category would break.

                Only from `xl`, where all six hold one line. Below that they
                wrap, and growing them per-line would make a line of two chips
                twice the size of a line of four.
            */}
            <div className="mt-3 flex flex-wrap gap-3">
                <Chip
                    active={category === null}
                    onClick={() => setCategory(null)}
                >
                    All
                </Chip>
                {Object.entries(categories).map(([key, label]) => (
                    <Chip
                        key={key}
                        active={category === key}
                        onClick={() => setCategory(key)}
                    >
                        {label}
                    </Chip>
                ))}
            </div>

            <h2 className="mt-7 text-center text-2xl font-bold text-navy">
                {query || category ? 'Articles' : 'Featured Articles'}
            </h2>

            {visible.length === 0 ? (
                <p className="mt-6 rounded-xl bg-white p-8 text-center text-sm text-copy shadow-xl">
                    No articles match that search. Try a different word, or{' '}
                    <Link
                        href={help.ticket()}
                        className="font-bold text-link hover:underline"
                    >
                        raise a ticket
                    </Link>
                    .
                </p>
            ) : (
                <ul className="mx-auto mt-5 grid max-w-4xl gap-3 md:grid-cols-2">
                    {visible.map((article) => {
                        const Icon = ICONS[article.icon] ?? FileText;

                        return (
                            <li key={article.slug}>
                                <Link
                                    href={help.article(article.slug)}
                                    className="flex h-full items-center gap-3 rounded-lg bg-white px-4 py-2.5 shadow-md transition hover:shadow-lg"
                                >
                                    <Icon
                                        aria-hidden="true"
                                        className="size-7 shrink-0 text-navy"
                                        strokeWidth={1.5}
                                    />
                                    <span>
                                        <span className="block text-[15px] font-bold text-navy">
                                            {article.title}
                                        </span>
                                        <span className="block text-xs text-copy">
                                            {article.summary}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}

            {/*
                max-w-2xl. The Figma draws this panel at about a third of the
                frame and lets "Submit a ticket or contact our support team"
                wrap onto two lines, and reproducing that measure made it read
                as a squat block rather than a bar -- the client asked for it
                long. At 672px the subtitle holds one line, which is what makes
                the difference: the wrap was the whole reason it looked stubby.
                Still well inside the container, so it cannot push the page
                wider than the screen.
            */}
            <aside className="mx-auto mt-6 flex max-w-2xl flex-col items-center gap-4 rounded-xl bg-white/85 p-4 text-center shadow-xl sm:flex-row sm:text-left">
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
                    className="inline-flex items-center gap-2 rounded-md bg-[#3B72C4] px-6 py-3 text-sm font-bold text-white transition hover:bg-[#31629F]"
                >
                    Contact Support
                    <ArrowRight aria-hidden="true" className="size-4" />
                </Link>
            </aside>
        </>
    );
}

function Chip({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={`rounded-md px-6 py-2.5 text-[15px] font-bold transition xl:flex-auto ${
                active
                    ? 'bg-[#3B72C4] text-white'
                    : 'bg-white text-navy hover:bg-[#EEF4FD]'
            }`}
        >
            {children}
        </button>
    );
}

KnowledgeBaseIndex.layout = {
    breadcrumbs: [
        { title: 'Help', href: help.index() },
        { title: 'Knowledge Base', href: help.knowledgeBase() },
    ],
};
