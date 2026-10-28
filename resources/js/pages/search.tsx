import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    ChefHat,
    ChevronRight,
    Contact,
    ListChecks,
    Search as SearchIcon,
    ShoppingCart,
    StickyNote,
    Wallet,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { useT } from '@/lib/i18n';

// Module -> icon + nav label key, same mapping app-sidebar.tsx uses for these modules' own nav links.
const MODULES: Record<string, { icon: LucideIcon; labelKey: string }> = {
    shopping: { icon: ShoppingCart, labelKey: 'nav.shopping' },
    tasks: { icon: ListChecks, labelKey: 'nav.tasks' },
    notes: { icon: StickyNote, labelKey: 'nav.notes' },
    recipes: { icon: ChefHat, labelKey: 'nav.recipes' },
    contacts: { icon: Contact, labelKey: 'nav.contacts' },
    calendar: { icon: CalendarDays, labelKey: 'nav.calendar' },
    spending: { icon: Wallet, labelKey: 'nav.spending' },
};

type Row = { module: string; title: string; meta: string; href: string; icon: string };

const ALL = '__all__';

// Wraps every case-insensitive occurrence of `query` in <mark>. Plain substring split (not highlighting
// via dangerouslySetInnerHTML): the text is always ours (title/meta from the DB), never raw HTML.
function Highlighted({ text, query }: { text: string; query: string }) {
    if (query.trim().length < 2) return <>{text}</>;
    const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const parts = text.split(new RegExp(`(${escaped})`, 'ig'));
    return (
        <>
            {parts.map((part, i) =>
                part.toLowerCase() === query.toLowerCase() ? (
                    <mark key={i} className="bg-accent-strong text-accent-strong-foreground rounded-sm px-0.5">
                        {part}
                    </mark>
                ) : (
                    <span key={i}>{part}</span>
                ),
            )}
        </>
    );
}

export default function SearchPage({ q = '', results = [] }: { q?: string; results?: Row[] }) {
    const t = useT();
    const [query, setQuery] = useState(q);
    const inputRef = useRef<HTMLInputElement>(null);
    const [activeModule, setActiveModule] = useState<string>(ALL);

    // Autofocus on open, like a real search screen.
    useEffect(() => {
        inputRef.current?.focus();
    }, []);

    // Debounce the server round trip (results depend on sharing/visibility, so they can't be computed
    // client-side); 250ms matches the shopping list's autosave debounce elsewhere in the app.
    useEffect(() => {
        if (query === q) return; // skip the request that produced the current props (back/forward nav)
        const timer = setTimeout(() => {
            router.get('/search', { q: query }, { preserveState: true, replace: true, only: ['q', 'results'] });
        }, 250);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    // A module only gets a chip once it has a hit; re-derived per response (new q, new hit set). The chip
    // order follows SearchSources registration order (first appearance in `results`), same as the groups below.
    const moduleOrder = useMemo(() => [...new Set(results.map((r) => r.module))], [results]);
    useEffect(() => {
        if (activeModule !== ALL && !moduleOrder.includes(activeModule)) setActiveModule(ALL);
    }, [moduleOrder, activeModule]);

    const groups = useMemo(() => {
        const rows = activeModule === ALL ? results : results.filter((r) => r.module === activeModule);
        const byModule = new Map<string, Row[]>();
        for (const row of rows) {
            if (!byModule.has(row.module)) byModule.set(row.module, []);
            byModule.get(row.module)!.push(row);
        }
        return [...byModule.entries()];
    }, [results, activeModule]);

    const cancel = () => {
        if (window.history.length > 1) window.history.back();
        else router.visit('/dashboard');
    };

    return (
        <div className="bg-background min-h-svh">
            <Head title={t('common.search')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <div className="relative flex-1">
                        <SearchIcon className="text-muted-foreground pointer-events-none absolute top-1/2 left-4 size-4.5 -translate-y-1/2" />
                        <input
                            ref={inputRef}
                            type="search"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder={t('search.placeholder')}
                            // text-base = 16px: smaller zooms the page on iOS when the field is focused. The native search
                            // clear (×) is hidden: our own clear button sits in the same spot.
                            className="border-foreground bg-surface-card text-primary placeholder:text-muted-foreground focus-visible:ring-ring h-12 w-full rounded-full border-2 pr-10 pl-11 text-base focus-visible:ring-2 focus-visible:outline-hidden [&::-webkit-search-cancel-button]:appearance-none"
                        />
                        {query && (
                            <button
                                type="button"
                                onClick={() => {
                                    setQuery('');
                                    inputRef.current?.focus();
                                }}
                                aria-label={t('common.cancel')}
                                className="text-muted-foreground hover:text-primary absolute top-1/2 right-3 -translate-y-1/2"
                            >
                                <X className="size-5" />
                            </button>
                        )}
                    </div>
                    <button type="button" onClick={cancel} className="text-primary shrink-0 px-1 text-sm font-medium">
                        {t('common.cancel')}
                    </button>
                </div>

                {query.trim().length < 2 ? (
                    <p className="text-muted-foreground px-1 text-sm">{t('search.prompt')}</p>
                ) : (
                    <>
                        <div
                            data-scroll-x
                            className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                        >
                            <button
                                type="button"
                                onClick={() => setActiveModule(ALL)}
                                className={`shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors ${
                                    activeModule === ALL ? 'bg-primary text-primary-foreground border-primary' : 'border-border text-muted-foreground'
                                }`}
                            >
                                {t('search.all')} · {results.length}
                            </button>
                            {moduleOrder.map((moduleId) => {
                                const count = results.filter((r) => r.module === moduleId).length;
                                return (
                                    <button
                                        key={moduleId}
                                        type="button"
                                        onClick={() => setActiveModule(moduleId)}
                                        className={`shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors ${
                                            activeModule === moduleId
                                                ? 'bg-primary text-primary-foreground border-primary'
                                                : 'border-border text-muted-foreground'
                                        }`}
                                    >
                                        {t(MODULES[moduleId]?.labelKey ?? 'search.all')} · {count}
                                    </button>
                                );
                            })}
                        </div>

                        {results.length === 0 && <p className="text-muted-foreground px-1 py-8 text-center text-sm">{t('common.no_matches')}</p>}

                        {groups.map(([moduleId, rows]) => {
                            const Icon = MODULES[moduleId]?.icon ?? SearchIcon;
                            return (
                                <div key={moduleId} className="flex flex-col gap-2">
                                    <h2 className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">
                                        {t(MODULES[moduleId]?.labelKey ?? 'search.all')} {rows.length}
                                    </h2>
                                    <div className="bg-surface-card divide-border/60 flex flex-col divide-y rounded-2xl border">
                                        {rows.map((row, i) => (
                                            <a
                                                key={`${row.href}-${i}`}
                                                href={row.href}
                                                onClick={(e) => {
                                                    e.preventDefault();
                                                    router.visit(row.href);
                                                }}
                                                className="hover:bg-surface flex items-center gap-3 px-4 py-3 text-left transition-colors"
                                            >
                                                <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                                                    <Icon className="size-4.5" />
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-primary truncate text-sm font-semibold">
                                                        <Highlighted text={row.title} query={query} />
                                                    </div>
                                                    {row.meta && (
                                                        <div className="text-muted-foreground truncate text-xs">
                                                            <Highlighted text={row.meta} query={query} />
                                                        </div>
                                                    )}
                                                </div>
                                                <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                            </a>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </>
                )}
            </div>
        </div>
    );
}
