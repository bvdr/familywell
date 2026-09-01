import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Pencil, Plus, Receipt } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import {
    intervalText,
    MAINT_STATUS_META,
    maintStatusLabel,
    mileageText,
    remainingText,
    templateLabel,
    vehicleTitle,
    type MaintenanceHistoryEntry,
    type MaintenanceSchedule,
    type VehicleHeader,
} from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

import VehicleNav from '../vehicle-nav';

type Props = { vehicle: VehicleHeader; schedule: MaintenanceSchedule; history: MaintenanceHistoryEntry[] };

export default function MaintenanceDetail({ vehicle, schedule, history }: Props) {
    const t = useT();
    const title = schedule.name || templateLabel(t, schedule.type);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
        { title: t('vehicles.maintenance'), href: `/m/vehicles/${vehicle.id}/maintenance` },
        { title, href: `/m/vehicles/${vehicle.id}/maintenance/${schedule.id}` },
    ];
    const color = MAINT_STATUS_META[schedule.status].color;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${vehicleTitle(vehicle)} · ${title}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex flex-col gap-1">
                    <h1 className="font-heading text-primary text-2xl">{vehicleTitle(vehicle)}</h1>
                    <p className="text-muted-foreground text-sm tabular-nums">{mileageText(t, vehicle.currentMileage)}</p>
                </header>

                <VehicleNav vehicleId={vehicle.id} active="maintenance" />

                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 className="font-heading text-primary truncate text-xl">{title}</h2>
                        <p className="text-muted-foreground text-sm">{intervalText(t, schedule.intervalKm, schedule.intervalMonths)}</p>
                    </div>
                    <Link
                        href={`/m/vehicles/${vehicle.id}/maintenance/${schedule.id}/edit`}
                        className="border-border text-primary hover:bg-surface flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                    >
                        <Pencil className="size-4" /> {t('vehicles.edit')}
                    </Link>
                </div>

                {/* Status card: the live derived state + how far off + the next-due target(s). */}
                <section className="bg-surface-card flex flex-col gap-4 rounded-2xl border p-5">
                    <div className="flex items-center gap-3">
                        <span className="size-3 shrink-0 rounded-full" style={{ background: color }} aria-hidden />
                        <span className="text-lg font-semibold" style={{ color }}>
                            {maintStatusLabel(t, schedule.status)}
                        </span>
                        <span className="text-muted-foreground ml-auto text-sm tabular-nums">{remainingText(t, schedule)}</span>
                    </div>

                    <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Stat label={t('vehicles.last_performed')} value={lastPerformedText(t, schedule)} />
                        <Stat label={t('vehicles.next_due')} value={nextDueText(t, schedule)} />
                    </dl>

                    {schedule.notes && <p className="text-text-secondary border-t pt-3 text-sm whitespace-pre-wrap">{schedule.notes}</p>}
                </section>

                {/* Actions. "Mark as completed" is live (writes history + advances the schedule); Add expense
                    / Create task are stubbed until the Phase 3 Spending / Tasks integration. */}
                <CompletePanel vehicle={vehicle} schedule={schedule} t={t} />

                <MaintenanceHistoryList history={history} t={t} />
            </div>
        </AppLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{label}</dt>
            <dd className="text-primary text-sm tabular-nums">{value}</dd>
        </div>
    );
}

// The "mark as completed" form: records a performance (date, mileage, cost, provider, notes). Defaults the
// date to today and the mileage to the car's current odometer, so the common "just did it" case is one tap.
function CompletePanel({ vehicle, schedule, t }: { vehicle: VehicleHeader; schedule: MaintenanceSchedule; t: TFn }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [form, setForm] = useState({
        performed_at: todayIso(),
        mileage: String(vehicle.currentMileage),
        cost: '',
        provider: '',
        notes: '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});

    const set = (k: keyof typeof form, v: string) => setForm((p) => ({ ...p, [k]: v }));

    const submit = () => {
        setProcessing(true);
        router.post(route('vehicles.maintenance.complete', [vehicle.id, schedule.id]), form, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setErrors({});
            },
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    if (!open) {
        return (
            <div className="flex flex-wrap gap-2">
                <Button type="button" onClick={() => setOpen(true)}>
                    <CheckCircle2 className="size-4" /> {t('vehicles.mark_completed')}
                </Button>
                {/* Phase 3 hooks — present so the workflow is visible, inert until Spending/Tasks are wired. */}
                <StubButton icon={<Receipt className="size-4" />} label={t('vehicles.add_expense')} hint={t('vehicles.coming_p3')} />
                <StubButton icon={<Plus className="size-4" />} label={t('vehicles.create_task')} hint={t('vehicles.coming_p3')} />
            </div>
        );
    }

    return (
        <section className="bg-surface-card flex flex-col gap-4 rounded-2xl border p-5">
            <h3 className="font-heading text-primary text-lg">{t('vehicles.record_completion')}</h3>
            <div className="grid gap-4 sm:grid-cols-2">
                <FormField label={t('vehicles.completed_date')} error={errors.performed_at}>
                    <Input type="date" value={form.performed_at} onChange={(e) => set('performed_at', e.target.value)} className="text-base" />
                </FormField>
                <FormField label={t('vehicles.completed_mileage')} error={errors.mileage}>
                    <Input value={form.mileage} onChange={(e) => set('mileage', e.target.value)} inputMode="numeric" className="text-base" />
                </FormField>
                <FormField label={t('vehicles.completed_cost')} error={errors.cost}>
                    <Input value={form.cost} onChange={(e) => set('cost', e.target.value)} inputMode="decimal" placeholder="0.00" className="text-base" />
                </FormField>
                <FormField label={t('vehicles.completed_provider')} error={errors.provider}>
                    <Input value={form.provider} onChange={(e) => set('provider', e.target.value)} placeholder={t('vehicles.provider_placeholder')} className="text-base" />
                </FormField>
            </div>
            <FormField label={t('vehicles.notes_label')} error={errors.notes}>
                <textarea
                    value={form.notes}
                    onChange={(e) => set('notes', e.target.value)}
                    rows={2}
                    className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                />
            </FormField>
            <div className="flex items-center gap-3">
                <Button type="button" onClick={submit} disabled={processing || form.mileage.trim() === '' || form.performed_at === ''} className="flex-1">
                    <CheckCircle2 className="size-4" /> {t('vehicles.save_completion')}
                </Button>
                <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                    {t('vehicles.cancel')}
                </Button>
            </div>
        </section>
    );
}

function MaintenanceHistoryList({ history, t }: { history: MaintenanceHistoryEntry[]; t: TFn }) {
    return (
        <section className="flex flex-col gap-2">
            <h3 className="font-heading text-primary text-lg">{t('vehicles.maint_history')}</h3>
            {history.length === 0 ? (
                <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-6 text-center text-sm">{t('vehicles.maint_history_empty')}</p>
            ) : (
                <ul className="bg-border grid gap-px overflow-hidden rounded-2xl border">
                    {history.map((h) => (
                        <li key={h.id} className="bg-surface-card flex flex-col gap-1 px-4 py-3">
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-primary text-sm font-medium">{h.performedAt}</span>
                                <span className="text-muted-foreground text-sm tabular-nums">{mileageText(t, h.mileage)}</span>
                            </div>
                            <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">
                                {h.cost != null && <span className="tabular-nums">{t('vehicles.cost_value', { value: h.cost.toFixed(2) })}</span>}
                                {h.provider && <span>{h.provider}</span>}
                            </div>
                            {h.notes && <p className="text-text-secondary text-sm whitespace-pre-wrap">{h.notes}</p>}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function StubButton({ icon, label, hint }: { icon: ReactNode; label: string; hint: string }) {
    return (
        <span
            title={hint}
            className="text-muted-foreground/50 border-border/60 flex cursor-default items-center gap-1.5 rounded-lg border border-dashed px-3 py-2 text-sm font-medium"
        >
            {icon}
            {label}
        </span>
    );
}

function FormField({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

// "134,200 km · Mar 12 2026" or "Never" — the schedule's baseline.
function lastPerformedText(t: TFn, s: MaintenanceSchedule): string {
    if (s.neverPerformed) return t('vehicles.never');
    const parts: string[] = [];
    if (s.lastPerformedMileage != null) parts.push(mileageText(t, s.lastPerformedMileage));
    if (s.lastPerformedAt) parts.push(s.lastPerformedAt);
    return parts.join(' · ') || t('vehicles.never');
}

// "149,200 km · Mar 12 2027" — the next-due target(s), whichever triggers are active.
function nextDueText(t: TFn, s: MaintenanceSchedule): string {
    const parts: string[] = [];
    if (s.nextDueMileage != null) parts.push(mileageText(t, s.nextDueMileage));
    if (s.nextDueDate) parts.push(s.nextDueDate);
    return parts.length ? parts.join(' · ') : t('vehicles.next_due_unknown');
}

// Today as YYYY-MM-DD for the completion-date default (local date, not UTC — matches the user's day).
function todayIso(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
