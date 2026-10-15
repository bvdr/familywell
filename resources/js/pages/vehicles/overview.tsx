import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Camera,
    Car,
    ChartLine,
    ChevronRight,
    FileText,
    Fuel,
    Gauge,
    History,
    Pencil,
    Receipt,
    StickyNote,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import AppLayout from '@/layouts/app-layout';
import { compressImage, makeThumbnail } from '@/lib/compress-image';
import { useT, type TFn } from '@/lib/i18n';
import { money } from '@/lib/money';
import { relativeTime } from '@/lib/notifications';
import {
    consumptionText,
    costPerKmText,
    distanceText,
    distanceUnitLabel,
    documentTypeLabel,
    fmtDistance,
    fuelLabel,
    MAINT_STATUS_META,
    maintStatusLabel,
    MILEAGE_PRESETS,
    mileageText,
    STATE_META,
    stateLabel,
    transmissionLabel,
    unitToKm,
    vehicleSubtitle,
    vehicleTitle,
    type CompactAnalytics,
    type MileageEntry,
    type NextMaintenance,
    type OverviewNext,
    type OverviewStatus,
    type PartRequest,
    type RecentEvent,
    type UnitSystem,
    type Vehicle,
} from '@/lib/vehicles';
import type { BreadcrumbItem, SharedData } from '@/types';

import { PartsSection } from './parts';
import VehicleNav from './vehicle-nav';

type SectionCounts = {
    repairs: number;
    documents: number;
    documentsExpiring: number;
    notes: number;
};

type Props = {
    vehicle: Vehicle;
    owner: string | null;
    primaryDriver: string | null;
    history: MileageEntry[];
    unitSystem: UnitSystem;
    status: OverviewStatus;
    next: OverviewNext;
    recent: RecentEvent[];
    sections: SectionCounts;
    parts: PartRequest[];
    canShopping: boolean;
    analytics: CompactAnalytics;
};

export default function VehicleOverview({ vehicle, owner, primaryDriver, history, unitSystem, status, next, recent, sections, parts, canShopping, analytics }: Props) {
    const t = useT();
    const { modules } = usePage<SharedData & { modules?: { enabled?: string[] } }>().props;
    const enabled = modules?.enabled ?? [];
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={vehicleTitle(vehicle)} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <PhotoHero vehicle={vehicle} t={t} />

                <header className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="font-heading text-primary text-2xl">{vehicleTitle(vehicle)}</h1>
                        <p className="text-muted-foreground text-sm">{vehicleSubtitle(vehicle) || t('vehicles.no_details')}</p>
                    </div>
                    <Link
                        href={`/m/vehicles/${vehicle.id}/edit`}
                        className="border-border text-primary hover:bg-surface flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                    >
                        <Pencil className="size-4" /> {t('vehicles.edit')}
                    </Link>
                </header>

                <VehicleNav vehicleId={vehicle.id} active="overview" />

                {/* The combined Good / Attention roll-up: maintenance due-or-overdue + expiring documents. */}
                <StatusBanner status={status} t={t} />

                <QuickActions vehicle={vehicle} enabled={enabled} t={t} />

                <MileagePanel vehicle={vehicle} unitSystem={unitSystem} t={t} />

                {/* Next up: the soonest maintenance and the soonest document/obligation expiry. */}
                <NextUp vehicle={vehicle} next={next} unitSystem={unitSystem} t={t} />

                <MaintenanceSummary vehicle={vehicle} unitSystem={unitSystem} t={t} />

                {/* V1.1 P3: the compact running-cost headline (YTD spend, cost per km, avg consumption),
                    linking through to the full Costs tab. Hidden until there's anything worth showing. */}
                <RunningCosts vehicle={vehicle} analytics={analytics} unitSystem={unitSystem} t={t} />

                <div className="grid gap-3 sm:grid-cols-2">
                    <SectionTile
                        href={`/m/vehicles/${vehicle.id}/repairs`}
                        icon={<Wrench className="size-5" />}
                        title={t('vehicles.repairs')}
                        subtitle={sections.repairs > 0 ? t('vehicles.repairs_count', { count: sections.repairs }) : t('vehicles.repairs_none')}
                    />
                    <SectionTile
                        href={`/m/vehicles/${vehicle.id}/documents`}
                        icon={<FileText className="size-5" />}
                        title={t('vehicles.documents')}
                        subtitle={documentsSubtitle(t, sections)}
                        alert={sections.documentsExpiring > 0}
                    />
                    <SectionTile
                        href={`/m/vehicles/${vehicle.id}/notes`}
                        icon={<StickyNote className="size-5" />}
                        title={t('vehicles.notes_section')}
                        subtitle={sections.notes > 0 ? t('vehicles.notes_count', { count: sections.notes }) : t('vehicles.notes_none')}
                    />
                    <SectionTile
                        href={`/m/vehicles/${vehicle.id}/timeline`}
                        icon={<History className="size-5" />}
                        title={t('vehicles.timeline')}
                        subtitle={t('vehicles.timeline_sub')}
                    />
                </div>

                {/* V1.1: parts to buy for this car (on the family shopping list), with a quick "add part". */}
                <PartsSection vehicleId={vehicle.id} parts={parts} canShopping={canShopping} t={t} />

                {/* Recent maintenance/repair events with cost. Hidden until there's a service story to tell. */}
                <RecentEvents recent={recent} unitSystem={unitSystem} t={t} />

                <MileageHistory history={history} unitSystem={unitSystem} t={t} />

                <DetailsCard vehicle={vehicle} owner={owner} primaryDriver={primaryDriver} t={t} />
            </div>
        </AppLayout>
    );
}

// The cover photo: full image when present, a tap-to-add tile otherwise. Uploads a compressed full image
// plus a small thumbnail (cards read the thumb), same pattern as project covers.
function PhotoHero({ vehicle, t }: { vehicle: Vehicle; t: TFn }) {
    const input = useRef<HTMLInputElement>(null);
    const upload = async (file: File) => {
        const img = await compressImage(file, 1_200_000);
        const thumb = await makeThumbnail(file, 640);
        router.post(
            route('vehicles.photo.upload', vehicle.id),
            { photo: img, photo_thumb: thumb ?? undefined },
            { forceFormData: true, preserveScroll: true },
        );
    };

    return (
        <div className="bg-surface relative aspect-[16/9] w-full overflow-hidden rounded-2xl border">
            {vehicle.photoUrl ? (
                <img src={vehicle.photoUrl} alt="" className="absolute inset-0 h-full w-full object-cover" />
            ) : (
                <button
                    type="button"
                    onClick={() => input.current?.click()}
                    className="text-muted-foreground hover:text-primary flex h-full w-full flex-col items-center justify-center gap-2"
                >
                    <Car className="size-12 opacity-40" />
                    <span className="flex items-center gap-1.5 text-sm font-medium">
                        <Camera className="size-4" /> {t('vehicles.add_photo')}
                    </span>
                </button>
            )}
            {vehicle.photoUrl && (
                <button
                    type="button"
                    onClick={() => input.current?.click()}
                    aria-label={t('vehicles.change_photo')}
                    className="bg-surface-card/90 text-primary absolute right-3 bottom-3 flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium shadow-sm"
                >
                    <Camera className="size-3.5" /> {t('vehicles.change_photo')}
                </button>
            )}
            <input
                ref={input}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) upload(f);
                }}
            />
        </div>
    );
}

// The one-line overall state: a calm "All good" when nothing is live, or an amber attention line naming
// what needs a look (N maintenance items, M expiring documents).
function StatusBanner({ status, t }: { status: OverviewStatus; t: TFn }) {
    const attention = status.overall === 'attention';
    const color = attention ? 'var(--warning)' : 'var(--success)';

    const parts: string[] = [];
    if (status.maintenanceAttention > 0) parts.push(t('vehicles.attention_maintenance', { count: status.maintenanceAttention }));
    if (status.documentsExpiring > 0) parts.push(t('vehicles.attention_documents', { count: status.documentsExpiring }));

    return (
        <div
            className="flex items-center gap-2.5 rounded-2xl border px-4 py-3"
            style={{ background: `color-mix(in srgb, ${color} 8%, transparent)`, borderColor: `color-mix(in srgb, ${color} 30%, transparent)` }}
        >
            <span className="size-2.5 shrink-0 rounded-full" style={{ background: color }} />
            <span className="text-primary text-sm font-medium">{attention ? t('vehicles.status_attention') : t('vehicles.status_good')}</span>
            {attention && parts.length > 0 && <span className="text-muted-foreground truncate text-sm">· {parts.join(' · ')}</span>}
        </div>
    );
}

// The quick-action row (spec 15): update mileage, add expense, add maintenance, add document, add note.
// Each is either a link into the relevant tab or (add-expense) a small inline modal. Module-gated where a
// destination lives in another module (Spending / Notes).
function QuickActions({ vehicle, enabled, t }: { vehicle: Vehicle; enabled: string[]; t: TFn }) {
    const [expense, setExpense] = useState(false);

    return (
        <>
            <div className="flex flex-wrap gap-2">
                <ActionButton icon={Gauge} label={t('vehicles.qa_mileage')} onClick={() => document.getElementById('mileage')?.scrollIntoView({ behavior: 'smooth', block: 'center' })} />
                {enabled.includes('spending') && <ActionButton icon={Receipt} label={t('vehicles.qa_expense')} onClick={() => setExpense(true)} />}
                <ActionLink icon={Wrench} label={t('vehicles.qa_maintenance')} href={`/m/vehicles/${vehicle.id}/maintenance/new`} />
                <ActionLink icon={FileText} label={t('vehicles.qa_document')} href={`/m/vehicles/${vehicle.id}/documents`} />
                {enabled.includes('notes') && <ActionLink icon={StickyNote} label={t('vehicles.qa_note')} href={`/m/vehicles/${vehicle.id}/notes`} />}
            </div>
            <ExpenseModal vehicle={vehicle} open={expense} onOpenChange={setExpense} t={t} />
        </>
    );
}

function ActionButton({ icon: Icon, label, onClick }: { icon: LucideIcon; label: string; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="bg-surface-card text-primary hover:border-accent inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
        >
            <Icon className="size-4" /> {label}
        </button>
    );
}

function ActionLink({ icon: Icon, label, href }: { icon: LucideIcon; label: string; href: string }) {
    return (
        <Link
            href={href}
            className="bg-surface-card text-primary hover:border-accent inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
        >
            <Icon className="size-4" /> {label}
        </Link>
    );
}

// A tiny modal for the vehicle-level ad-hoc expense (amount + note + date), posting to the P5 expense
// endpoint which mirrors it into Spending linked to the car. Uses the app's shared ResponsiveModal so it
// looks and behaves like every other add/edit sheet (mobile drawer + desktop dialog).
function ExpenseModal({ vehicle, open, onOpenChange, t }: { vehicle: Vehicle; open: boolean; onOpenChange: (v: boolean) => void; t: TFn }) {
    const [amount, setAmount] = useState('');
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const amountRef = useRef<HTMLInputElement>(null);

    // Reset the fields each time the sheet opens (it stays mounted for the open/close animation).
    useEffect(() => {
        if (open) {
            setAmount('');
            setNote('');
        }
    }, [open]);

    const save = () => {
        setProcessing(true);
        router.post(
            route('vehicles.expense', vehicle.id),
            { amount, note },
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
            title={t('vehicles.qa_expense')}
            description={t('vehicles.expense_car_hint')}
            autoFocusRef={amountRef}
            footer={
                <Button onClick={save} disabled={processing || amount.trim() === ''} className="w-full">
                    {t('vehicles.save_expense')}
                </Button>
            }
        >
            <div className="flex flex-col gap-3 pb-1">
                <div>
                    <label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.expense_amount')}</label>
                    <Input ref={amountRef} value={amount} onChange={(e) => setAmount(e.target.value)} inputMode="decimal" placeholder="0" className="mt-1 text-base" />
                </div>
                <div>
                    <label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.expense_note')}</label>
                    <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder={vehicleTitle(vehicle)} className="mt-1 text-base" />
                </div>
            </div>
        </ResponsiveModal>
    );
}

// Big current mileage + the quick-update controls, in the family's unit. Presets add whole units (10/50/…),
// a manual field takes an absolute reading — both converted to km before posting (the odometer is stored in
// km). A reading below the current one is blocked server-side; on that error we offer a "save as correction".
function MileagePanel({ vehicle, unitSystem, t }: { vehicle: Vehicle; unitSystem: UnitSystem; t: TFn }) {
    const [manual, setManual] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // Optimistic odometer: show the new reading the instant it's submitted, then reconcile with the server.
    // Cleared on success (the reloaded prop is authoritative) and on error (roll back to the confirmed value,
    // e.g. a rejected backwards reading), so the big number never lies while the request is in flight.
    const [optMileage, setOptMileage] = useState<number | null>(null);
    const shownMileage = optMileage ?? vehicle.currentMileage;

    const submit = (mileageKm: number, correction = false) => {
        setProcessing(true);
        setOptMileage(mileageKm);
        router.post(
            route('vehicles.mileage', vehicle.id),
            { mileage: mileageKm, correction },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setManual('');
                    setError(null);
                    setOptMileage(null);
                },
                onError: (errs) => {
                    setError(errs.mileage ?? null);
                    setOptMileage(null);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    // Presets are whole units in the display system; convert the delta to km before adding to the odometer.
    // Base off the shown (optimistic) reading so rapid taps stack correctly instead of all off the stale value.
    const addPreset = (delta: number) => submit(shownMileage + unitToKm(delta, unitSystem));
    const saveManual = (correction = false) => {
        const value = parseInt(manual, 10);
        if (Number.isFinite(value)) submit(unitToKm(value, unitSystem), correction);
    };

    const backwards = !!error;
    const unitLabel = unitSystem === 'imperial' ? 'mi' : 'km';

    return (
        <section id="mileage" className="bg-surface-card flex flex-col gap-4 rounded-2xl border p-5 scroll-mt-20">
            <div className="flex items-center gap-3">
                <span className="bg-accent-subtle text-primary flex size-11 items-center justify-center rounded-xl">
                    <Gauge className="size-6" />
                </span>
                <div>
                    <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.current_mileage')}</span>
                    <div className="text-primary text-3xl font-semibold tabular-nums">{mileageText(t, shownMileage, unitSystem)}</div>
                </div>
            </div>

            <div className="flex flex-wrap gap-2">
                {MILEAGE_PRESETS.map((delta) => (
                    <button
                        key={delta}
                        type="button"
                        disabled={processing}
                        onClick={() => addPreset(delta)}
                        className="border-border text-primary hover:bg-surface rounded-lg border px-3 py-1.5 text-sm font-medium tabular-nums transition-colors disabled:opacity-50"
                    >
                        +{delta.toLocaleString('en-US')} {unitLabel}
                    </button>
                ))}
            </div>

            <div className="flex flex-col gap-1.5">
                <div className="flex items-end gap-2">
                    <div className="flex-1">
                        <Input
                            value={manual}
                            onChange={(e) => setManual(e.target.value)}
                            inputMode="numeric"
                            placeholder={t('vehicles.manual_mileage_placeholder')}
                            className="text-base"
                        />
                    </div>
                    <Button type="button" onClick={() => saveManual(false)} disabled={processing || manual.trim() === ''}>
                        {t('vehicles.update')}
                    </Button>
                </div>
                <InputError message={error ?? undefined} />
                {backwards && (
                    <button type="button" onClick={() => saveManual(true)} className="text-destructive self-start text-xs font-medium underline">
                        {t('vehicles.save_as_correction')}
                    </button>
                )}
            </div>
        </section>
    );
}

// "Next up": the soonest maintenance schedule (with its km/date remaining) and the soonest document to
// expire. Renders nothing when a fresh car has neither.
function NextUp({ vehicle, next, unitSystem, t }: { vehicle: Vehicle; next: OverviewNext; unitSystem: UnitSystem; t: TFn }) {
    if (!next.maintenance && !next.document) return null;

    return (
        <section className="flex flex-col gap-2">
            <h2 className="font-heading text-primary text-lg">{t('vehicles.next_up')}</h2>
            <div className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-2xl border">
                {next.maintenance && <NextMaintenanceRow vehicle={vehicle} m={next.maintenance} unitSystem={unitSystem} t={t} />}
                {next.document && (
                    <Link href={`/m/vehicles/${vehicle.id}/documents`} className="bg-surface-card group flex items-center gap-3 px-4 py-3">
                        <span
                            className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${
                                next.document.expiring ? 'bg-[var(--warning)]/15 text-[var(--warning)]' : 'bg-accent-subtle text-primary'
                            }`}
                        >
                            <FileText className="size-4.5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <span className="text-primary block truncate text-sm font-medium">{next.document.title}</span>
                            <span className="text-muted-foreground block truncate text-xs">
                                {documentTypeLabel(t, next.document.type)}
                                {next.document.expiresOn ? ` · ${t('vehicles.expires_on', { date: next.document.expiresOn })}` : ''}
                            </span>
                        </div>
                        <ChevronRight className="text-muted-foreground size-4 shrink-0 transition-transform group-hover:translate-x-0.5" />
                    </Link>
                )}
            </div>
        </section>
    );
}

function NextMaintenanceRow({ vehicle, m, unitSystem, t }: { vehicle: Vehicle; m: NextMaintenance; unitSystem: UnitSystem; t: TFn }) {
    const color = MAINT_STATUS_META[m.status].color;
    // Build the "how far off" line from whichever trigger is nearer (km shown in the family's unit).
    let remaining: string;
    if (m.neverPerformed) remaining = t('vehicles.never_performed');
    else {
        const km = m.remainingKm;
        const days = m.remainingDays;
        const useKm = km != null && (days == null || km <= days);
        if (useKm && km != null) remaining = km > 0 ? t('vehicles.km_remaining', { value: distanceText(km, unitSystem) }) : km === 0 ? t('vehicles.due_now') : t('vehicles.km_overdue', { value: distanceText(-km, unitSystem) });
        else if (days != null) remaining = days > 0 ? t('vehicles.days_remaining', { value: days }) : days === 0 ? t('vehicles.due_now') : t('vehicles.days_overdue', { value: -days });
        else remaining = t('vehicles.due_now');
    }

    return (
        <Link href={`/m/vehicles/${vehicle.id}/maintenance/${m.id}`} className="bg-surface-card group flex items-center gap-3 px-4 py-3">
            <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                <Wrench className="size-4.5" />
            </span>
            <div className="min-w-0 flex-1">
                <span className="text-primary block truncate text-sm font-medium">{m.name}</span>
                <span className="flex items-center gap-1.5 text-xs" style={{ color }}>
                    <span className="size-1.5 rounded-full" style={{ background: color }} />
                    {maintStatusLabel(t, m.status)} · <span className="text-muted-foreground tabular-nums">{remaining}</span>
                </span>
            </div>
            <ChevronRight className="text-muted-foreground size-4 shrink-0 transition-transform group-hover:translate-x-0.5" />
        </Link>
    );
}

// The Maintenance summary tile: the derived status dot, attention count, and soonest service by distance.
function MaintenanceSummary({ vehicle, unitSystem, t }: { vehicle: Vehicle; unitSystem: UnitSystem; t: TFn }) {
    const color = STATE_META[vehicle.state].color;
    const subtitle =
        vehicle.attention > 0
            ? t('vehicles.attention_count', { count: vehicle.attention })
            : vehicle.nextServiceKm != null && vehicle.nextServiceKm >= 0
              ? t('vehicles.next_service_in', { value: distanceText(vehicle.nextServiceKm, unitSystem) })
              : t('vehicles.maint_all_good');

    return (
        <Link
            href={`/m/vehicles/${vehicle.id}/maintenance`}
            className="bg-surface-card group flex items-center gap-3 rounded-2xl border px-4 py-3.5 transition-colors hover:border-[var(--primary)]"
        >
            <span className="bg-accent-subtle text-primary flex size-10 shrink-0 items-center justify-center rounded-xl">
                <Wrench className="size-5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="text-primary font-medium">{t('vehicles.maintenance')}</span>
                    <span className="flex items-center gap-1 text-xs font-medium" style={{ color }}>
                        <span className="size-1.5 rounded-full" style={{ background: color }} />
                        {stateLabel(t, vehicle.state)}
                    </span>
                </div>
                <span className="text-muted-foreground block truncate text-sm">{subtitle}</span>
            </div>
            <ChevronRight className="text-muted-foreground size-4 shrink-0 transition-transform group-hover:translate-x-0.5" />
        </Link>
    );
}

// V1.1 P3: the compact "running costs" headline — a few derived stats (year-to-date spend, operating cost
// per km, average fuel consumption) that link through to the full Costs tab. Comes from VehicleAnalytics, so
// it never disagrees with that screen. Hidden until there's data (no spends and no consumption yet).
function RunningCosts({ vehicle, analytics, unitSystem, t }: { vehicle: Vehicle; analytics: CompactAnalytics; unitSystem: UnitSystem; t: TFn }) {
    if (!analytics.hasData) return null;

    const stats: { icon: LucideIcon; label: string; value: string }[] = [
        { icon: Receipt, label: t('vehicles.cost_ytd'), value: money(analytics.ytdTotal, analytics.currency) },
        { icon: Gauge, label: t('vehicles.operating_cost', { unit: distanceUnitLabel(unitSystem) }), value: costPerKmText(analytics.ytdCostPerKm, analytics.currency, unitSystem) },
        { icon: Fuel, label: t('vehicles.fuel_consumption_avg'), value: consumptionText(analytics.avgConsumption, unitSystem) },
    ];

    return (
        <Link
            href={`/m/vehicles/${vehicle.id}/analytics`}
            className="bg-surface-card group flex flex-col gap-3 rounded-2xl border p-4 transition-colors hover:border-[var(--primary)]"
        >
            <div className="flex items-center justify-between gap-2">
                <span className="text-primary flex items-center gap-2 font-medium">
                    <ChartLine className="size-4.5" /> {t('vehicles.costs')}
                </span>
                <ChevronRight className="text-muted-foreground size-4 shrink-0 transition-transform group-hover:translate-x-0.5" />
            </div>
            <div className="bg-border grid grid-cols-3 gap-px overflow-hidden rounded-xl border">
                {stats.map((s) => (
                    <div key={s.label} className="bg-surface-card flex flex-col gap-0.5 p-3">
                        <span className="text-muted-foreground text-[11px] font-semibold tracking-widest uppercase">{s.label}</span>
                        <span className="text-primary text-base font-semibold tabular-nums">{s.value}</span>
                    </div>
                ))}
            </div>
        </Link>
    );
}

// Recent maintenance/repair events, each with when it happened, the odometer and the cost. Hidden when the
// car has no service story yet.
function RecentEvents({ recent, unitSystem, t }: { recent: RecentEvent[]; unitSystem: UnitSystem; t: TFn }) {
    if (recent.length === 0) return null;

    return (
        <section className="flex flex-col gap-2">
            <h2 className="font-heading text-primary text-lg">{t('vehicles.recent')}</h2>
            <ul className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-2xl border">
                {recent.map((e) => (
                    <li key={e.id} className="bg-surface-card flex items-center gap-3 px-4 py-2.5">
                        <span className="bg-accent-subtle text-primary flex size-8 shrink-0 items-center justify-center rounded-lg">
                            {e.type === 'repair' ? <Wrench className="size-4" /> : <Gauge className="size-4" />}
                        </span>
                        <div className="min-w-0 flex-1">
                            <span className="text-primary block truncate text-sm">{e.title || t(`vehicles.recent_${e.type}`)}</span>
                            <span className="text-muted-foreground block truncate text-xs">
                                {e.date ? relativeTime(e.date) : ''}
                                {e.mileage != null ? ` · ${fmtDistance(e.mileage, unitSystem)} ${unitSystem === 'imperial' ? 'mi' : 'km'}` : ''}
                            </span>
                        </div>
                        {e.cost != null && (
                            <span className="text-primary shrink-0 text-sm tabular-nums">{t('vehicles.cost_value', { value: e.cost.toLocaleString('en-US') })}</span>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function MileageHistory({ history, unitSystem, t }: { history: MileageEntry[]; unitSystem: UnitSystem; t: TFn }) {
    return (
        <section className="flex flex-col gap-2">
            <h2 className="font-heading text-primary text-lg">{t('vehicles.mileage_history')}</h2>
            {history.length === 0 ? (
                <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-6 text-center text-sm">{t('vehicles.mileage_history_empty')}</p>
            ) : (
                <ul className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-2xl border">
                    {history.map((h) => (
                        <li key={h.id} className="bg-surface-card flex items-center gap-3 px-4 py-2.5 text-sm">
                            <span className="text-muted-foreground w-24 shrink-0 text-xs">{h.recordedAt ? relativeTime(h.recordedAt) : ''}</span>
                            <span className="text-primary flex-1 tabular-nums">{mileageText(t, h.mileage, unitSystem)}</span>
                            {h.recorder && <span className="text-muted-foreground shrink-0 text-xs">{h.recorder}</span>}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

// The at-a-glance details, only the rows that are filled in.
function DetailsCard({ vehicle, owner, primaryDriver, t }: { vehicle: Vehicle; owner: string | null; primaryDriver: string | null; t: TFn }) {
    const rows: { label: string; value: string | null }[] = [
        { label: t('vehicles.owner_label'), value: owner },
        { label: t('vehicles.driver_label'), value: primaryDriver },
        { label: t('vehicles.fuel_label'), value: vehicle.fuelType ? fuelLabel(t, vehicle.fuelType) : null },
        { label: t('vehicles.transmission_label'), value: vehicle.transmission ? transmissionLabel(t, vehicle.transmission) : null },
        { label: t('vehicles.color_label'), value: vehicle.color },
        { label: t('vehicles.vin_label'), value: vehicle.vin },
        { label: t('vehicles.registration_label'), value: vehicle.registrationNumber },
        { label: t('vehicles.plate_label'), value: vehicle.licensePlate },
        { label: t('vehicles.purchase_date_label'), value: vehicle.purchaseDate },
        { label: t('vehicles.insurance_label'), value: vehicle.insuranceCompany },
        { label: t('vehicles.road_tax_label'), value: vehicle.roadTaxStatus },
        { label: t('vehicles.inspection_label'), value: vehicle.inspectionStatus },
    ].filter((r) => r.value);

    if (rows.length === 0 && !vehicle.notes) return null;

    return (
        <section className="flex flex-col gap-2">
            <h2 className="font-heading text-primary text-lg">{t('vehicles.details')}</h2>
            <dl className="bg-surface-card grid grid-cols-1 gap-px overflow-hidden rounded-2xl border sm:grid-cols-2">
                {rows.map((r) => (
                    <div key={r.label} className="flex items-baseline justify-between gap-3 px-4 py-2.5">
                        <dt className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{r.label}</dt>
                        <dd className="text-primary min-w-0 truncate text-right text-sm">{r.value}</dd>
                    </div>
                ))}
            </dl>
            {vehicle.notes && <p className="text-text-secondary bg-surface-card rounded-xl border px-4 py-3 text-sm whitespace-pre-wrap">{vehicle.notes}</p>}
        </section>
    );
}

// A live section tile (Repairs / Documents / Notes / Timeline): links into the tab and shows the car's
// count, with an amber tint when something needs attention (an expiring document).
function SectionTile({ href, icon, title, subtitle, alert = false }: { href: string; icon: ReactNode; title: string; subtitle: string; alert?: boolean }): ReactNode {
    return (
        <Link href={href} className="bg-surface-card group flex items-center gap-3 rounded-2xl border px-4 py-3.5 transition-colors hover:border-[var(--primary)]">
            <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${alert ? 'bg-[var(--warning)]/15 text-[var(--warning)]' : 'bg-accent-subtle text-primary'}`}>
                {icon}
            </span>
            <div className="min-w-0 flex-1">
                <span className="text-primary block font-medium">{title}</span>
                <span className="text-muted-foreground block truncate text-sm">{subtitle}</span>
            </div>
            <ChevronRight className="text-muted-foreground size-4 shrink-0 transition-transform group-hover:translate-x-0.5" />
        </Link>
    );
}

// "3 documents · 1 expiring" — the documents tile subtitle, surfacing the exception when there is one.
function documentsSubtitle(t: TFn, s: SectionCounts): string {
    if (s.documents === 0) return t('vehicles.documents_none');
    const parts = [t('vehicles.documents_count', { count: s.documents })];
    if (s.documentsExpiring > 0) parts.push(t('vehicles.documents_expiring_count', { count: s.documentsExpiring }));
    return parts.join(' · ');
}
