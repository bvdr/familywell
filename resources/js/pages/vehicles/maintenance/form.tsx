import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Trash2 } from 'lucide-react';
import { type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { FormActions } from '@/components/form-actions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { intervalText, templateLabel, vehicleTitle, type MaintenanceSchedule, type MaintenanceTemplate, type VehicleHeader } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

type Props = {
    vehicle: VehicleHeader;
    schedule: MaintenanceSchedule | null; // null => Add mode
    templates: MaintenanceTemplate[];
};

// The request body (snake_case, matching VehicleMaintenanceController::validateSchedule). Numbers/dates
// are held as strings and coerced server-side; empty strings become null there.
type Fields = {
    name: string;
    type: string;
    interval_km: string;
    interval_months: string;
    last_performed_at: string;
    last_performed_mileage: string;
    notes: string;
    active: boolean;
};

function initialFields(s: MaintenanceSchedule | null): Fields {
    return {
        name: s?.name ?? '',
        type: s?.type ?? 'custom',
        interval_km: s?.intervalKm != null ? String(s.intervalKm) : '',
        interval_months: s?.intervalMonths != null ? String(s.intervalMonths) : '',
        last_performed_at: s?.lastPerformedAt ?? '',
        last_performed_mileage: s?.lastPerformedMileage != null ? String(s.lastPerformedMileage) : '',
        notes: s?.notes ?? '',
        active: s?.active ?? true,
    };
}

export default function MaintenanceForm({ vehicle, schedule, templates }: Props) {
    const t = useT();
    const editing = schedule !== null;
    const { data, setData, post, patch, processing, errors } = useForm<Fields>(initialFields(schedule));

    const back = editing ? `/m/vehicles/${vehicle.id}/maintenance/${schedule!.id}` : `/m/vehicles/${vehicle.id}/maintenance`;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
        { title: t('vehicles.maintenance'), href: `/m/vehicles/${vehicle.id}/maintenance` },
        { title: editing ? t('vehicles.edit_maintenance') : t('vehicles.add_maintenance'), href: back },
    ];

    // Applying a template prefills the name + both intervals; the user can then adjust anything before
    // saving. "Custom" clears to a blank rule the user names themselves.
    const applyTemplate = (tpl: MaintenanceTemplate) => {
        setData((prev) => ({
            ...prev,
            type: tpl.key,
            name: templateLabel(t, tpl.key),
            interval_km: tpl.intervalKm != null ? String(tpl.intervalKm) : '',
            interval_months: tpl.intervalMonths != null ? String(tpl.intervalMonths) : '',
        }));
    };
    const applyCustom = () => setData((prev) => ({ ...prev, type: 'custom', name: '', interval_km: '', interval_months: '' }));

    const submit = () => {
        if (editing) {
            patch(route('vehicles.maintenance.update', [vehicle.id, schedule!.id]), { preserveScroll: true });
        } else {
            post(route('vehicles.maintenance.store', vehicle.id));
        }
    };

    const remove = () => {
        if (window.confirm(t('vehicles.maint_delete_confirm'))) {
            router.delete(route('vehicles.maintenance.destroy', [vehicle.id, schedule!.id]));
        }
    };

    // At least one interval must be set (server enforces it too); disable submit until name + one interval.
    const hasInterval = data.interval_km.trim() !== '' || data.interval_months.trim() !== '';
    const canSubmit = data.name.trim() !== '' && hasInterval && !processing;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? t('vehicles.edit_maintenance') : t('vehicles.add_maintenance')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
                <header className="flex items-center gap-3">
                    <Link href={back} className="text-muted-foreground hover:text-primary" aria-label={t('vehicles.back')}>
                        <ArrowLeft className="size-5" />
                    </Link>
                    <h1 className="font-heading text-primary text-2xl">
                        {editing ? t('vehicles.edit_maintenance') : t('vehicles.add_maintenance')}
                    </h1>
                </header>

                {!editing && (
                    <section className="flex flex-col gap-3">
                        <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.choose_template')}</h2>
                        <div className="flex flex-wrap gap-2">
                            {templates.map((tpl) => (
                                <button
                                    key={tpl.key}
                                    type="button"
                                    onClick={() => applyTemplate(tpl)}
                                    className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors ${
                                        data.type === tpl.key
                                            ? 'border-[var(--primary)] bg-accent-subtle text-primary'
                                            : 'border-border text-primary hover:bg-surface'
                                    }`}
                                >
                                    {templateLabel(t, tpl.key)}
                                </button>
                            ))}
                            <button
                                type="button"
                                onClick={applyCustom}
                                className={`rounded-lg border border-dashed px-3 py-1.5 text-sm font-medium transition-colors ${
                                    data.type === 'custom'
                                        ? 'border-[var(--primary)] bg-accent-subtle text-primary'
                                        : 'border-border text-primary hover:bg-surface'
                                }`}
                            >
                                + {t('vehicles.custom_maintenance')}
                            </button>
                        </div>
                    </section>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                    className="flex flex-col gap-5"
                >
                    <Field label={t('vehicles.maint_name_label')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder={t('vehicles.maint_name_placeholder')} className="text-base" />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('vehicles.interval_km_label')} error={errors.interval_km} hint={t('vehicles.interval_km_hint')}>
                            <Input value={data.interval_km} onChange={(e) => setData('interval_km', e.target.value)} inputMode="numeric" placeholder="15000" className="text-base" />
                        </Field>
                        <Field label={t('vehicles.interval_months_label')} error={errors.interval_months} hint={t('vehicles.interval_months_hint')}>
                            <Input value={data.interval_months} onChange={(e) => setData('interval_months', e.target.value)} inputMode="numeric" placeholder="12" className="text-base" />
                        </Field>
                    </div>
                    {hasInterval && (
                        <p className="text-muted-foreground -mt-2 text-xs">
                            {t('vehicles.interval_preview', {
                                value: intervalText(t, num(data.interval_km), num(data.interval_months)) || '—',
                            })}
                        </p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('vehicles.last_done_date_label')} error={errors.last_performed_at} hint={t('vehicles.last_done_hint')}>
                            <Input type="date" value={data.last_performed_at} onChange={(e) => setData('last_performed_at', e.target.value)} className="text-base" />
                        </Field>
                        <Field label={t('vehicles.last_done_mileage_label')} error={errors.last_performed_mileage}>
                            <Input value={data.last_performed_mileage} onChange={(e) => setData('last_performed_mileage', e.target.value)} inputMode="numeric" placeholder={String(vehicle.currentMileage)} className="text-base" />
                        </Field>
                    </div>

                    <Field label={t('vehicles.notes_label')} error={errors.notes}>
                        <textarea
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            rows={3}
                            className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                        />
                    </Field>

                    {editing && (
                        <label className="flex items-center gap-2.5 text-sm">
                            <input
                                type="checkbox"
                                checked={data.active}
                                onChange={(e) => setData('active', e.target.checked)}
                                className="size-4 rounded border-input"
                            />
                            <span className="text-primary">{t('vehicles.maint_active_label')}</span>
                            <span className="text-muted-foreground text-xs">{t('vehicles.maint_active_hint')}</span>
                        </label>
                    )}

                    <FormActions>
                        <Button type="submit" disabled={!canSubmit} className="flex-1">
                            <Check className="size-4" /> {editing ? t('vehicles.save') : t('vehicles.add_maintenance')}
                        </Button>
                        {editing && (
                            <Button type="button" variant="outline" onClick={remove} className="text-destructive">
                                <Trash2 className="size-4" /> {t('vehicles.delete')}
                            </Button>
                        )}
                    </FormActions>
                </form>
            </div>
        </AppLayout>
    );
}

// Parse a numeric form string to a number or null (blank/invalid => null), for the interval preview.
function num(v: string): number | null {
    const n = parseInt(v, 10);
    return Number.isFinite(n) ? n : null;
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
