import { Minus, Plus, RotateCcw } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';

import { useLocale, useT } from '@/lib/i18n';
import { factorFor, formatQty, scaleIngredient } from '@/lib/recipe-scale.js';
import { minutesText, type Recipe, type ScaleTarget } from '@/lib/recipes';

// The cooking part of a recipe, shared by the app page and the public guest page: the calculator (scale
// by servings, by how much to make, or by what you have of one ingredient), the scaled ingredients and
// the method with tickable steps. Scaling is recipe-scale.js, the same code the MCP widget inlines.
// `shop` renders under the ingredients with the current factor (the app page's "Add to shopping").
export function RecipeView({ recipe, shop }: { recipe: Recipe; shop?: (factor: number, target: ScaleTarget | null) => ReactNode }) {
    const t = useT();
    const locale = useLocale();
    const input = useMemo(
        () => ({ servings: recipe.servings, yieldAmount: recipe.yieldAmount, yieldUnit: recipe.yieldUnit, ingredients: recipe.ingredients }),
        [recipe],
    );
    const withQty = recipe.ingredients.map((ing, index) => ({ ing, index })).filter(({ ing }) => ing.qty);
    const modes = [
        recipe.servings ? (['servings', t('recipes.by_servings')] as const) : null,
        recipe.yieldAmount ? (['yield', t('recipes.by_total')] as const) : null,
        withQty.length ? (['ingredient', t('recipes.by_have')] as const) : null,
    ].filter((m) => m !== null);

    const [mode, setMode] = useState<ScaleTarget['type']>(modes[0]?.[0] ?? 'servings');
    const [target, setTarget] = useState<ScaleTarget | null>(null);
    // Raw text of the number fields, so typing "1," or clearing a field doesn't fight the parsed value.
    const [amount, setAmount] = useState('');
    const [unit, setUnit] = useState('');
    const [have, setHave] = useState(withQty[0]?.index ?? 0);
    const [done, setDone] = useState<Set<number>>(new Set());

    const factor = target ? factorFor(input, target) : 1;
    const num = (v: string) => {
        const n = parseFloat(v.replace(',', '.'));
        return Number.isFinite(n) && n > 0 ? n : null;
    };
    const servings = target?.type === 'servings' ? target.value : (recipe.servings ?? 1);
    const setServings = (v: number) => setTarget(v === recipe.servings ? null : { type: 'servings', value: Math.max(1, v) });
    const pickMode = (m: ScaleTarget['type']) => {
        setMode(m);
        setTarget(null);
        setAmount('');
        setUnit(m === 'yield' ? (recipe.yieldUnit ?? '') : m === 'ingredient' ? (recipe.ingredients[have]?.unit ?? '') : '');
    };
    const onAmount = (v: string, u = unit, idx = have) => {
        setAmount(v);
        const a = num(v);
        if (!a) return setTarget(null);
        setTarget(
            mode === 'yield'
                ? { type: 'yield', amount: a, unit: u || recipe.yieldUnit }
                : { type: 'ingredient', index: idx, amount: a, unit: u || recipe.ingredients[idx].unit },
        );
    };

    const field =
        'border-input bg-surface-card focus-visible:ring-ring rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none';

    // Ingredients with their section headings, in recipe order.
    let group: string | null = null;
    const rows: ReactNode[] = [];
    recipe.ingredients.forEach((ing, i) => {
        if ((ing.group || null) !== group) {
            group = ing.group || null;
            if (group)
                rows.push(
                    <li key={`g${i}`} className="text-muted-foreground bg-surface px-4 py-1.5 text-xs font-semibold tracking-widest uppercase">
                        {group}
                    </li>,
                );
        }
        const s = scaleIngredient(ing, factor ?? 1);
        rows.push(
            <li key={i} className="flex items-baseline gap-3 px-4 py-2.5">
                <span className="text-primary w-20 shrink-0 font-semibold tabular-nums">
                    {s.qty === null ? '' : `${formatQty(s.qty, s.unit, locale)} ${s.unit ?? ''}`}
                </span>
                <span className="min-w-0 flex-1">
                    {ing.name}
                    {ing.note && <span className="text-muted-foreground"> · {ing.note}</span>}
                    {s.qty === null && <span className="text-muted-foreground"> · {t('recipes.to_taste')}</span>}
                </span>
            </li>,
        );
    });

    return (
        <div className="flex flex-col gap-5">
            {modes.length > 0 && (
                <section className="bg-surface-card flex flex-col gap-3 rounded-2xl border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap gap-1.5">
                            {modes.map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => pickMode(key)}
                                    className={`rounded-full border px-3 py-1 text-sm font-medium transition-colors ${
                                        mode === key ? 'border-primary bg-accent-subtle text-primary' : 'border-border text-muted-foreground'
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        <div className="flex items-center gap-2">
                            <span
                                className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${factor === null ? 'bg-destructive/10 text-destructive' : 'bg-accent-subtle text-primary'}`}
                            >
                                {factor === null
                                    ? t('recipes.cant_scale')
                                    : factor === 1
                                      ? t('recipes.as_written')
                                      : `×${Math.round(factor * 100) / 100}`}
                            </span>
                            {target && (
                                <button
                                    type="button"
                                    onClick={() => pickMode(mode)}
                                    className="text-muted-foreground hover:text-primary"
                                    aria-label={t('recipes.reset')}
                                >
                                    <RotateCcw className="size-4" />
                                </button>
                            )}
                        </div>
                    </div>

                    {mode === 'servings' && recipe.servings && (
                        <div className="flex items-center gap-3">
                            <button
                                type="button"
                                onClick={() => setServings(servings - 1)}
                                className="bg-surface flex size-10 items-center justify-center rounded-full border"
                                aria-label={t('recipes.fewer')}
                            >
                                <Minus className="size-4" />
                            </button>
                            <span className="text-primary min-w-10 text-center text-2xl font-semibold tabular-nums">{servings}</span>
                            <button
                                type="button"
                                onClick={() => setServings(servings + 1)}
                                className="bg-surface flex size-10 items-center justify-center rounded-full border"
                                aria-label={t('recipes.more')}
                            >
                                <Plus className="size-4" />
                            </button>
                            <span className="text-muted-foreground text-sm">{t('recipes.servings_word')}</span>
                        </div>
                    )}
                    {mode === 'yield' && recipe.yieldAmount && (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-muted-foreground text-sm">{t('recipes.make')}</span>
                            <input
                                value={amount}
                                onChange={(e) => onAmount(e.target.value)}
                                inputMode="decimal"
                                placeholder={String(recipe.yieldAmount)}
                                className={`${field} w-28`}
                            />
                            <input
                                value={unit}
                                onChange={(e) => {
                                    setUnit(e.target.value);
                                    onAmount(amount, e.target.value);
                                }}
                                placeholder={recipe.yieldUnit ?? ''}
                                className={`${field} w-20`}
                                aria-label={t('recipes.unit')}
                            />
                            <span className="text-muted-foreground text-xs">
                                {t('recipes.recipe_makes', { value: `${recipe.yieldAmount} ${recipe.yieldUnit ?? ''}` })}
                            </span>
                        </div>
                    )}
                    {mode === 'ingredient' && withQty.length > 0 && (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-muted-foreground text-sm">{t('recipes.i_have')}</span>
                            <input
                                value={amount}
                                onChange={(e) => onAmount(e.target.value)}
                                inputMode="decimal"
                                placeholder="400"
                                className={`${field} w-24`}
                                aria-label={t('recipes.how_much')}
                            />
                            <input
                                value={unit}
                                onChange={(e) => {
                                    setUnit(e.target.value);
                                    onAmount(amount, e.target.value);
                                }}
                                placeholder={recipe.ingredients[have]?.unit ?? ''}
                                className={`${field} w-20`}
                                aria-label={t('recipes.unit')}
                            />
                            <span className="text-muted-foreground text-sm">{t('recipes.of')}</span>
                            <select
                                value={have}
                                onChange={(e) => {
                                    const idx = Number(e.target.value);
                                    const u = recipe.ingredients[idx]?.unit ?? '';
                                    setHave(idx);
                                    setUnit(u);
                                    onAmount(amount, u, idx);
                                }}
                                className={`${field} min-w-0 flex-1`}
                            >
                                {withQty.map(({ ing, index }) => (
                                    <option key={index} value={index}>
                                        {ing.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                </section>
            )}

            <section className="flex flex-col gap-2">
                <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('recipes.ingredients')}</h2>
                {rows.length ? (
                    <ul className="bg-surface-card divide-border divide-y overflow-hidden rounded-2xl border">{rows}</ul>
                ) : (
                    <p className="text-muted-foreground text-sm">{t('recipes.no_ingredients')}</p>
                )}
                {shop?.(factor ?? 1, factor === null ? null : target)}
            </section>

            {recipe.steps.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('recipes.method')}</h2>
                    <ol className="bg-surface-card divide-border divide-y overflow-hidden rounded-2xl border">
                        {recipe.steps.map((step, i) => (
                            <li key={i}>
                                <label className="flex cursor-pointer gap-3 px-4 py-3">
                                    <input
                                        type="checkbox"
                                        checked={done.has(i)}
                                        onChange={() =>
                                            setDone((d) => {
                                                const next = new Set(d);
                                                if (next.has(i)) next.delete(i);
                                                else next.add(i);
                                                return next;
                                            })
                                        }
                                        className="mt-1 size-4 shrink-0 accent-[var(--accent-strong)]"
                                    />
                                    <span className={`whitespace-pre-line ${done.has(i) ? 'text-muted-foreground line-through' : ''}`}>
                                        <span className="text-primary font-semibold">{i + 1}. </span>
                                        {step}
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ol>
                </section>
            )}

            {recipe.notes && (
                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('recipes.notes')}</h2>
                    <p className="bg-surface-card rounded-2xl border p-4 text-sm whitespace-pre-line">{recipe.notes}</p>
                </section>
            )}

            {recipe.sourceUrl && (
                <a
                    href={recipe.sourceUrl}
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    className="text-primary self-start text-sm font-medium underline"
                >
                    {t('recipes.original')} ↗
                </a>
            )}
        </div>
    );
}

/** Title block: photo, title, times / servings / yield, tags, description. */
export function RecipeHeader({ recipe, photo }: { recipe: Recipe; photo?: ReactNode }) {
    const t = useT();
    const meta = [
        recipe.prepMinutes ? `${t('recipes.prep')} ${minutesText(recipe.prepMinutes)}` : null,
        recipe.cookMinutes ? `${t('recipes.cook')} ${minutesText(recipe.cookMinutes)}` : null,
        recipe.servings ? t('recipes.servings_n', { n: recipe.servings }) : null,
        recipe.yieldAmount ? t('recipes.makes', { value: `${recipe.yieldAmount} ${recipe.yieldUnit ?? ''}`.trim() }) : null,
    ].filter(Boolean);
    return (
        <div className="flex flex-col gap-3">
            {photo ?? (recipe.photoUrl && <img src={recipe.photoUrl} alt="" className="aspect-[16/10] w-full rounded-2xl object-cover" />)}
            <h1 className="font-heading text-primary text-3xl leading-tight">{recipe.title}</h1>
            {meta.length > 0 && <p className="text-muted-foreground text-sm">{meta.join(' · ')}</p>}
            {recipe.tags.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {recipe.tags.map((tag) => (
                        <span key={tag} className="bg-accent-subtle text-primary rounded-full px-2.5 py-0.5 text-xs font-medium">
                            {tag}
                        </span>
                    ))}
                </div>
            )}
            {recipe.description && <p className="whitespace-pre-line">{recipe.description}</p>}
        </div>
    );
}
