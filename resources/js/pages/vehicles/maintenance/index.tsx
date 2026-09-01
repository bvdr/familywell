import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Plus, Wrench } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { useT, type TFn } from '@/lib/i18n';
import {
    intervalText,
    MAINT_STATUS_META,
    maintStatusLabel,
    mileageText,
    remainingText,
    templateLabel,
    vehicleTitle,
    type MaintenanceSchedule,
    type VehicleHeader,
} from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

import VehicleNav from '../vehicle-nav';

type Props = { vehicle: VehicleHeader; schedules: MaintenanceSchedule[] };

export default function MaintenanceIndex({ vehicle, schedules }: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
        { title: t('vehicles.maintenance'), href: `/m/vehicles/${vehicle.id}/maintenance` },
    ];
    const addHref = `/m/vehicles/${vehicle.id}/maintenance/new`;
    // The mobile nav "+" adds a schedule from this screen.
    useAddAction(() => router.visit(addHref));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${vehicleTitle(vehicle)} · ${t('vehicles.maintenance')}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex flex-col gap-1">
                    <h1 className="font-heading text-primary text-2xl">{vehicleTitle(vehicle)}</h1>
                    <p className="text-muted-foreground text-sm tabular-nums">{mileageText(t, vehicle.currentMileage)}</p>
                </header>

                <VehicleNav vehicleId={vehicle.id} active="maintenance" />

                <div className="flex items-center justify-between gap-3">
                    <h2 className="font-heading text-primary text-lg">{t('vehicles.maintenance_schedules')}</h2>
                    <Link
                        href={addHref}
                        className="bg-accent-strong text-accent-strong-foreground hover:bg-accent-strong/90 flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                    >
                        <Plus className="size-4" /> {t('vehicles.add_maintenance')}
                    </Link>
                </div>

                {schedules.length === 0 ? (
                    <EmptyState t={t} addHref={addHref} />
                ) : (
                    <ul className="bg-border grid gap-px overflow-hidden rounded-2xl border">
                        {schedules.map((s) => (
                            <ScheduleRow key={s.id} vehicleId={vehicle.id} schedule={s} t={t} />
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}

function ScheduleRow({ vehicleId, schedule, t }: { vehicleId: number; schedule: MaintenanceSchedule; t: TFn }) {
    const color = MAINT_STATUS_META[schedule.status].color;
    return (
        <li>
            <Link
                href={`/m/vehicles/${vehicleId}/maintenance/${schedule.id}`}
                className="bg-surface-card hover:bg-surface group flex items-center gap-3 px-4 py-3 transition-colors"
            >
                <span className="mt-0.5 size-2.5 shrink-0 rounded-full" style={{ background: color }} aria-hidden />
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <span className="text-primary truncate font-medium">{schedule.name || templateLabel(t, schedule.type)}</span>
                        {!schedule.active && (
                            <span className="text-muted-foreground bg-surface rounded border px-1.5 py-0.5 text-[10px] font-medium tracking-wide uppercase">
                                {t('vehicles.inactive')}
                            </span>
                        )}
                    </div>
                    <span className="text-muted-foreground block truncate text-xs">
                        {intervalText(t, schedule.intervalKm, schedule.intervalMonths)}
                    </span>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-0.5 text-right">
                    <span className="text-xs font-medium tabular-nums" style={{ color }}>
                        {maintStatusLabel(t, schedule.status)}
                    </span>
                    <span className="text-muted-foreground text-xs tabular-nums">{remainingText(t, schedule)}</span>
                </div>
                <ChevronRight className="text-muted-foreground ml-1 size-4 shrink-0 transition-transform group-hover:translate-x-0.5" />
            </Link>
        </li>
    );
}

function EmptyState({ t, addHref }: { t: TFn; addHref: string }) {
    return (
        <div className="bg-surface-card flex flex-col items-center gap-3 rounded-2xl border px-6 py-14 text-center">
            <span className="bg-accent-subtle text-primary flex size-14 items-center justify-center rounded-2xl">
                <Wrench className="size-7" />
            </span>
            <h2 className="font-heading text-primary text-xl">{t('vehicles.maint_empty_title')}</h2>
            <p className="text-muted-foreground max-w-sm text-sm">{t('vehicles.maint_empty_body')}</p>
            <Link
                href={addHref}
                className="bg-accent-strong text-accent-strong-foreground hover:bg-accent-strong/90 mt-1 flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
            >
                <Plus className="size-4" /> {t('vehicles.add_maintenance')}
            </Link>
        </div>
    );
}
