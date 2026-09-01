import { Head, router } from '@inertiajs/react';
import { Droplet, Fuel, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import {
    consumptionText,
    costPerDistanceText,
    distanceUnitLabel,
    fmtDistance,
    fmtMileage,
    mileageText,
    vehicleTitle,
    volumeText,
    volumeUnitLabel,
    type FuelEntry,
    type FuelStats,
    type UnitSystem,
    type VehicleHeader,
} from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

import VehicleNav from '../vehicle-nav';

type Props = {
    vehicle: VehicleHeader;
    unitSystem: UnitSystem;
    entries: FuelEntry[];
    stats: FuelStats;
    canExpense: boolean;
};

export default function VehicleFuel({ vehicle, unitSystem, entries, stats, canExpense }: Props) {
    const t = useT();
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
        { title: t('vehicles.fuel'), href: `/m/vehicles/${vehicle.id}/fuel` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${vehicleTitle(vehicle)} · ${t('vehicles.fuel')}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex flex-col gap-1">
                    <h1 className="font-heading text-primary text-2xl">{vehicleTitle(vehicle)}</h1>
                    <p className="text-muted-foreground text-sm tabular-nums">{mileageText(t, vehicle.currentMileage, unitSystem)}</p>
                </header>

                <VehicleNav vehicleId={vehicle.id} active="fuel" />

                <div className="flex items-center justify-between gap-3">
                    <h2 className="font-heading text-primary text-xl">{t('vehicles.fuel')}</h2>
                    {!adding && (
                        <Button type="button" onClick={() => setAdding(true)}>
                            <Plus className="size-4" /> {t('vehicles.add_fuel')}
                        </Button>
                    )}
                </div>

                {/* Computed metrics — only once there's something to compute. */}
                {stats.entryCount > 0 && <FuelMetrics stats={stats} unitSystem={unitSystem} t={t} />}

                {adding && (
                    <FuelForm
                        vehicle={vehicle}
                        entry={null}
                        unitSystem={unitSystem}
                        canExpense={canExpense}
                        t={t}
                        onDone={() => setAdding(false)}
                        onCancel={() => setAdding(false)}
                    />
                )}

                {entries.length === 0 && !adding ? (
                    <EmptyState t={t} />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {entries.map((e) =>
                            editingId === e.id ? (
                                <FuelForm
                                    key={e.id}
                                    vehicle={vehicle}
                                    entry={e}
                                    unitSystem={unitSystem}
                                    canExpense={canExpense}
                                    t={t}
                                    onDone={() => setEditingId(null)}
                                    onCancel={() => setEditingId(null)}
                                />
                            ) : (
                                <FuelCard key={e.id} vehicle={vehicle} entry={e} unitSystem={unitSystem} t={t} onEdit={() => setEditingId(e.id)} />
                            ),
                        )}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}

function EmptyState({ t }: { t: TFn }) {
    return (
        <div className="text-muted-foreground bg-surface-card flex flex-col items-center gap-2 rounded-2xl border px-4 py-10 text-center">
            <Fuel className="size-8 opacity-40" />
            <p className="text-primary font-medium">{t('vehicles.fuel_empty_title')}</p>
            <p className="max-w-sm text-sm">{t('vehicles.fuel_empty_body')}</p>
        </div>
    );
}

// The computed metrics grid: consumption (latest / average), cost per km, monthly + annual fuel cost, plus
// the totals. Each cell falls back to an em dash until there's enough history to compute it.
function FuelMetrics({ stats, unitSystem, t }: { stats: FuelStats; unitSystem: UnitSystem; t: TFn }) {
    const cells: { label: string; value: string }[] = [
        { label: t('vehicles.fuel_consumption_latest'), value: consumptionText(stats.latestConsumption, unitSystem) },
        { label: t('vehicles.fuel_consumption_avg'), value: consumptionText(stats.avgConsumption, unitSystem) },
        { label: t('vehicles.fuel_cost_per_km', { unit: distanceUnitLabel(unitSystem) }), value: costPerDistanceText(stats.costPerKm, unitSystem) },
        { label: t('vehicles.fuel_monthly'), value: stats.monthlyCost != null ? t('vehicles.cost_value', { value: stats.monthlyCost.toFixed(2) }) : '—' },
        { label: t('vehicles.fuel_annual'), value: stats.annualCost != null ? t('vehicles.cost_value', { value: stats.annualCost.toFixed(2) }) : '—' },
        { label: t('vehicles.fuel_total_cost'), value: stats.totalCost > 0 ? t('vehicles.cost_value', { value: stats.totalCost.toFixed(2) }) : '—' },
    ];

    return (
        <section className="bg-surface-card grid grid-cols-2 gap-px overflow-hidden rounded-2xl border sm:grid-cols-3">
            {cells.map((c) => (
                <div key={c.label} className="bg-surface-card flex flex-col gap-0.5 p-4">
                    <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{c.label}</span>
                    <span className="text-primary text-lg font-semibold tabular-nums">{c.value}</span>
                </div>
            ))}
        </section>
    );
}

// One fill record: date, volume, cost, odometer, a partial-tank badge, and the station/note.
function FuelCard({ vehicle, entry, unitSystem, t, onEdit }: { vehicle: VehicleHeader; entry: FuelEntry; unitSystem: UnitSystem; t: TFn; onEdit: () => void }) {
    const remove = () => {
        if (confirm(t('vehicles.fuel_delete_confirm'))) {
            router.delete(`/m/vehicles/${vehicle.id}/fuel/${entry.id}`, { preserveScroll: true });
        }
    };

    return (
        <li className="bg-surface-card flex flex-col gap-2 rounded-2xl border p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-primary flex items-center gap-2 font-medium tabular-nums">
                        <Droplet className="text-muted-foreground size-4 shrink-0" />
                        {volumeText(entry.liters, unitSystem)}
                        {!entry.isFullTank && <span className="text-muted-foreground rounded-full border px-1.5 py-0.5 text-[11px] font-medium">{t('vehicles.fuel_partial')}</span>}
                    </h3>
                    <p className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm">
                        {entry.filledOn && <span>{entry.filledOn}</span>}
                        <span className="tabular-nums">{fmtDistance(entry.mileage, unitSystem)} {distanceUnitLabel(unitSystem)}</span>
                        {entry.cost != null && <span className="tabular-nums">{t('vehicles.cost_value', { value: entry.cost.toFixed(2) })}</span>}
                        {entry.station && <span>{entry.station}</span>}
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                    <IconButton label={t('vehicles.edit')} onClick={onEdit}>
                        <Pencil className="size-4" />
                    </IconButton>
                    <IconButton label={t('vehicles.delete')} onClick={remove}>
                        <Trash2 className="size-4" />
                    </IconButton>
                </div>
            </div>
            {entry.note && <p className="text-text-secondary text-sm whitespace-pre-wrap">{entry.note}</p>}
        </li>
    );
}

// The add/edit form. Volume is entered in the family's unit but we keep it simple: the value is sent as-is
// (litres/gallons) — the server stores litres, so imperial families enter gallons and we convert on submit.
function FuelForm({
    vehicle,
    entry,
    unitSystem,
    canExpense,
    t,
    onDone,
    onCancel,
}: {
    vehicle: VehicleHeader;
    entry: FuelEntry | null;
    unitSystem: UnitSystem;
    canExpense: boolean;
    t: TFn;
    onDone: () => void;
    onCancel: () => void;
}) {
    const editing = entry !== null;
    // Volume is shown/edited in the display unit; convert to litres only when posting (imperial gallons).
    const litersToDisplay = (l: number) => (unitSystem === 'imperial' ? l / 3.785411784 : l);
    const displayToLiters = (v: number) => (unitSystem === 'imperial' ? v * 3.785411784 : v);

    const [form, setForm] = useState({
        filled_on: entry?.filledOn ?? todayIso(),
        liters: entry?.liters != null ? String(round1(litersToDisplay(entry.liters))) : '',
        cost: entry?.cost != null ? String(entry.cost) : '',
        mileage: entry?.mileage != null ? String(entry.mileage) : String(vehicle.currentMileage),
        is_full_tank: entry?.isFullTank ?? true,
        station: entry?.station ?? '',
        note: entry?.note ?? '',
        create_expense: false,
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const set = (k: keyof typeof form, v: string | boolean) => setForm((p) => ({ ...p, [k]: v }));

    const submit = () => {
        setProcessing(true);
        // Convert the entered volume (display unit) to litres; mileage entered in display unit -> km.
        const litersValue = parseFloat(form.liters);
        const mileageValue = parseInt(form.mileage, 10);
        const payload = {
            filled_on: form.filled_on,
            liters: Number.isFinite(litersValue) ? round2(displayToLiters(litersValue)) : form.liters,
            cost: form.cost,
            mileage: Number.isFinite(mileageValue) ? (unitSystem === 'imperial' ? Math.round(mileageValue * 1.60934) : mileageValue) : form.mileage,
            is_full_tank: form.is_full_tank,
            station: form.station,
            note: form.note,
            ...(!editing && canExpense ? { create_expense: form.create_expense } : {}),
            ...(editing ? { _method: 'patch' } : {}),
        };
        const url = editing ? `/m/vehicles/${vehicle.id}/fuel/${entry!.id}` : `/m/vehicles/${vehicle.id}/fuel`;
        router.post(url, payload, {
            preserveScroll: true,
            onSuccess: onDone,
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <section className="bg-surface-card flex flex-col gap-4 rounded-2xl border p-5">
            <h3 className="font-heading text-primary text-lg">{editing ? t('vehicles.edit_fuel') : t('vehicles.add_fuel')}</h3>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('vehicles.completed_date')} error={errors.filled_on}>
                    <Input type="date" value={form.filled_on} onChange={(e) => set('filled_on', e.target.value)} className="text-base" />
                </Field>
                <Field label={t('vehicles.fuel_liters', { unit: volumeUnitLabel(unitSystem) })} error={errors.liters}>
                    <Input value={form.liters} onChange={(e) => set('liters', e.target.value)} inputMode="decimal" placeholder="0.0" className="text-base" />
                </Field>
                <Field label={t('vehicles.completed_mileage')} error={errors.mileage}>
                    <Input value={form.mileage} onChange={(e) => set('mileage', e.target.value)} inputMode="numeric" placeholder={fmtMileage(vehicle.currentMileage)} className="text-base" />
                </Field>
                <Field label={t('vehicles.completed_cost')} error={errors.cost}>
                    <Input value={form.cost} onChange={(e) => set('cost', e.target.value)} inputMode="decimal" placeholder="0.00" className="text-base" />
                </Field>
                <Field label={t('vehicles.fuel_station')} error={errors.station}>
                    <Input value={form.station} onChange={(e) => set('station', e.target.value)} placeholder={t('vehicles.fuel_station_placeholder')} className="text-base" />
                </Field>
            </div>

            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.is_full_tank} onChange={(e) => set('is_full_tank', e.target.checked)} className="size-4 rounded border" />
                <span className="text-text-secondary">{t('vehicles.fuel_full_tank')}</span>
            </label>
            <p className="text-muted-foreground -mt-2 text-xs">{t('vehicles.fuel_full_tank_hint')}</p>

            <Field label={t('vehicles.notes_label')} error={errors.note}>
                <textarea
                    value={form.note}
                    onChange={(e) => set('note', e.target.value)}
                    rows={2}
                    className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                />
            </Field>

            {!editing && canExpense && (
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.create_expense} onChange={(e) => set('create_expense', e.target.checked)} className="size-4 rounded border" />
                    <span className="text-text-secondary">{t('vehicles.fuel_add_to_spending')}</span>
                </label>
            )}

            <div className="flex items-center gap-3">
                <Button type="button" onClick={submit} disabled={processing || form.liters.trim() === '' || form.mileage.trim() === '' || form.filled_on === ''} className="flex-1">
                    {t('vehicles.save')}
                </Button>
                <Button type="button" variant="outline" onClick={onCancel}>
                    {t('vehicles.cancel')}
                </Button>
            </div>
        </section>
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

const round1 = (n: number): number => Math.round(n * 10) / 10;
const round2 = (n: number): number => Math.round(n * 100) / 100;

function todayIso(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
