import { Head, router } from '@inertiajs/react';
import { Bell, CircleDot, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import { distanceText, mileageText, tireSeasonLabel, vehicleTitle, type TireSet, type UnitSystem, type VehicleHeader } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

import VehicleNav from '../vehicle-nav';

type Props = {
    vehicle: VehicleHeader;
    unitSystem: UnitSystem;
    sets: TireSet[];
    canRemind: boolean;
};

const SEASONS = ['summer', 'winter', 'all_season'] as const;

export default function VehicleTires({ vehicle, unitSystem, sets, canRemind }: Props) {
    const t = useT();
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    // Optimistic mount: the tapped set becomes "on the car" instantly and the others un-mount (only one set is
    // ever mounted). Cleared on settle so the reloaded props are authoritative. Optimistic remove hides a set
    // the moment it's deleted, reappearing only if the delete fails.
    const [optMountedId, setOptMountedId] = useState<number | null>(null);
    const removed = useOptimistic<boolean>();
    const visibleSets = sets.filter((s) => !removed.get(s.id, false));
    const isMounted = (s: TireSet) => (optMountedId !== null ? s.id === optMountedId : s.isMounted);
    const mountSet = (s: TireSet) => {
        setOptMountedId(s.id);
        router.post(
            `/m/vehicles/${vehicle.id}/tires/${s.id}/mount`,
            {},
            { preserveScroll: true, preserveState: true, onSuccess: () => setOptMountedId(null), onError: () => setOptMountedId(null) },
        );
    };
    const removeSet = (s: TireSet) => {
        if (!confirm(t('vehicles.tire_delete_confirm'))) return;
        removed.set(s.id, true);
        router.delete(`/m/vehicles/${vehicle.id}/tires/${s.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => removed.clear(s.id),
            onError: () => removed.clear(s.id),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
        { title: t('vehicles.tires'), href: `/m/vehicles/${vehicle.id}/tires` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${vehicleTitle(vehicle)} · ${t('vehicles.tires')}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex flex-col gap-1">
                    <h1 className="font-heading text-primary text-2xl">{vehicleTitle(vehicle)}</h1>
                    <p className="text-muted-foreground text-sm tabular-nums">{mileageText(t, vehicle.currentMileage, unitSystem)}</p>
                </header>

                <VehicleNav vehicleId={vehicle.id} active="tires" />

                <div className="flex items-center justify-between gap-3">
                    <h2 className="font-heading text-primary text-xl">{t('vehicles.tires')}</h2>
                    <Button type="button" onClick={() => setAdding(true)}>
                        <Plus className="size-4" /> {t('vehicles.add_tire_set')}
                    </Button>
                </div>

                {visibleSets.length === 0 ? (
                    <EmptyState t={t} />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {visibleSets.map((s) => (
                            <TireCard
                                key={s.id}
                                vehicle={vehicle}
                                set={s}
                                mounted={isMounted(s)}
                                unitSystem={unitSystem}
                                canRemind={canRemind}
                                t={t}
                                onEdit={() => setEditingId(s.id)}
                                onMount={() => mountSet(s)}
                                onRemove={() => removeSet(s)}
                            />
                        ))}
                    </ul>
                )}
            </div>

            {/* Always mounted (per the shared-modal pattern in ItemFormModal): open drives both the "add"
                and "edit" cases, and tire is looked up fresh from `sets` by editingId. */}
            <TireFormModal
                open={adding || editingId !== null}
                onOpenChange={(v) => {
                    if (!v) {
                        setAdding(false);
                        setEditingId(null);
                    }
                }}
                vehicle={vehicle}
                tire={editingId !== null ? (sets.find((s) => s.id === editingId) ?? null) : null}
                t={t}
            />
        </AppLayout>
    );
}

function EmptyState({ t }: { t: TFn }) {
    return (
        <div className="text-muted-foreground bg-surface-card flex flex-col items-center gap-2 rounded-2xl border px-4 py-10 text-center">
            <CircleDot className="size-8 opacity-40" />
            <p className="text-primary font-medium">{t('vehicles.tires_empty_title')}</p>
            <p className="max-w-sm text-sm">{t('vehicles.tires_empty_body')}</p>
        </div>
    );
}

// One tyre set. The mounted set is highlighted with a primary ring + an "On the car" badge; the others offer
// a "Mount this set" action that swaps it onto the car. A seasonal reminder button spawns a Tasks reminder.
function TireCard({
    vehicle,
    set,
    mounted,
    unitSystem,
    canRemind,
    t,
    onEdit,
    onMount,
    onRemove,
}: {
    vehicle: VehicleHeader;
    set: TireSet;
    mounted: boolean; // the (optimistic) mounted state — parent owns it so only one set shows as "on the car"
    unitSystem: UnitSystem;
    canRemind: boolean;
    t: TFn;
    onEdit: () => void;
    onMount: () => void;
    onRemove: () => void;
}) {
    const url = `/m/vehicles/${vehicle.id}/tires/${set.id}`;
    const remind = () => router.post(`${url}/remind`, {}, { preserveScroll: true });

    // "Fitted 2026-01-12 at 148,000 km", skipping whichever part is missing.
    const fitted: string | null = set.installedOn
        ? t('vehicles.tire_fitted', { date: set.installedOn }) +
          (set.installedMileage != null ? ' ' + t('vehicles.tire_fitted_at', { mileage: distanceText(set.installedMileage, unitSystem) }) : '')
        : null;

    const meta = [
        set.size,
        set.treadDepthMm != null ? `${set.treadDepthMm} mm` : null,
        set.storageLocation,
    ].filter(Boolean) as string[];

    return (
        <li className={`bg-surface-card flex flex-col gap-2 rounded-2xl border p-4 ${mounted ? 'border-[var(--primary)] ring-1 ring-[var(--primary)]/30' : ''}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-primary flex flex-wrap items-center gap-2 font-medium">
                        <CircleDot className="text-muted-foreground size-4 shrink-0" />
                        {tireSeasonLabel(t, set.season)}
                        {set.brand && <span className="text-muted-foreground font-normal">· {set.brand}</span>}
                        {mounted && (
                            <span className="rounded-full border border-[var(--primary)]/40 px-1.5 py-0.5 text-[11px] font-medium text-primary">
                                {t('vehicles.tire_on_car')}
                            </span>
                        )}
                    </h3>
                    {meta.length > 0 && <p className="text-muted-foreground text-sm tabular-nums">{meta.join(' · ')}</p>}
                    {fitted && <p className="text-muted-foreground text-xs tabular-nums">{fitted}</p>}
                </div>
                <div className="flex shrink-0 items-center gap-1">
                    <IconButton label={t('vehicles.edit')} onClick={onEdit}>
                        <Pencil className="size-4" />
                    </IconButton>
                    <IconButton label={t('vehicles.delete')} onClick={onRemove}>
                        <Trash2 className="size-4" />
                    </IconButton>
                </div>
            </div>

            {set.notes && <p className="text-text-secondary text-sm whitespace-pre-wrap">{set.notes}</p>}

            <div className="flex flex-wrap gap-2">
                {!mounted && (
                    <Button type="button" variant="outline" size="sm" onClick={onMount}>
                        {t('vehicles.tire_mount')}
                    </Button>
                )}
                {canRemind && (
                    <Button type="button" variant="ghost" size="sm" onClick={remind}>
                        <Bell className="size-4" /> {t('vehicles.tire_remind')}
                    </Button>
                )}
            </div>
        </li>
    );
}

// Add/edit modal. `is_mounted` is offered only on create (existing sets swap via the card's mount action).
// `tire` null => add; a TireSet => edit. Stays mounted; re-seeds its fields whenever it opens (mirrors
// ItemFormModal in inventory-modals.tsx) so switching between "add" and editing different rows never
// leaks stale field values from a previous open.
function TireFormModal({
    open,
    onOpenChange,
    vehicle,
    tire,
    t,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    vehicle: VehicleHeader;
    tire: TireSet | null;
    t: TFn;
}) {
    const editing = tire !== null;
    const [form, setForm] = useState({
        season: tire?.season ?? 'summer',
        brand: tire?.brand ?? '',
        size: tire?.size ?? '',
        tread_depth_mm: tire?.treadDepthMm != null ? String(tire.treadDepthMm) : '',
        storage_location: tire?.storageLocation ?? '',
        is_mounted: tire?.isMounted ?? false,
        notes: tire?.notes ?? '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (!open) return;
        setForm({
            season: tire?.season ?? 'summer',
            brand: tire?.brand ?? '',
            size: tire?.size ?? '',
            tread_depth_mm: tire?.treadDepthMm != null ? String(tire.treadDepthMm) : '',
            storage_location: tire?.storageLocation ?? '',
            is_mounted: tire?.isMounted ?? false,
            notes: tire?.notes ?? '',
        });
        setErrors({});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, tire?.id]);

    const setField = (k: keyof typeof form, v: string | boolean) => setForm((p) => ({ ...p, [k]: v }));

    const submit = () => {
        setProcessing(true);
        const payload = {
            season: form.season,
            brand: form.brand,
            size: form.size,
            tread_depth_mm: form.tread_depth_mm,
            storage_location: form.storage_location,
            notes: form.notes,
            ...(!editing ? { is_mounted: form.is_mounted } : {}),
            ...(editing ? { _method: 'patch' } : {}),
        };
        const url = editing ? `/m/vehicles/${vehicle.id}/tires/${tire!.id}` : `/m/vehicles/${vehicle.id}/tires`;
        router.post(url, payload, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? t('vehicles.edit_tire_set') : t('vehicles.add_tire_set')}
            footer={
                <Button type="button" onClick={submit} disabled={processing} className="w-full">
                    {t('vehicles.save')}
                </Button>
            }
        >
            <div className="flex flex-col gap-4 pb-1">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('vehicles.tire_season')} error={errors.season}>
                        <select
                            value={form.season}
                            onChange={(e) => setField('season', e.target.value)}
                            className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                        >
                            {SEASONS.map((s) => (
                                <option key={s} value={s}>
                                    {tireSeasonLabel(t, s)}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label={t('vehicles.tire_brand')} error={errors.brand}>
                        <Input value={form.brand} onChange={(e) => setField('brand', e.target.value)} className="text-base" />
                    </Field>
                    <Field label={t('vehicles.tire_size')} error={errors.size}>
                        <Input value={form.size} onChange={(e) => setField('size', e.target.value)} placeholder={t('vehicles.tire_size_placeholder')} className="text-base" />
                    </Field>
                    <Field label={t('vehicles.tire_tread')} error={errors.tread_depth_mm}>
                        <Input value={form.tread_depth_mm} onChange={(e) => setField('tread_depth_mm', e.target.value)} inputMode="decimal" placeholder="0.0" className="text-base" />
                    </Field>
                    <Field label={t('vehicles.tire_storage')} error={errors.storage_location}>
                        <Input
                            value={form.storage_location}
                            onChange={(e) => setField('storage_location', e.target.value)}
                            placeholder={t('vehicles.tire_storage_placeholder')}
                            className="text-base"
                        />
                    </Field>
                </div>

                <Field label={t('vehicles.notes_label')} error={errors.notes}>
                    <textarea
                        value={form.notes}
                        onChange={(e) => setField('notes', e.target.value)}
                        rows={2}
                        className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                    />
                </Field>

                {!editing && (
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.is_mounted} onChange={(e) => setField('is_mounted', e.target.checked)} className="size-4 rounded border" />
                        <span className="text-text-secondary">{t('vehicles.tire_set_mounted_now')}</span>
                    </label>
                )}
            </div>
        </ResponsiveModal>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function IconButton({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            className="text-muted-foreground hover:text-primary hover:bg-surface flex size-8 items-center justify-center rounded-lg transition-colors"
        >
            {children}
        </button>
    );
}
