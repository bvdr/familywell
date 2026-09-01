import { router } from '@inertiajs/react';
import { Package, Plus, ShoppingCart, Trash2, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TFn } from '@/lib/i18n';
import type { PartRequest } from '@/lib/vehicles';

// The vehicle-level "Parts" card on the Overview: the parts requested/bought for this car (each a labelled
// row on the family shopping list), with an inline "add part" form. Shown whenever Shopping is on or the car
// already has parts on record. Adding is idempotent server-side (a still-open request is reused).
export function PartsSection({ vehicleId, parts, canShopping, t }: { vehicleId: number; parts: PartRequest[]; canShopping: boolean; t: TFn }) {
    const [adding, setAdding] = useState(false);

    if (!canShopping && parts.length === 0) return null;

    return (
        <section className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-3">
                <h2 className="font-heading text-primary text-lg">{t('vehicles.parts')}</h2>
                {canShopping && !adding && (
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="bg-surface-card text-primary hover:border-accent inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
                    >
                        <Plus className="size-4" /> {t('vehicles.add_part')}
                    </button>
                )}
            </div>

            {adding && <AddPartForm vehicleId={vehicleId} t={t} onDone={() => setAdding(false)} onCancel={() => setAdding(false)} />}

            {parts.length === 0 ? (
                !adding && <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-6 text-center text-sm">{t('vehicles.parts_empty')}</p>
            ) : (
                <ul className="bg-border grid gap-px overflow-hidden rounded-2xl border">
                    {parts.map((p) => (
                        <PartRow key={p.id} vehicleId={vehicleId} part={p} t={t} />
                    ))}
                </ul>
            )}
        </section>
    );
}

function PartRow({ vehicleId, part, t }: { vehicleId: number; part: PartRequest; t: TFn }) {
    const remove = () => {
        if (confirm(t('vehicles.part_delete_confirm'))) router.delete(`/m/vehicles/${vehicleId}/parts/${part.id}`, { preserveScroll: true });
    };
    const bought = part.status === 'bought';

    return (
        <li className="bg-surface-card flex items-center gap-3 px-4 py-2.5">
            <span className="bg-accent-subtle text-primary flex size-8 shrink-0 items-center justify-center rounded-lg">
                <Package className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
                <span className={`text-primary block truncate text-sm ${bought ? 'line-through opacity-70' : ''}`}>{part.name}</span>
                <span className="text-muted-foreground flex flex-wrap items-center gap-x-2 text-xs">
                    <span>{bought ? t('vehicles.part_status_bought') : t('vehicles.part_status_requested')}</span>
                    {!bought && part.onShoppingList && (
                        <span className="inline-flex items-center gap-1">
                            <ShoppingCart className="size-3" /> {t('vehicles.part_on_list')}
                        </span>
                    )}
                    {part.estimatedCost != null && <span className="tabular-nums">· {t('vehicles.cost_value', { value: part.estimatedCost.toFixed(2) })}</span>}
                </span>
            </div>
            <button
                type="button"
                onClick={remove}
                aria-label={t('vehicles.delete')}
                className="text-muted-foreground hover:text-primary hover:bg-surface flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors"
            >
                <Trash2 className="size-4" />
            </button>
        </li>
    );
}

// The inline add form used by the Overview card (name + optional estimated cost).
function AddPartForm({ vehicleId, t, onDone, onCancel }: { vehicleId: number; t: TFn; onDone: () => void; onCancel: () => void }) {
    const [name, setName] = useState('');
    const [cost, setCost] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        router.post(
            `/m/vehicles/${vehicleId}/parts`,
            { name, estimated_cost: cost },
            {
                preserveScroll: true,
                onSuccess: onDone,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="bg-surface-card flex flex-col gap-3 rounded-2xl border p-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.part_name')}</Label>
                    <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={t('vehicles.part_name_placeholder')} className="text-base" />
                </div>
                <div className="grid gap-1.5">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.part_cost')}</Label>
                    <Input value={cost} onChange={(e) => setCost(e.target.value)} inputMode="decimal" placeholder="0.00" className="text-base" />
                </div>
            </div>
            <p className="text-muted-foreground text-xs">{t('vehicles.part_cost_hint')}</p>
            <div className="flex items-center gap-3">
                <Button type="button" onClick={submit} disabled={processing || name.trim() === ''} className="flex-1">
                    {t('vehicles.add_part')}
                </Button>
                <Button type="button" variant="outline" onClick={onCancel}>
                    {t('vehicles.cancel')}
                </Button>
            </div>
        </div>
    );
}

// A compact "Add part to shopping" control for a maintenance event or repair. Toggles a small inline form
// that posts with the origin id, so the part records what service it's for.
export function AddPartButton({ vehicleId, scheduleId, repairId, t }: { vehicleId: number; scheduleId?: number; repairId?: number; t: TFn }) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [cost, setCost] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        router.post(
            `/m/vehicles/${vehicleId}/parts`,
            { name, estimated_cost: cost, maintenance_schedule_id: scheduleId, repair_id: repairId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setName('');
                    setCost('');
                    setOpen(false);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="text-primary hover:bg-surface inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
            >
                <ShoppingCart className="size-4" /> {t('vehicles.add_part')}
            </button>
        );
    }

    return (
        <div className="bg-surface flex flex-col gap-2 rounded-lg border p-3">
            <div className="flex items-center justify-between">
                <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.add_part')}</span>
                <button type="button" onClick={() => setOpen(false)} aria-label={t('vehicles.cancel')} className="text-muted-foreground hover:text-primary">
                    <X className="size-4" />
                </button>
            </div>
            <div className="flex flex-wrap gap-2">
                <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={t('vehicles.part_name_placeholder')} className="min-w-40 flex-1 text-base" />
                <Input value={cost} onChange={(e) => setCost(e.target.value)} inputMode="decimal" placeholder="0.00" className="w-24 text-base" />
                <Button type="button" onClick={submit} disabled={processing || name.trim() === ''}>
                    {t('vehicles.add_part')}
                </Button>
            </div>
        </div>
    );
}
