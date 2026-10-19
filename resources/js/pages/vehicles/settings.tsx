import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Segmented } from '@/components/segmented';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import { save as saveInBackground } from '@/lib/save';
import { templateLabel, type SettingsTemplate, type UnitSystem, type VehicleSettings } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

type Props = {
    settings: VehicleSettings;
    unitSystems: UnitSystem[];
    canManage: boolean; // family admin? gates every write on this screen (server enforces it too)
};

// The keys the update endpoint accepts. Each control instant-saves only what it changed; the server ignores
// absent fields, so one control never clobbers another.
type Patch = Partial<{
    unit_system: UnitSystem;
    notify_maintenance: boolean;
    notify_documents: boolean;
    notify_mileage_milestone: boolean;
    template_intervals: Record<string, { km: number | null; months: number | null }>;
    template_reset: string;
}>;

export default function VehiclesSettings({ settings, unitSystems, canManage }: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: t('vehicles.settings'), href: route('vehicles.settings') },
    ];

    // Template intervals keep their own input state; this only sends them (lib/save: the next tap can't cancel it).
    const save = (patch: Patch) => void saveInBackground('patch', route('vehicles.settings.update'), patch);

    // Optimistic overlay for the instant controls (unit select + the three switches): the control flips
    // on tap and snaps back only if the save fails. Keyed by the settings prop name. Template intervals
    // already keep their own local input state, so they don't need this.
    const opt = useOptimistic<string | boolean>();
    const unitSystem = opt.get('unitSystem', settings.unitSystem) as UnitSystem;
    const notify = (key: 'notifyMaintenance' | 'notifyDocuments' | 'notifyMileageMilestone') => opt.get(key, settings[key]) as boolean;
    const saveInstant = (key: string, value: string | boolean, patch: Patch) => {
        opt.set(key, value);
        void saveInBackground('patch', route('vehicles.settings.update'), patch).then(() => opt.clear(key));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('vehicles.settings')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div>
                    <h1 className="font-heading text-primary text-2xl">{t('vehicles.settings')}</h1>
                    <p className="text-muted-foreground text-sm">{t('vehicles.settings_sub')}</p>
                </div>

                {/* Display units. No app-wide unit setting exists, so this is the module-level default (km vs
                    miles); distances are always stored in km and only shown in the chosen unit. */}
                <section className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
                    <div>
                        <h2 className="font-heading text-primary text-lg">{t('vehicles.unit_system')}</h2>
                        <p className="text-muted-foreground text-sm">{t('vehicles.unit_system_desc')}</p>
                    </div>
                    <div className="md:max-w-xs">
                        <Segmented
                            options={unitSystems.map((u) => ({ value: u, label: t(`vehicles.unit_system_${u}`) }))}
                            value={unitSystem}
                            onChange={(u) => saveInstant('unitSystem', u, { unit_system: u })}
                            disabled={!canManage}
                            ariaLabel={t('vehicles.unit_system')}
                        />
                    </div>
                </section>

                {/* Notifications: which conditions may raise an alert for the family (the per-user push mute
                    lives on the account Notifications screen). */}
                <section className="bg-surface-card flex flex-col gap-1 rounded-xl border p-5">
                    <div className="mb-2">
                        <h2 className="font-heading text-primary text-lg">{t('vehicles.notifications_settings')}</h2>
                        <p className="text-muted-foreground text-sm">{t('vehicles.notifications_settings_desc')}</p>
                    </div>
                    <ToggleRow
                        label={t('vehicles.notify_maintenance')}
                        hint={t('vehicles.notify_maintenance_hint')}
                        on={notify('notifyMaintenance')}
                        disabled={!canManage}
                        onToggle={() =>
                            saveInstant('notifyMaintenance', !notify('notifyMaintenance'), { notify_maintenance: !notify('notifyMaintenance') })
                        }
                    />
                    <ToggleRow
                        label={t('vehicles.notify_documents')}
                        hint={t('vehicles.notify_documents_hint')}
                        on={notify('notifyDocuments')}
                        disabled={!canManage}
                        onToggle={() => saveInstant('notifyDocuments', !notify('notifyDocuments'), { notify_documents: !notify('notifyDocuments') })}
                    />
                    <ToggleRow
                        label={t('vehicles.notify_milestone')}
                        hint={t('vehicles.notify_milestone_hint')}
                        on={notify('notifyMileageMilestone')}
                        disabled={!canManage}
                        onToggle={() =>
                            saveInstant('notifyMileageMilestone', !notify('notifyMileageMilestone'), {
                                notify_mileage_milestone: !notify('notifyMileageMilestone'),
                            })
                        }
                    />
                    <p className="text-muted-foreground mt-2 text-xs">{t('vehicles.notify_footnote')}</p>
                </section>

                {/* Customisable default maintenance-template intervals (spec 20). Editing a row overrides the
                    shipped default for new schedules created from that template. */}
                <section className="bg-surface-card flex flex-col gap-1 rounded-xl border p-5">
                    <div className="mb-2">
                        <h2 className="font-heading text-primary text-lg">{t('vehicles.template_intervals')}</h2>
                        <p className="text-muted-foreground text-sm">{t('vehicles.template_intervals_desc')}</p>
                    </div>
                    <div className="flex flex-col divide-y">
                        {settings.templates.map((tpl) => (
                            <TemplateRow key={tpl.key} tpl={tpl} canManage={canManage} save={save} t={t} />
                        ))}
                    </div>
                </section>

                {!canManage && <p className="text-muted-foreground px-1 text-xs">{t('vehicles.settings_admin_only')}</p>}
            </div>
        </AppLayout>
    );
}

// One editable template default: a km field + a months field, instant-saved on blur, with a reset-to-shipped
// link once it's been customised. Distances are entered in the family's unit; note we send km straight
// through here (the shipped defaults are km specs and editing them as km is the least surprising).
function TemplateRow({ tpl, canManage, save, t }: { tpl: SettingsTemplate; canManage: boolean; save: (patch: Patch) => void; t: TFn }) {
    const [km, setKm] = useState(tpl.intervalKm != null ? String(tpl.intervalKm) : '');
    const [months, setMonths] = useState(tpl.intervalMonths != null ? String(tpl.intervalMonths) : '');

    const commit = () => {
        const nextKm = km.trim() === '' ? null : parseInt(km, 10);
        const nextMonths = months.trim() === '' ? null : parseInt(months, 10);
        // No change from what's shown -> skip the round-trip.
        if (nextKm === tpl.intervalKm && nextMonths === tpl.intervalMonths) return;
        save({ template_intervals: { [tpl.key]: { km: nextKm, months: nextMonths } } });
    };

    return (
        <div className={`flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5 ${!canManage ? 'opacity-60' : ''}`}>
            <span className="text-primary min-w-0 flex-1 text-sm font-medium">
                {templateLabel(t, tpl.key)}
                {tpl.customised && <span className="text-muted-foreground ml-1.5 text-[11px] uppercase">· {t('vehicles.customised')}</span>}
            </span>
            <label className="flex items-center gap-1.5">
                <input
                    value={km}
                    onChange={(e) => setKm(e.target.value)}
                    onBlur={commit}
                    inputMode="numeric"
                    disabled={!canManage}
                    className="border-border bg-surface-card text-primary w-20 rounded-lg border px-2 py-1 text-right text-sm tabular-nums disabled:opacity-60"
                    aria-label={`${templateLabel(t, tpl.key)} km`}
                    placeholder="—"
                />
                <span className="text-muted-foreground text-xs">km</span>
            </label>
            <label className="flex items-center gap-1.5">
                <input
                    value={months}
                    onChange={(e) => setMonths(e.target.value)}
                    onBlur={commit}
                    inputMode="numeric"
                    disabled={!canManage}
                    className="border-border bg-surface-card text-primary w-16 rounded-lg border px-2 py-1 text-right text-sm tabular-nums disabled:opacity-60"
                    aria-label={`${templateLabel(t, tpl.key)} ${t('vehicles.months')}`}
                    placeholder="—"
                />
                <span className="text-muted-foreground text-xs">{t('vehicles.months')}</span>
            </label>
            {tpl.customised && canManage && (
                <button
                    type="button"
                    onClick={() => save({ template_reset: tpl.key })}
                    className="text-muted-foreground hover:text-primary text-xs underline"
                >
                    {t('vehicles.reset')}
                </button>
            )}
        </div>
    );
}

// A label (+ optional hint) with a switch on the right. Disabled (non-admin) dims the whole row.
function ToggleRow({ label, hint, on, disabled, onToggle }: { label: string; hint?: string; on: boolean; disabled?: boolean; onToggle: () => void }) {
    return (
        <div className={`flex items-center justify-between gap-3 py-2.5 ${disabled ? 'opacity-60' : ''}`}>
            <span className="min-w-0">
                <span className="text-primary block text-sm font-medium">{label}</span>
                {hint && <span className="text-muted-foreground block text-xs">{hint}</span>}
            </span>
            <button
                type="button"
                role="switch"
                aria-checked={on}
                aria-label={label}
                disabled={disabled}
                onClick={onToggle}
                className={`relative h-6 w-10 shrink-0 rounded-full transition-colors disabled:cursor-not-allowed ${on ? 'bg-accent-strong' : 'bg-border'}`}
            >
                <span className={`absolute top-0.5 size-5 rounded-full bg-white shadow-sm transition-all ${on ? 'left-[18px]' : 'left-0.5'}`} />
            </button>
        </div>
    );
}
