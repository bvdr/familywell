import { Head, Link, router } from '@inertiajs/react';
import { ChefHat, Clock, Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { useT } from '@/lib/i18n';
import { minutesText } from '@/lib/recipes';
import { type BreadcrumbItem } from '@/types';

type Card = {
    id: number;
    title: string;
    thumbUrl: string | null;
    tags: string[];
    servings: number | null;
    totalMinutes: number | null;
    ingredientNames: string[];
};

const ALL = '__all__';

export default function RecipesIndex({ recipes }: { recipes: Card[] }) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.recipes'), href: '/m/recipes' }];
    const [query, setQuery] = useState('');
    const [tag, setTag] = useState(ALL);
    const create = () => router.visit(route('recipes.create'));
    useAddAction(create); // the mobile nav's "+" adds a recipe here

    const tags = useMemo(() => [...new Set(recipes.flatMap((r) => r.tags))].sort((a, b) => a.localeCompare(b)), [recipes]);
    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        return recipes.filter(
            (r) =>
                (tag === ALL || r.tags.includes(tag)) && (!q || [r.title, ...r.tags, ...r.ingredientNames].some((s) => s.toLowerCase().includes(q))), // "what can I make with leeks"
        );
    }, [recipes, query, tag]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('nav.recipes')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="font-heading text-primary text-2xl">{t('nav.recipes')}</h1>
                    <Button size="icon" className="hidden rounded-full md:inline-flex" onClick={create} aria-label={t('recipes.new')}>
                        <Plus className="size-5" />
                    </Button>
                </div>

                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('recipes.search_placeholder')}
                        className="pl-9 text-base"
                    />
                </div>

                {tags.length > 0 && (
                    <div data-scroll-x className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        {[ALL, ...tags].map((tg) => (
                            <button
                                key={tg}
                                type="button"
                                onClick={() => setTag(tg)}
                                className={`shrink-0 rounded-full border px-3.5 py-1.5 text-sm transition-colors ${
                                    tag === tg ? 'border-primary bg-accent-subtle text-primary' : 'border-border text-muted-foreground'
                                }`}
                            >
                                {tg === ALL ? t('recipes.all') : tg}
                            </button>
                        ))}
                    </div>
                )}

                {recipes.length === 0 ? (
                    <div className="bg-surface-card flex flex-col items-center gap-3 rounded-2xl border p-8 text-center">
                        <ChefHat className="text-muted-foreground size-8" />
                        <p className="text-primary font-medium">{t('recipes.empty_title')}</p>
                        <p className="text-muted-foreground text-sm">{t('recipes.empty_body')}</p>
                        <Button onClick={create}>
                            <Plus className="size-4" /> {t('recipes.new')}
                        </Button>
                    </div>
                ) : visible.length === 0 ? (
                    <p className="text-muted-foreground py-8 text-center text-sm">{t('recipes.no_match')}</p>
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {visible.map((r) => (
                            <Link
                                key={r.id}
                                href={route('recipes.show', r.id)}
                                className="bg-surface-card hover:border-accent flex flex-col overflow-hidden rounded-2xl border transition-colors"
                            >
                                {r.thumbUrl ? (
                                    <img src={r.thumbUrl} alt="" loading="lazy" className="aspect-[4/3] w-full object-cover" />
                                ) : (
                                    <div className="bg-accent-subtle text-primary flex aspect-[4/3] w-full items-center justify-center">
                                        <ChefHat className="size-8 opacity-60" />
                                    </div>
                                )}
                                <div className="flex flex-col gap-1 p-3">
                                    <span className="text-primary line-clamp-2 leading-snug font-medium">{r.title}</span>
                                    <span className="text-muted-foreground flex items-center gap-1 text-xs">
                                        {r.totalMinutes ? (
                                            <>
                                                <Clock className="size-3" /> {minutesText(r.totalMinutes)}
                                            </>
                                        ) : null}
                                        {r.totalMinutes && r.servings ? ' · ' : ''}
                                        {r.servings ? t('recipes.servings_n', { n: r.servings }) : ''}
                                    </span>
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
