import { Head, Link, router } from '@inertiajs/react';
import { Camera, Car, ChevronRight, FileText, Gauge, Pencil, StickyNote, Wrench } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { compressImage, makeThumbnail } from '@/lib/compress-image';
import { useT, type TFn } from '@/lib/i18n';
import { relativeTime } from '@/lib/notifications';
import {
    fmtMileage,
    fuelLabel,
    MILEAGE_PRESETS,
    mileageText,
    STATE_META,
    stateLabel,
    transmissionLabel,
    vehicleSubtitle,
    vehicleTitle,
    type MileageEntry,
    type Vehicle,
} from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

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
    sections: SectionCounts;
};

export default function VehicleOverview({ vehicle, owner, primaryDriver, history, sections }: Props) {
    const t = useT();
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
                    <div>
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

                <MileagePanel vehicle={vehicle} t={t} />

                {/* Maintenance is live (P2); Repairs / Documents / Notes are live from P4 — real tiles that
                    link into each tab and surface the car's counts (and an expiring-document exception). Fuel
                    stays a stub until V1.1. */}
                <MaintenanceSummary vehicle={vehicle} t={t} />
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
                    <StubSection title={t('vehicles.fuel')} desc={t('vehicles.fuel_stub')} />
                </div>

                <MileageHistory history={history} t={t} />

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

// Big current mileage + the quick-update controls: presets that add to the odometer, or a manual absolute
// entry. A manual reading below the current odometer is blocked server-side; on that error we offer a
// one-tap "save as correction" (the explicit override the server asks for).
function MileagePanel({ vehicle, t }: { vehicle: Vehicle; t: TFn }) {
    const [manual, setManual] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = (mileage: number, correction = false) => {
        setProcessing(true);
        router.post(
            route('vehicles.mileage', vehicle.id),
            { mileage, correction },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setManual('');
                    setError(null);
                },
                onError: (errs) => setError(errs.mileage ?? null),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const addPreset = (delta: number) => submit(vehicle.currentMileage + delta);
    const saveManual = (correction = false) => {
        const value = parseInt(manual, 10);
        if (Number.isFinite(value)) submit(value, correction);
    };

    // The server flags a backwards manual reading on the `mileage` field — surface the correction escape.
    const backwards = !!error;

    return (
        <section className="bg-surface-card flex flex-col gap-4 rounded-2xl border p-5">
            <div className="flex items-center gap-3">
                <span className="bg-accent-subtle text-primary flex size-11 items-center justify-center rounded-xl">
                    <Gauge className="size-6" />
                </span>
                <div>
                    <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('vehicles.current_mileage')}</span>
                    <div className="text-primary text-3xl font-semibold tabular-nums">{mileageText(t, vehicle.currentMileage)}</div>
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
                        +{fmtMileage(delta)}
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
                    <button
                        type="button"
                        onClick={() => saveManual(true)}
                        className="text-destructive self-start text-xs font-medium underline"
                    >
                        {t('vehicles.save_as_correction')}
                    </button>
                )}
            </div>
        </section>
    );
}

// The Maintenance summary tile on the Overview: the derived status dot, how many items need attention,
// and the soonest service by distance — a link into the Maintenance tab. Real from P2 onward.
function MaintenanceSummary({ vehicle, t }: { vehicle: Vehicle; t: TFn }) {
    const color = STATE_META[vehicle.state].color;
    const subtitle =
        vehicle.attention > 0
            ? t('vehicles.attention_count', { count: vehicle.attention })
            : vehicle.nextServiceKm != null && vehicle.nextServiceKm >= 0
              ? t('vehicles.next_service_in', { value: fmtMileage(vehicle.nextServiceKm) })
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

function MileageHistory({ history, t }: { history: MileageEntry[]; t: TFn }) {
    return (
        <section className="flex flex-col gap-2">
            <h2 className="font-heading text-primary text-lg">{t('vehicles.mileage_history')}</h2>
            {history.length === 0 ? (
                <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-6 text-center text-sm">{t('vehicles.mileage_history_empty')}</p>
            ) : (
                <ul className="bg-border grid gap-px overflow-hidden rounded-2xl border">
                    {history.map((h) => (
                        <li key={h.id} className="bg-surface-card flex items-center gap-3 px-4 py-2.5 text-sm">
                            <span className="text-muted-foreground w-24 shrink-0 text-xs">{h.recordedAt ? relativeTime(h.recordedAt) : ''}</span>
                            <span className="text-primary flex-1 tabular-nums">{mileageText(t, h.mileage)}</span>
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

// A live section tile (Repairs / Documents / Notes): links into the tab and shows the car's count, with an
// amber tint when something needs attention (an expiring document).
function SectionTile({
    href,
    icon,
    title,
    subtitle,
    alert = false,
}: {
    href: string;
    icon: ReactNode;
    title: string;
    subtitle: string;
    alert?: boolean;
}): ReactNode {
    return (
        <Link
            href={href}
            className="bg-surface-card group flex items-center gap-3 rounded-2xl border px-4 py-3.5 transition-colors hover:border-[var(--primary)]"
        >
            <span
                className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${
                    alert ? 'bg-[var(--warning)]/15 text-[var(--warning)]' : 'bg-accent-subtle text-primary'
                }`}
            >
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

// A placeholder for a later-phase section (Fuel). Reads as "coming", not broken — a muted card with the
// section's name and a one-line promise.
function StubSection({ title, desc }: { title: string; desc: string }): ReactNode {
    return (
        <div className="bg-surface/60 flex items-center gap-3 rounded-xl border border-dashed px-4 py-3">
            <span className="text-muted-foreground/60 flex size-8 shrink-0 items-center justify-center">
                <Wrench className="size-4" />
            </span>
            <div className="min-w-0">
                <span className="text-primary block text-sm font-medium">{title}</span>
                <span className="text-muted-foreground block truncate text-xs">{desc}</span>
            </div>
        </div>
    );
}
