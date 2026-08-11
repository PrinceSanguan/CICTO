import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
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

/** §23 knowledge base index: search, category chips, article list. */
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

            <h1 className="mt-4 text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                Knowledge Base
            </h1>
            <p className="mt-1 text-[15px] font-medium text-white/90">
                Browse helpful articles and FAQs to guide you.
            </p>

            <form
                role="search"
                onSubmit={(event) => event.preventDefault()}
                className="mt-6 flex max-w-3xl overflow-hidden rounded-lg shadow-lg"
            >
                <div className="relative flex-1">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#8A9AAE]"
                    />
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search articles…"
                        aria-label="Search articles"
                        className="h-14 w-full border-0 bg-white pr-4 pl-12 text-[15px] text-navy placeholder:text-[#8A9AAE] focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none focus-visible:ring-inset"
                    />
                </div>

                {/*
                    Decorative: filtering already happens as you type, so this
                    only exists to match the design. It is not a submit button
                    pretending to do something.
                */}
                <span
                    aria-hidden="true"
                    className="flex w-20 items-center justify-center bg-[#3B72C4]"
                >
                    <Search className="size-5 text-white" />
                </span>
            </form>

            <div className="mt-4 flex flex-wrap gap-3">
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

            <h2 className="mt-10 text-center text-2xl font-bold text-navy">
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
                <ul className="mx-auto mt-6 grid max-w-4xl gap-4 md:grid-cols-2">
                    {visible.map((article) => {
                        const Icon = ICONS[article.icon] ?? FileText;

                        return (
                            <li key={article.slug}>
                                <Link
                                    href={help.article(article.slug)}
                                    className="flex h-full items-center gap-3 rounded-lg bg-white px-4 py-3 shadow-md transition hover:shadow-lg"
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

            <aside className="mx-auto mt-10 flex max-w-2xl flex-col items-center gap-4 rounded-xl bg-white/85 p-5 text-center shadow-xl sm:flex-row sm:text-left">
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
            className={`rounded-md px-4 py-2 text-sm font-bold transition ${
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
