import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, ListChecks, Pencil, Plus, Receipt } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
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
    type MaintenanceLinkedTask,
    type MaintenanceSchedule,
    type ProviderOption,
    type VehicleHeader,
    useCarMoney,
} from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

import { AddPartButton } from '../parts';
import ProviderPicker from '../provider-picker';
import VehicleNav from '../vehicle-nav';

type Props = {
    vehicle: VehicleHeader;
    schedule: MaintenanceSchedule;
    history: MaintenanceHistoryEntry[];
    linkedTask: MaintenanceLinkedTask | null;
    canExpense: boolean;
    canShopping: boolean;
    providers: ProviderOption[];
};

export default function MaintenanceDetail({ vehicle, schedule, history, linkedTask, canExpense, canShopping, providers }: Props) {
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
                        <p className="text-muted-foreground text-sm">
                            {schedule.oneOff ? t('vehicles.one_off') : intervalText(t, schedule.intervalKm, schedule.intervalMonths)}
                        </p>
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

                {/* The open linked task (P3), if this schedule spawned one when it fell due. */}
                {linkedTask && <LinkedTaskBanner task={linkedTask} t={t} />}

                {/* Actions. "Mark as completed" records the service (and closes the linked task + logs any
                    cost to Spending); "Create task" and "Add expense" wire into the Tasks / Spending modules. */}
                <CompletePanel vehicle={vehicle} schedule={schedule} linkedTask={linkedTask} canExpense={canExpense} providers={providers} t={t} />

                {/* V1.1: request a part for this service — creates a labelled row on the family shopping list. */}
                {canShopping && (
                    <div>
                        <AddPartButton vehicleId={vehicle.id} scheduleId={schedule.id} t={t} />
                    </div>
                )}

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

// A pointer to the open linked task (Maintenance -> Tasks). Deep-links into the Tasks module so checking it
// off there completes the maintenance (via the task.saved hook), closing the round-trip.
function LinkedTaskBanner({ task, t }: { task: MaintenanceLinkedTask; t: TFn }) {
    const href = task.listId ? `/m/tasks/lists/${task.listId}` : '/m/tasks';
    return (
        <Link
            href={href}
            className="border-border bg-surface hover:bg-surface-card flex items-center gap-3 rounded-xl border px-4 py-3 text-sm transition-colors"
        >
            <ListChecks className="text-primary size-4 shrink-0" />
            <span className="text-text-secondary min-w-0">
                <span className="text-primary font-medium">{t('vehicles.linked_task')}</span>
                <span className="truncate"> · {task.title}</span>
            </span>
            <span className="text-muted-foreground ml-auto shrink-0 text-xs">{t('vehicles.view_task')}</span>
        </Link>
    );
}

// The action row + its two inline forms: "mark as completed" (records a performance, defaulting the date to
// today and mileage to the car's odometer), and "add expense" (records a car cost into Spending). "Create
// task" is a one-shot POST — idempotent server-side — shown only when no task is open yet.
function CompletePanel({
    vehicle,
    schedule,
    linkedTask,
    canExpense,
    providers,
    t,
}: {
    vehicle: VehicleHeader;
    schedule: MaintenanceSchedule;
    linkedTask: MaintenanceLinkedTask | null;
    canExpense: boolean;
    providers: ProviderOption[];
    t: TFn;
}) {
    const [panel, setPanel] = useState<'none' | 'complete' | 'expense'>('none');
    const [processing, setProcessing] = useState(false);
    const [form, setForm] = useState<{
        performed_at: string;
        mileage: string;
        cost: string;
        provider: string;
        service_provider_id: number | null;
        notes: string;
    }>({
        performed_at: todayIso(),
        mileage: String(vehicle.currentMileage),
        cost: '',
        provider: '',
        service_provider_id: null,
        notes: '',
    });
    const [expense, setExpense] = useState({ amount: '', note: '', spent_at: todayIso() });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const amountRef = useRef<HTMLInputElement>(null);

    // Re-seed each sheet when it opens (they stay mounted): completion defaults the date to today and the
    // mileage to the car's current odometer; the expense sheet starts blank.
    useEffect(() => {
        if (panel === 'complete') {
            setForm({
                performed_at: todayIso(),
                mileage: String(vehicle.currentMileage),
                cost: '',
                provider: '',
                service_provider_id: null,
                notes: '',
            });
            setErrors({});
        } else if (panel === 'expense') {
            setExpense({ amount: '', note: '', spent_at: todayIso() });
            setErrors({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [panel]);

    const set = (k: keyof typeof form, v: string) => setForm((p) => ({ ...p, [k]: v }));
    const setExp = (k: keyof typeof expense, v: string) => setExpense((p) => ({ ...p, [k]: v }));
    const close = () => {
        setPanel('none');
        setErrors({});
    };

    const submit = () => {
        setProcessing(true);
        router.post(route('vehicles.maintenance.complete', [vehicle.id, schedule.id]), form, {
            preserveScroll: true,
            onSuccess: close,
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    const submitExpense = () => {
        setProcessing(true);
        router.post(route('vehicles.maintenance.expense', [vehicle.id, schedule.id]), expense, {
            preserveScroll: true,
            onSuccess: () => {
                setExpense({ amount: '', note: '', spent_at: todayIso() });
                close();
            },
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    // The manual "Create task" action: idempotent server-side, so a second click while one is open no-ops.
    const createTask = () => {
        setProcessing(true);
        router.post(
            route('vehicles.maintenance.task', [vehicle.id, schedule.id]),
            {},
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    };

    return (
        <>
            <div className="flex flex-wrap gap-2">
                <Button type="button" onClick={() => setPanel('complete')}>
                    <CheckCircle2 className="size-4" /> {t('vehicles.mark_completed')}
                </Button>
                {canExpense && (
                    <Button type="button" variant="outline" onClick={() => setPanel('expense')}>
                        <Receipt className="size-4" /> {t('vehicles.add_expense')}
                    </Button>
                )}
                {/* Only offer "Create task" when the schedule has no open one — otherwise the banner links to it. */}
                {!linkedTask && (
                    <Button type="button" variant="outline" onClick={createTask} disabled={processing}>
                        <Plus className="size-4" /> {t('vehicles.create_task')}
                    </Button>
                )}
            </div>

            {/* Record a completion (records the service, closes any linked task, logs cost to Spending). */}
            <ResponsiveModal
                open={panel === 'complete'}
                onOpenChange={(v) => !v && close()}
                title={t('vehicles.record_completion')}
                className="sm:max-w-lg"
                footer={
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={processing || form.mileage.trim() === '' || form.performed_at === ''}
                        className="w-full"
                    >
                        <CheckCircle2 className="size-4" /> {t('vehicles.save_completion')}
                    </Button>
                }
            >
                <div className="flex flex-col gap-4 pb-1">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label={t('vehicles.completed_date')} error={errors.performed_at}>
                            <Input
                                type="date"
                                value={form.performed_at}
                                onChange={(e) => set('performed_at', e.target.value)}
                                className="text-base"
                            />
                        </FormField>
                        <FormField label={t('vehicles.completed_mileage')} error={errors.mileage}>
                            <Input value={form.mileage} onChange={(e) => set('mileage', e.target.value)} inputMode="numeric" className="text-base" />
                        </FormField>
                        <FormField label={t('vehicles.completed_cost')} error={errors.cost}>
                            <Input
                                value={form.cost}
                                onChange={(e) => set('cost', e.target.value)}
                                inputMode="decimal"
                                placeholder="0.00"
                                className="text-base"
                            />
                        </FormField>
                        <ProviderPicker
                            providers={providers}
                            value={{ serviceProviderId: form.service_provider_id, provider: form.provider }}
                            onChange={(v) => setForm((p) => ({ ...p, service_provider_id: v.serviceProviderId, provider: v.provider }))}
                            label={t('vehicles.completed_provider')}
                            t={t}
                            error={errors.provider ?? errors.service_provider_id}
                        />
                    </div>
                    <FormField label={t('vehicles.notes_label')} error={errors.notes}>
                        <textarea
                            value={form.notes}
                            onChange={(e) => set('notes', e.target.value)}
                            rows={2}
                            className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                        />
                    </FormField>
                    {/* A cost here is mirrored into Spending, linked to this car. */}
                    <p className="text-muted-foreground text-xs">{t('vehicles.cost_to_spending_hint')}</p>
                </div>
            </ResponsiveModal>

            {/* Add an ad-hoc expense for this service, mirrored into Spending linked to the car. */}
            <ResponsiveModal
                open={panel === 'expense'}
                onOpenChange={(v) => !v && close()}
                title={t('vehicles.add_expense')}
                autoFocusRef={amountRef}
                className="sm:max-w-lg"
                footer={
                    <Button type="button" onClick={submitExpense} disabled={processing || expense.amount.trim() === ''} className="w-full">
                        <Receipt className="size-4" /> {t('vehicles.save_expense')}
                    </Button>
                }
            >
                <div className="flex flex-col gap-4 pb-1">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label={t('vehicles.expense_amount')} error={errors.amount}>
                            <Input
                                ref={amountRef}
                                value={expense.amount}
                                onChange={(e) => setExp('amount', e.target.value)}
                                inputMode="decimal"
                                placeholder="0.00"
                                className="text-base"
                            />
                        </FormField>
                        <FormField label={t('vehicles.completed_date')} error={errors.spent_at}>
                            <Input type="date" value={expense.spent_at} onChange={(e) => setExp('spent_at', e.target.value)} className="text-base" />
                        </FormField>
                    </div>
                    <FormField label={t('vehicles.expense_note')} error={errors.note}>
                        <Input
                            value={expense.note}
                            onChange={(e) => setExp('note', e.target.value)}
                            placeholder={schedule.name}
                            className="text-base"
                        />
                    </FormField>
                </div>
            </ResponsiveModal>
        </>
    );
}

function MaintenanceHistoryList({ history, t }: { history: MaintenanceHistoryEntry[]; t: TFn }) {
    const carMoney = useCarMoney();
    return (
        <section className="flex flex-col gap-2">
            <h3 className="font-heading text-primary text-lg">{t('vehicles.maint_history')}</h3>
            {history.length === 0 ? (
                <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-6 text-center text-sm">
                    {t('vehicles.maint_history_empty')}
                </p>
            ) : (
                <ul className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-2xl border">
                    {history.map((h) => (
                        <li key={h.id} className="bg-surface-card flex flex-col gap-1 px-4 py-3">
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-primary text-sm font-medium">{h.performedAt}</span>
                                <span className="text-muted-foreground text-sm tabular-nums">{mileageText(t, h.mileage)}</span>
                            </div>
                            <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">
                                {h.cost != null && <span className="tabular-nums">{carMoney(h.cost)}</span>}
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
