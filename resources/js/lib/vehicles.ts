import type { TFn } from '@/lib/i18n';

// The vehicle shape the server sends (Vehicle::toPayload + the controller's photo URLs). `state` and
// `nextServiceKm` are placeholders in P1 (no maintenance data yet); the card renders them but they carry
// no real signal until maintenance schedules land in P2.
export type VehicleState = 'good' | 'service_due' | 'overdue';

export type Vehicle = {
    id: number;
    make: string;
    model: string;
    year: number | null;
    version: string | null;
    fuelType: string | null;
    transmission: string | null;
    vin: string | null;
    registrationNumber: string | null;
    color: string | null;
    purchaseDate: string | null;
    purchasePrice: number | null;
    ownerId: number | null;
    primaryDriverId: number | null;
    currentMileage: number;
    hasPhoto: boolean;
    photoThumbUrl: string | null;
    photoUrl: string | null;
    licensePlate: string | null;
    insuranceCompany: string | null;
    roadTaxStatus: string | null;
    inspectionStatus: string | null;
    notes: string | null;
    state: VehicleState;
    nextServiceKm: number | null;
    createdBy: number | null;
    updatedAt: string | null;
};

export type MileageEntry = {
    id: number;
    mileage: number;
    recordedBy: number | null;
    recordedAt: string | null;
    recorder: string | null;
};

export type MemberOption = { id: number; name: string };

// Colour per placeholder status, drawn from the theme tokens so it tracks the active skin. In P1 every
// vehicle is "good"; the other tones are wired for P2 (service due soon / overdue).
export const STATE_META: Record<VehicleState, { color: string }> = {
    good: { color: 'var(--success)' },
    service_due: { color: 'var(--warning)' },
    overdue: { color: 'var(--destructive)' },
};

/** Localised status label (Good / Service due / Overdue). */
export const stateLabel = (t: TFn, state: VehicleState): string => t(`vehicles.state_${state}`);

/** Localised fuel-type label; an unknown/blank value renders as an em dash. */
export const fuelLabel = (t: TFn, fuel: string | null): string => {
    if (!fuel) return '—';
    const key = `vehicles.fuel_${fuel}`;
    const label = t(key);
    return label === key ? fuel : label;
};

/** Localised transmission label; blank renders as an em dash. */
export const transmissionLabel = (t: TFn, tr: string | null): string => {
    if (!tr) return '—';
    const key = `vehicles.transmission_${tr}`;
    const label = t(key);
    return label === key ? tr : label;
};

// A thousands-grouped mileage string ("142340" -> "142,340"). Locale-agnostic grouping keeps the number
// readable without pulling a formatter per render.
export const fmtMileage = (n: number): string => n.toLocaleString('en-US');

// "142,340 km" — the odometer as shown across the module. Units are metric (km) in P1; the global
// metric/imperial setting is honoured in P5 (spec 20), so this stays the single place to switch.
export const mileageText = (t: TFn, km: number): string => t('vehicles.mileage_value', { value: fmtMileage(km) });

// One-line vehicle title ("Dacia Duster"). The subtitle (year · engine) is built at the call site.
export const vehicleTitle = (v: Pick<Vehicle, 'make' | 'model'>): string => `${v.make} ${v.model}`.trim();

/** "2019 · 1.5 dCi" — the card subtitle, skipping any missing part. */
export const vehicleSubtitle = (v: Pick<Vehicle, 'year' | 'version'>): string =>
    [v.year ? String(v.year) : null, v.version].filter(Boolean).join(' · ');

// The quick-update presets on the Overview (spec 2): add this many km to the current odometer, or enter
// a value manually. Kept here so the overview and any future quick-add share one list.
export const MILEAGE_PRESETS = [10, 50, 100, 500] as const;
