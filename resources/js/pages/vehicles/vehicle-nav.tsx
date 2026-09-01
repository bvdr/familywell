import { Link } from '@inertiajs/react';
import { FileText, Fuel, Gauge, Wrench } from 'lucide-react';

import { useT } from '@/lib/i18n';

// The per-car sub-navigation (spec: Overview / Maintenance / Repairs / Fuel / Documents …). Overview and
// Maintenance are live in P2; the rest are shown disabled ("coming soon") so the shape of a car's home is
// visible now and the tabs don't move as later phases land. `active` selects the current tab.
type Tab = 'overview' | 'maintenance';

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
            {/* Later-phase tabs, present but inert so the nav is stable across phases. */}
            <TabStub icon={<Wrench className="size-4" />} label={t('vehicles.tab_repairs')} />
            <TabStub icon={<Fuel className="size-4" />} label={t('vehicles.tab_fuel')} />
            <TabStub icon={<FileText className="size-4" />} label={t('vehicles.tab_documents')} />
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

function TabStub({ icon, label }: { icon: React.ReactNode; label: string }) {
    return (
        <span className="text-muted-foreground/40 flex cursor-default items-center gap-1.5 border-b-2 border-transparent px-3 py-2 font-medium">
            {icon}
            {label}
        </span>
    );
}
