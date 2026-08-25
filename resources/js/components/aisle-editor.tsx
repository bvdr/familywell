import { router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useT } from '@/lib/i18n';
import { AISLE_ICON_KEYS, aisleIcon } from '@/lib/shopping';

type Aisle = { id: number; slug: string; name: string; icon: string; position: number };
type Template = { key: string; label: string };

const csrf = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
async function api(method: string, url: string, body?: unknown) {
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    return res.ok ? res.json() : null;
}

// Per-shop aisle editor. Opens from a list's ⋯ menu; edits the aisles for that list's store. All
// changes hit the JSON aisle endpoints; on close (if anything changed) the list page reloads its
// `aisles` + `items` so the chips and grouping reflect the new set.
export function AisleEditor({
    open,
    onOpenChange,
    listId,
    stores = [],
    initialStore = '',
    shopId,
    shopName,
    reloadKeys = ['aislesByStore', 'items'],
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    // List mode: edit the aisles for a list's store(s).
    listId?: number;
    stores?: string[]; // the shops you can edit aisles for (the list's stores)
    initialStore?: string; // '' = the list's default/no store
    // Shop mode: edit one shop's aisles directly (no store switcher).
    shopId?: number;
    shopName?: string;
    reloadKeys?: string[]; // page props to reload on close so grouping/chips refresh
}) {
    const t = useT();
    // In shop mode the store is fixed to the shop and there's no switcher.
    const storeList = shopId ? [] : stores;
    const [store, setStore] = useState(shopId ? (shopName ?? '') : initialStore);

    // Endpoints differ by mode; update/destroy of a single aisle are shared (keyed by aisle id).
    const ensureUrl = shopId ? route('shopping.shops.aisles.ensure', shopId) : route('shopping.aisles.ensure', listId!);
    const storeAisleUrl = shopId ? route('shopping.shops.aisles.store', shopId) : route('shopping.aisles.store', listId!);
    const reorderUrl = shopId ? route('shopping.shops.aisles.reorder', shopId) : route('shopping.aisles.reorder', listId!);
    const templateUrl = shopId ? route('shopping.shops.aisles.template', shopId) : route('shopping.aisles.template', listId!);
    const [aisles, setAisles] = useState<Aisle[]>([]);
    const [templates, setTemplates] = useState<Template[]>([]);
    const [newName, setNewName] = useState('');
    const [newIcon, setNewIcon] = useState('basket');
    const [picker, setPicker] = useState<'new' | number | null>(null); // icon picker target
    const [dirty, setDirty] = useState(false);

    useEffect(() => {
        if (open) setStore(shopId ? (shopName ?? '') : initialStore);
    }, [open, initialStore, shopId, shopName]);

    useEffect(() => {
        if (!open) return;
        setPicker(null);
        // Ensure the shop has editable rows (materializes the fallback), then load them + templates.
        api('POST', ensureUrl, { store }).then((d) => {
            if (d) {
                setAisles(d.aisles);
                setTemplates(d.templates);
            }
        });
    }, [open, ensureUrl, store]);

    const apply = (d: { aisles?: Aisle[] } | null) => {
        if (d?.aisles) {
            setAisles(d.aisles);
            setDirty(true);
        }
    };

    const addAisle = async () => {
        if (!newName.trim()) return;
        apply(await api('POST', storeAisleUrl, { name: newName.trim(), icon: newIcon, store }));
        setNewName('');
        setNewIcon('basket');
    };
    const removeAisle = async (a: Aisle) => apply(await api('DELETE', route('shopping.aisles.destroy', a.id)));
    const renameAisle = async (a: Aisle) => apply(await api('PATCH', route('shopping.aisles.update', a.id), { name: a.name }));
    const editNameLocal = (id: number, name: string) => setAisles((prev) => prev.map((a) => (a.id === id ? { ...a, name } : a)));
    const move = async (idx: number, dir: -1 | 1) => {
        const j = idx + dir;
        if (j < 0 || j >= aisles.length) return;
        const next = [...aisles];
        [next[idx], next[j]] = [next[j], next[idx]];
        setAisles(next);
        setDirty(true);
        await api('POST', reorderUrl, { slugs: next.map((a) => a.slug), store });
    };
    const setIcon = async (target: 'new' | number, icon: string) => {
        if (target === 'new') setNewIcon(icon);
        else apply(await api('PATCH', route('shopping.aisles.update', target), { icon }));
        setPicker(null);
    };
    const applyTemplate = async (tpl: Template) => {
        if (!confirm(t('shopping.replace_template_confirm', { template: tpl.label }))) return;
        apply(await api('POST', templateUrl, { template: tpl.key, store }));
    };

    const handleOpenChange = (v: boolean) => {
        if (!v && dirty) {
            router.reload({ only: reloadKeys }); // refresh chips + grouping on the list page
            setDirty(false);
        }
        onOpenChange(v);
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={handleOpenChange}
            title={t('shopping.edit_departments')}
            description={
                storeList.length > 1 ? t('shopping.pick_shop_then_edit') : t('shopping.departments_at', { store: store || t('shopping.this_shop') })
            }
            footer={
                <Button className="w-full" onClick={() => handleOpenChange(false)}>
                    {t('shopping.done_button')}
                </Button>
            }
        >
            {storeList.length > 1 && picker === null && (
                <div className="mb-4 flex flex-wrap gap-2">
                    {storeList.map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => setStore(s)}
                            className={`rounded-full border px-3 py-1.5 text-sm transition-colors ${
                                store === s ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border text-primary'
                            }`}
                        >
                            {s}
                        </button>
                    ))}
                </div>
            )}
            {picker !== null ? (
                <div className="flex flex-col gap-3">
                    <button type="button" onClick={() => setPicker(null)} className="text-muted-foreground self-start text-sm">
                        {t('shopping.back')}
                    </button>
                    <div className="grid grid-cols-6 gap-2">
                        {AISLE_ICON_KEYS.map((key) => {
                            const Icon = aisleIcon(key);
                            return (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => setIcon(picker, key)}
                                    aria-label={key}
                                    className="border-border text-primary hover:bg-surface flex aspect-square items-center justify-center rounded-lg border transition-colors"
                                >
                                    <Icon className="size-5" />
                                </button>
                            );
                        })}
                    </div>
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-2">
                        {aisles.map((a, idx) => {
                            const Icon = aisleIcon(a.icon);
                            return (
                                <div key={a.id} className="border-border flex items-center gap-2 rounded-xl border p-2">
                                    <button
                                        type="button"
                                        onClick={() => setPicker(a.id)}
                                        aria-label={t('shopping.change_icon')}
                                        className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg"
                                    >
                                        <Icon className="size-4.5" />
                                    </button>
                                    <input
                                        value={a.name}
                                        onChange={(e) => editNameLocal(a.id, e.target.value)}
                                        onBlur={() => renameAisle(a)}
                                        aria-label={t('shopping.department_name')}
                                        className="text-primary min-w-0 flex-1 bg-transparent text-sm font-medium outline-none"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => move(idx, -1)}
                                        disabled={idx === 0}
                                        aria-label={t('shopping.move_up')}
                                        className="text-muted-foreground hover:text-primary flex size-7 items-center justify-center rounded-lg disabled:opacity-30"
                                    >
                                        <ChevronUp className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => move(idx, 1)}
                                        disabled={idx === aisles.length - 1}
                                        aria-label={t('shopping.move_down')}
                                        className="text-muted-foreground hover:text-primary flex size-7 items-center justify-center rounded-lg disabled:opacity-30"
                                    >
                                        <ChevronDown className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => removeAisle(a)}
                                        aria-label={t('shopping.delete_department')}
                                        className="text-muted-foreground hover:text-destructive flex size-7 items-center justify-center rounded-lg"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            );
                        })}
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setPicker('new')}
                            aria-label={t('shopping.pick_icon')}
                            className="border-border text-primary hover:bg-surface flex size-9 shrink-0 items-center justify-center rounded-lg border"
                        >
                            {(() => {
                                const Icon = aisleIcon(newIcon);
                                return <Icon className="size-4.5" />;
                            })()}
                        </button>
                        <Input
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && addAisle()}
                            placeholder={t('shopping.new_department_placeholder')}
                        />
                        <Button type="button" size="icon" onClick={addAisle} disabled={!newName.trim()} aria-label={t('shopping.add_department')}>
                            <Plus className="size-4" />
                        </Button>
                    </div>

                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                            {t('shopping.start_from_template')}
                        </Label>
                        <div className="flex flex-wrap gap-2">
                            {templates.map((tpl) => (
                                <button
                                    key={tpl.key}
                                    type="button"
                                    onClick={() => applyTemplate(tpl)}
                                    className="border-border text-primary hover:bg-surface rounded-full border px-3 py-1.5 text-sm transition-colors"
                                >
                                    {tpl.label}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </ResponsiveModal>
    );
}
