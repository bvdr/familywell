import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowLeft, ArrowUp, Check, ClipboardPaste, Heading, Lock, LockOpen, Plus, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { FormActions } from '@/components/form-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { RECIPE_UNITS } from '@/lib/recipe-scale.js';
import { parseIngredients, type Ingredient, type Recipe } from '@/lib/recipes';
import { type BreadcrumbItem } from '@/types';

// Editor rows: an ingredient, or a section heading ("Dough") that becomes the `group` of the
// ingredients under it when saved. Numbers are held as text while typing.
type Row = { kind: 'section'; name: string } | { kind: 'ing'; qty: string; unit: string; name: string; note: string; scalable: boolean };

function toRows(ingredients: Ingredient[]): Row[] {
    const rows: Row[] = [];
    let group: string | null = null;
    for (const i of ingredients) {
        if ((i.group || null) !== group) {
            group = i.group || null;
            if (group) rows.push({ kind: 'section', name: group });
        }
        rows.push({
            kind: 'ing',
            qty: i.qty === null ? '' : String(i.qty),
            unit: i.unit ?? '',
            name: i.name,
            note: i.note ?? '',
            scalable: i.scalable,
        });
    }
    return rows;
}

function fromRows(rows: Row[]) {
    let group: string | null = null;
    const out = [];
    for (const r of rows) {
        if (r.kind === 'section') {
            group = r.name.trim() || null;
            continue;
        }
        if (!r.name.trim()) continue;
        const qty = parseFloat(r.qty.replace(',', '.'));
        out.push({
            group,
            name: r.name.trim(),
            qty: Number.isFinite(qty) ? qty : null,
            unit: r.unit.trim() || null,
            note: r.note.trim() || null,
            scalable: r.scalable,
        });
    }
    return out;
}

const blankRow = (): Row => ({ kind: 'ing', qty: '', unit: '', name: '', note: '', scalable: true });

type Fields = {
    title: string;
    description: string;
    servings: string;
    yield_amount: string;
    yield_unit: string;
    prep_minutes: string;
    cook_minutes: string;
    tags: string;
    source_url: string;
    notes: string;
    steps: string;
};

export default function RecipeEdit({ recipe }: { recipe: Recipe | null }) {
    const t = useT();
    const editing = recipe !== null;
    const back = editing ? route('recipes.show', recipe.id) : route('recipes.index');
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.recipes'), href: '/m/recipes' },
        { title: editing ? recipe.title : t('recipes.new'), href: back },
    ];

    const [rows, setRows] = useState<Row[]>(() => (recipe?.ingredients.length ? toRows(recipe.ingredients) : [blankRow(), blankRow(), blankRow()]));
    const [paste, setPaste] = useState<string | null>(null);
    const form = useForm<Fields>({
        title: recipe?.title ?? '',
        description: recipe?.description ?? '',
        servings: recipe?.servings != null ? String(recipe.servings) : '',
        yield_amount: recipe?.yieldAmount != null ? String(recipe.yieldAmount) : '',
        yield_unit: recipe?.yieldUnit ?? '',
        prep_minutes: recipe?.prepMinutes != null ? String(recipe.prepMinutes) : '',
        cook_minutes: recipe?.cookMinutes != null ? String(recipe.cookMinutes) : '',
        tags: (recipe?.tags ?? []).join(', '),
        source_url: recipe?.sourceUrl ?? '',
        notes: recipe?.notes ?? '',
        steps: (recipe?.steps ?? []).join('\n\n'),
    });
    const { data, setData, errors, processing } = form;

    const setRow = (i: number, patch: Partial<Row>) => setRows((rs) => rs.map((r, j) => (j === i ? ({ ...r, ...patch } as Row) : r)));
    const move = (i: number, d: number) =>
        setRows((rs) => {
            const next = [...rs];
            const j = i + d;
            if (j < 0 || j >= next.length) return rs;
            [next[i], next[j]] = [next[j], next[i]];
            return next;
        });

    const submit = () => {
        const nul = (v: string) => (v.trim() === '' ? null : v.trim());
        // Steps: one per paragraph (blank line between), or one per line when there are no blank lines.
        const text = data.steps.trim();
        const steps = text ? (/\n\s*\n/.test(text) ? text.split(/\n\s*\n/) : text.split('\n')).map((s) => s.trim()).filter(Boolean) : [];
        form.transform(() => ({
            title: data.title.trim(),
            description: nul(data.description),
            servings: nul(data.servings),
            yield_amount: nul(data.yield_amount),
            yield_unit: nul(data.yield_unit),
            prep_minutes: nul(data.prep_minutes),
            cook_minutes: nul(data.cook_minutes),
            tags: data.tags
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean),
            source_url: nul(data.source_url),
            notes: nul(data.notes),
            steps,
            ingredients: fromRows(rows),
        }));
        if (editing) form.patch(route('recipes.update', recipe.id));
        else form.post(route('recipes.store'));
    };

    const field = 'text-base';
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? recipe.title : t('recipes.new')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
                <header className="flex items-center gap-3">
                    <Link href={back} className="text-muted-foreground hover:text-primary" aria-label={t('recipes.back')}>
                        <ArrowLeft className="size-5" />
                    </Link>
                    <h1 className="font-heading text-primary text-2xl">{editing ? t('recipes.edit') : t('recipes.new')}</h1>
                </header>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                    className="flex flex-col gap-5"
                >
                    <Field label={t('recipes.title_label')} error={errors.title}>
                        <Input
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder={t('recipes.title_placeholder')}
                            className={field}
                            autoFocus={!editing}
                        />
                    </Field>
                    <Field label={t('recipes.description_label')} error={errors.description}>
                        <Textarea value={data.description} onChange={(v) => setData('description', v)} rows={2} />
                    </Field>

                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <Field label={t('recipes.servings_label')} error={errors.servings}>
                            <Input
                                value={data.servings}
                                onChange={(e) => setData('servings', e.target.value)}
                                inputMode="numeric"
                                placeholder="4"
                                className={field}
                            />
                        </Field>
                        <Field label={t('recipes.prep_label')} error={errors.prep_minutes}>
                            <Input
                                value={data.prep_minutes}
                                onChange={(e) => setData('prep_minutes', e.target.value)}
                                inputMode="numeric"
                                placeholder="20"
                                className={field}
                            />
                        </Field>
                        <Field label={t('recipes.cook_label')} error={errors.cook_minutes}>
                            <Input
                                value={data.cook_minutes}
                                onChange={(e) => setData('cook_minutes', e.target.value)}
                                inputMode="numeric"
                                placeholder="45"
                                className={field}
                            />
                        </Field>
                        <Field label={t('recipes.yield_label')} error={errors.yield_amount ?? errors.yield_unit} hint={t('recipes.yield_hint')}>
                            <div className="flex gap-1.5">
                                <Input
                                    value={data.yield_amount}
                                    onChange={(e) => setData('yield_amount', e.target.value)}
                                    inputMode="decimal"
                                    placeholder="1200"
                                    className={`${field} min-w-0`}
                                />
                                <Input
                                    value={data.yield_unit}
                                    onChange={(e) => setData('yield_unit', e.target.value)}
                                    list="recipe-units"
                                    placeholder="g"
                                    className={`${field} w-16`}
                                />
                            </div>
                        </Field>
                    </div>

                    {/* Ingredients */}
                    <section className="flex flex-col gap-2">
                        <div className="flex items-center justify-between">
                            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                {t('recipes.ingredients')}
                            </Label>
                            <button
                                type="button"
                                onClick={() => setPaste(paste === null ? '' : null)}
                                className="text-primary flex items-center gap-1 text-sm font-medium"
                            >
                                <ClipboardPaste className="size-4" /> {t('recipes.paste_list')}
                            </button>
                        </div>
                        {paste !== null && (
                            <div className="bg-surface flex flex-col gap-2 rounded-xl border p-3">
                                <Textarea value={paste} onChange={setPaste} rows={6} placeholder={t('recipes.paste_placeholder')} />
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={!paste.trim()}
                                        onClick={() => {
                                            // Replace the empty starter rows, keep anything already typed.
                                            setRows((rs) => [
                                                ...rs.filter((r) => r.kind === 'section' || r.name.trim()),
                                                ...toRows(parseIngredients(paste)),
                                            ]);
                                            setPaste(null);
                                        }}
                                    >
                                        {t('recipes.add_lines')}
                                    </Button>
                                    <Button type="button" size="sm" variant="ghost" onClick={() => setPaste(null)}>
                                        {t('recipes.cancel')}
                                    </Button>
                                </div>
                            </div>
                        )}
                        <datalist id="recipe-units">
                            {RECIPE_UNITS.map((u) => (
                                <option key={u} value={u} />
                            ))}
                        </datalist>
                        <ul className="flex flex-col gap-2">
                            {rows.map((r, i) => (
                                <li key={i} className="bg-surface-card flex items-start gap-1.5 rounded-xl border p-2">
                                    {r.kind === 'section' ? (
                                        <Input
                                            value={r.name}
                                            onChange={(e) => setRow(i, { name: e.target.value })}
                                            placeholder={t('recipes.section_placeholder')}
                                            className={`${field} font-semibold`}
                                        />
                                    ) : (
                                        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                            <div className="flex gap-1.5">
                                                <Input
                                                    value={r.qty}
                                                    onChange={(e) => setRow(i, { qty: e.target.value })}
                                                    inputMode="decimal"
                                                    placeholder={t('recipes.qty')}
                                                    className={`${field} w-20`}
                                                    aria-label={t('recipes.qty')}
                                                />
                                                <Input
                                                    value={r.unit}
                                                    onChange={(e) => setRow(i, { unit: e.target.value })}
                                                    list="recipe-units"
                                                    placeholder={t('recipes.unit')}
                                                    className={`${field} w-20`}
                                                    aria-label={t('recipes.unit')}
                                                />
                                                <Input
                                                    value={r.name}
                                                    onChange={(e) => setRow(i, { name: e.target.value })}
                                                    placeholder={t('recipes.ingredient')}
                                                    className={`${field} min-w-0 flex-1`}
                                                    aria-label={t('recipes.ingredient')}
                                                />
                                            </div>
                                            <div className="flex items-center gap-1.5">
                                                <Input
                                                    value={r.note}
                                                    onChange={(e) => setRow(i, { note: e.target.value })}
                                                    placeholder={t('recipes.note_placeholder')}
                                                    className="h-8 min-w-0 flex-1 text-base md:text-sm"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => setRow(i, { scalable: !r.scalable })}
                                                    title={r.scalable ? t('recipes.scales') : t('recipes.fixed')}
                                                    aria-label={r.scalable ? t('recipes.scales') : t('recipes.fixed')}
                                                    className={`flex h-8 items-center gap-1 rounded-lg px-2 text-xs ${r.scalable ? 'text-muted-foreground' : 'bg-accent-subtle text-primary'}`}
                                                >
                                                    {r.scalable ? <LockOpen className="size-3.5" /> : <Lock className="size-3.5" />}
                                                    {r.scalable ? '' : t('recipes.fixed')}
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                    <div className="flex shrink-0 flex-col">
                                        <IconBtn label={t('recipes.move_up')} onClick={() => move(i, -1)}>
                                            <ArrowUp className="size-3.5" />
                                        </IconBtn>
                                        <IconBtn label={t('recipes.move_down')} onClick={() => move(i, 1)}>
                                            <ArrowDown className="size-3.5" />
                                        </IconBtn>
                                        <IconBtn label={t('recipes.remove')} onClick={() => setRows((rs) => rs.filter((_, j) => j !== i))}>
                                            <X className="size-3.5" />
                                        </IconBtn>
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" size="sm" onClick={() => setRows((rs) => [...rs, blankRow()])}>
                                <Plus className="size-4" /> {t('recipes.add_ingredient')}
                            </Button>
                            <Button type="button" variant="ghost" size="sm" onClick={() => setRows((rs) => [...rs, { kind: 'section', name: '' }])}>
                                <Heading className="size-4" /> {t('recipes.add_section')}
                            </Button>
                        </div>
                        <InputError message={Object.entries(errors).find(([k]) => k.startsWith('ingredients'))?.[1]} />
                    </section>

                    <Field label={t('recipes.method')} error={errors.steps} hint={t('recipes.steps_hint')}>
                        <Textarea value={data.steps} onChange={(v) => setData('steps', v)} rows={8} placeholder={t('recipes.steps_placeholder')} />
                    </Field>

                    <Field label={t('recipes.tags_label')} error={errors.tags} hint={t('recipes.tags_hint')}>
                        <Input
                            value={data.tags}
                            onChange={(e) => setData('tags', e.target.value)}
                            placeholder={t('recipes.tags_placeholder')}
                            className={field}
                        />
                    </Field>
                    <Field label={t('recipes.source_label')} error={errors.source_url}>
                        <Input
                            value={data.source_url}
                            onChange={(e) => setData('source_url', e.target.value)}
                            type="url"
                            placeholder="https://"
                            className={field}
                        />
                    </Field>
                    <Field label={t('recipes.notes')} error={errors.notes}>
                        <Textarea value={data.notes} onChange={(v) => setData('notes', v)} rows={3} />
                    </Field>

                    <FormActions>
                        <Button type="submit" disabled={processing || !data.title.trim()} className="flex-1">
                            <Check className="size-4" /> {editing ? t('recipes.save') : t('recipes.create')}
                        </Button>
                    </FormActions>
                </form>
            </div>
        </AppLayout>
    );
}

function Field({ label, error, hint, children }: { label: string; error?: string; hint?: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{label}</Label>
            {children}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

function Textarea({ value, onChange, rows, placeholder }: { value: string; onChange: (v: string) => void; rows: number; placeholder?: string }) {
    return (
        <textarea
            value={value}
            onChange={(e) => onChange(e.target.value)}
            rows={rows}
            placeholder={placeholder}
            className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
        />
    );
}

function IconBtn({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            title={label}
            className="text-muted-foreground hover:text-primary flex size-7 items-center justify-center"
        >
            {children}
        </button>
    );
}
