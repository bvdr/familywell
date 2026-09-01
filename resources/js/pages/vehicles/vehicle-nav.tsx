import { Link } from '@inertiajs/react';
import { CircleDot, FileText, Fuel, Gauge, History, StickyNote, Wrench } from 'lucide-react';

import { useT } from '@/lib/i18n';

// The per-car sub-navigation (spec: Overview / Maintenance / Repairs / Timeline / Fuel / Documents / Notes).
// Overview + Maintenance are live from P2; Repairs / Documents / Notes from P4; Timeline from P5; Fuel from
// V1.1. `active` selects the current tab.
type Tab = 'overview' | 'maintenance' | 'repairs' | 'fuel' | 'tires' | 'documents' | 'notes' | 'timeline';

export default function VehicleNav({ vehicleId, active }: { vehicleId: number; active: Tab }) {
    const t = useT();

    return (
        <nav className="border-border flex flex-wrap items-center gap-1 border-b pb-px text-sm">
            <TabLink href={`/m/vehicles/${vehicleId}`} icon={<Gauge className="size-4" />} label={t('vehicles.tab_overview')} active={active === 'overview'} />
            <TabLink
                href={`/m/vehicles/${vehicleId}/maintenance`}
                icon={<Wrench className="size-4" />}
                label={t('vehicles.tab_maintenance')}
                active={active === 'maintenance'}
            />
            <TabLink
                href={`/m/vehicles/${vehicleId}/repairs`}
                icon={<Wrench className="size-4" />}
                label={t('vehicles.tab_repairs')}
                active={active === 'repairs'}
            />
            <TabLink
                href={`/m/vehicles/${vehicleId}/fuel`}
                icon={<Fuel className="size-4" />}
                label={t('vehicles.tab_fuel')}
                active={active === 'fuel'}
            />
            <TabLink
                href={`/m/vehicles/${vehicleId}/tires`}
                icon={<CircleDot className="size-4" />}
                label={t('vehicles.tab_tires')}
                active={active === 'tires'}
            />
            <TabLink
                href={`/m/vehicles/${vehicleId}/documents`}
                icon={<FileText className="size-4" />}
                label={t('vehicles.tab_documents')}
                active={active === 'documents'}
            />
            <TabLink
                href={`/m/vehicles/${vehicleId}/notes`}
                icon={<StickyNote className="size-4" />}
                label={t('vehicles.tab_notes')}
                active={active === 'notes'}
            />
            <TabLink
                href={`/m/vehicles/${vehicleId}/timeline`}
                icon={<History className="size-4" />}
                label={t('vehicles.tab_timeline')}
                active={active === 'timeline'}
            />
        </nav>
    );
}

function TabLink({ href, icon, label, active }: { href: string; icon: React.ReactNode; label: string; active: boolean }) {
    return (
        <Link
            href={href}
            className={`-mb-px flex items-center gap-1.5 rounded-t-lg border-b-2 px-3 py-2 font-medium transition-colors ${
                active
                    ? 'border-[var(--primary)] text-primary'
                    : 'text-muted-foreground hover:text-primary border-transparent'
            }`}
        >
            {icon}
            {label}
        </Link>
    );
}
