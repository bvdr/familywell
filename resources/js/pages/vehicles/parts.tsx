import { router } from '@inertiajs/react';
import { Package, Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import type { TFn } from '@/lib/i18n';
import type { PartRequest } from '@/lib/vehicles';

// The vehicle-level "Parts" card on the Overview: the parts requested/bought for this car (each a labelled
// row on the family shopping list), with an "add part" modal. Shown whenever Shopping is on or the car
// already has parts on record. Adding is idempotent server-side (a still-open request is reused).
export function PartsSection({ vehicleId, parts, canShopping, t }: { vehicleId: number; parts: PartRequest[]; canShopping: boolean; t: TFn }) {
    const [adding, setAdding] = useState(false);
    // Optimistic removal overlay: a deleted part disappears immediately and only reappears if the delete fails.
    const removed = useOptimistic<boolean>();
    const visibleParts = parts.filter((p) => !removed.get(p.id, false));
    const removePart = (part: PartRequest) => {
        removed.set(part.id, true);
        router.delete(`/m/vehicles/${vehicleId}/parts/${part.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => removed.clear(part.id),
            onError: () => removed.clear(part.id),
        });
    };

    if (!canShopping && parts.length === 0) return null;

    return (
        <section className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-3">
                <h2 className="font-heading text-primary text-lg">{t('vehicles.parts')}</h2>
                {canShopping && (
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className="bg-surface-card text-primary hover:border-accent inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
                    >
                        <Plus className="size-4" /> {t('vehicles.add_part')}
                    </button>
                )}
            </div>

            <AddPartForm vehicleId={vehicleId} t={t} open={adding} onOpenChange={setAdding} />

            {visibleParts.length === 0 ? (
                <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-6 text-center text-sm">{t('vehicles.parts_empty')}</p>
            ) : (
                <ul className="bg-border grid gap-px overflow-hidden rounded-2xl border">
                    {visibleParts.map((p) => (
                        <PartRow key={p.id} part={p} t={t} onRemove={() => removePart(p)} />
                    ))}
                </ul>
            )}
        </section>
    );
}

function PartRow({ part, t, onRemove }: { part: PartRequest; t: TFn; onRemove: () => void }) {
    const remove = () => {
        if (confirm(t('vehicles.part_delete_confirm'))) onRemove();
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

// The "add part" modal used by the Overview card (name + optional estimated cost). Always mounted so the
// drawer/dialog can animate in and out; `open` is owned by the parent (PartsSection).
function AddPartForm({ vehicleId, t, open, onOpenChange }: { vehicleId: number; t: TFn; open: boolean; onOpenChange: (open: boolean) => void }) {
    const [name, setName] = useState('');
    const [cost, setCost] = useState('');
    const [processing, setProcessing] = useState(false);

    // Re-seed on every open so a previous submission (or a cancelled edit) doesn't linger.
    useEffect(() => {
        if (open) {
            setName('');
            setCost('');
        }
    }, [open]);

    const submit = () => {
        setProcessing(true);
        router.post(
            `/m/vehicles/${vehicleId}/parts`,
            { name, estimated_cost: cost },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('vehicles.add_part')}
            footer={
                <Button type="button" onClick={submit} disabled={processing || name.trim() === ''} className="w-full">
                    {t('vehicles.add_part')}
                </Button>
            }
        >
            <div className="flex flex-col gap-3 pb-2">
                <div className="grid gap-1.5">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.part_name')}</Label>
                    <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={t('vehicles.part_name_placeholder')} className="text-base" />
                </div>
                <div className="grid gap-1.5">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.part_cost')}</Label>
                    <Input value={cost} onChange={(e) => setCost(e.target.value)} inputMode="decimal" placeholder="0.00" className="text-base" />
                </div>
                <p className="text-muted-foreground text-xs">{t('vehicles.part_cost_hint')}</p>
            </div>
        </ResponsiveModal>
    );
}

// A compact "Add part to shopping" control for a maintenance event or repair. Opens a modal that posts with
// the origin id, so the part records what service it's for. Manages its own open state internally.
export function AddPartButton({ vehicleId, scheduleId, repairId, t }: { vehicleId: number; scheduleId?: number; repairId?: number; t: TFn }) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [cost, setCost] = useState('');
    const [processing, setProcessing] = useState(false);

    // Re-seed on every open so a previous submission doesn't linger.
    useEffect(() => {
        if (open) {
            setName('');
            setCost('');
        }
    }, [open]);

    const submit = () => {
        setProcessing(true);
        router.post(
            `/m/vehicles/${vehicleId}/parts`,
            { name, estimated_cost: cost, maintenance_schedule_id: scheduleId, repair_id: repairId },
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="text-primary hover:bg-surface inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
            >
                <ShoppingCart className="size-4" /> {t('vehicles.add_part')}
            </button>
            <ResponsiveModal
                open={open}
                onOpenChange={setOpen}
                title={t('vehicles.add_part')}
                footer={
                    <Button type="button" onClick={submit} disabled={processing || name.trim() === ''} className="w-full">
                        {t('vehicles.add_part')}
                    </Button>
                }
            >
                <div className="flex flex-col gap-3 pb-2">
                    <div className="grid gap-1.5">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.part_name')}</Label>
                        <Input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder={t('vehicles.part_name_placeholder')}
                            className="text-base"
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.part_cost')}</Label>
                        <Input value={cost} onChange={(e) => setCost(e.target.value)} inputMode="decimal" placeholder="0.00" className="text-base" />
                    </div>
                </div>
            </ResponsiveModal>
        </>
    );
}
