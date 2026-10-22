import { Head, Link, router } from '@inertiajs/react';
import { Car, ChevronRight, Gauge, Plus, Settings, Wrench } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { useT, type TFn } from '@/lib/i18n';
import { mileageText, STATE_META, stateLabel, vehicleSubtitle, vehicleTitle, type UnitSystem, type Vehicle } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

type Props = { vehicles: Vehicle[]; unitSystem: UnitSystem; canManage: boolean };

export default function VehiclesIndex({ vehicles, unitSystem, canManage }: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('vehicles.title'), href: '/m/vehicles' }];
    // The mobile nav "+" adds a car from this screen.
    useAddAction(() => router.visit('/m/vehicles/new'));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('vehicles.title')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                {/* Wraps: on a phone the buttons drop under the title instead of squeezing it into a narrow column
                    (long labels like "Añadir vehículo" left the title two words wide). */}
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-56 flex-1">
                        <h1 className="font-heading text-primary text-2xl">{t('vehicles.my_cars')}</h1>
                        <p className="text-muted-foreground text-sm">{t('vehicles.subtitle')}</p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        {/* Service providers / garages — a family-wide manage screen, open to every member. */}
                        <Link
                            href="/m/vehicles/providers"
                            aria-label={t('vehicles.providers')}
                            className="border-border text-muted-foreground hover:text-primary flex items-center justify-center rounded-lg border p-2 transition-colors"
                        >
                            <Wrench className="size-4" />
                        </Link>
                        {canManage && (
                            <Link
                                href="/m/vehicles/settings"
                                aria-label={t('vehicles.settings')}
                                className="border-border text-muted-foreground hover:text-primary flex items-center justify-center rounded-lg border p-2 transition-colors"
                            >
                                <Settings className="size-4" />
                            </Link>
                        )}
                        <AddVehicleButton t={t} />
                    </div>
                </header>

                {vehicles.length === 0 ? (
                    <EmptyState t={t} />
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {vehicles.map((v) => (
                            <VehicleCard key={v.id} vehicle={v} unitSystem={unitSystem} t={t} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

// One car card: photo (or a fallback tile), make/model, year · engine, current mileage, and the
// placeholder status + next-service line (real once P2 maintenance schedules exist).
function VehicleCard({ vehicle, unitSystem, t }: { vehicle: Vehicle; unitSystem: UnitSystem; t: TFn }) {
    const color = STATE_META[vehicle.state].color;
    return (
        <Link
            href={`/m/vehicles/${vehicle.id}`}
            className="bg-surface-card group flex flex-col overflow-hidden rounded-2xl border transition-colors hover:border-[var(--primary)]"
        >
            <div className="bg-surface relative aspect-[16/9] w-full overflow-hidden">
                {vehicle.photoThumbUrl ? (
                    <img src={vehicle.photoThumbUrl} alt="" className="absolute inset-0 h-full w-full object-cover" />
                ) : (
                    <div className="text-muted-foreground/40 flex h-full w-full items-center justify-center">
                        <Car className="size-12" />
                    </div>
                )}
            </div>
            <div className="flex flex-1 flex-col gap-2 p-4">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <h2 className="font-heading text-primary truncate text-lg">{vehicleTitle(vehicle)}</h2>
                        <p className="text-muted-foreground truncate text-sm">{vehicleSubtitle(vehicle) || t('vehicles.no_details')}</p>
                    </div>
                    <span
                        className="flex shrink-0 items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium"
                        style={{ color, borderColor: color }}
                    >
                        <span className="size-1.5 rounded-full" style={{ background: color }} />
                        {stateLabel(t, vehicle.state)}
                    </span>
                </div>
                <div className="text-text-secondary mt-auto flex items-center gap-1.5 text-sm">
                    <Gauge className="text-muted-foreground size-4" />
                    <span className="tabular-nums">{mileageText(t, vehicle.currentMileage, unitSystem)}</span>
                    <ChevronRight className="text-muted-foreground ml-auto size-4 transition-transform group-hover:translate-x-0.5" />
                </div>
            </div>
        </Link>
    );
}

function AddVehicleButton({ t }: { t: TFn }) {
    return (
        <Link
            href="/m/vehicles/new"
            className="bg-accent-strong text-accent-strong-foreground hover:bg-accent-strong/90 flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
        >
            <Plus className="size-4" /> {t('vehicles.add_vehicle')}
        </Link>
    );
}

function EmptyState({ t }: { t: TFn }) {
    return (
        <div className="bg-surface-card flex flex-col items-center gap-3 rounded-2xl border px-6 py-14 text-center">
            <span className="bg-accent-subtle text-primary flex size-14 items-center justify-center rounded-2xl">
                <Car className="size-7" />
            </span>
            <h2 className="font-heading text-primary text-xl">{t('vehicles.empty_title')}</h2>
            <p className="text-muted-foreground max-w-sm text-sm">{t('vehicles.empty_body')}</p>
            <Link
                href="/m/vehicles/new"
                className="bg-accent-strong text-accent-strong-foreground hover:bg-accent-strong/90 mt-1 flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
            >
                <Plus className="size-4" /> {t('vehicles.add_vehicle')}
            </Link>
        </div>
    );
}
