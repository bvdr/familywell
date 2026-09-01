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
    // Derived maintenance rollup (P2): the card status dot, the soonest service distance, and how many
    // schedules need attention now (due or overdue).
    state: VehicleState;
    nextServiceKm: number | null;
    attention: number;
    createdBy: number | null;
    updatedAt: string | null;
};

// The compact vehicle header the maintenance screens render from (title + odometer + state rollup).
export type VehicleHeader = {
    id: number;
    make: string;
    model: string;
    year: number | null;
    version: string | null;
    currentMileage: number;
    state: VehicleState;
    nextServiceKm: number | null;
    attention: number;
};

// The four maintenance statuses from the engine (MaintenanceStatus), most urgent last.
export type MaintStatus = 'good' | 'due_soon' | 'due' | 'overdue';

// A recurring maintenance schedule with its live computed status (VehicleMaintenanceSchedule::toPayload).
export type MaintenanceSchedule = {
    id: number;
    vehicleId: number;
    name: string;
    type: string;
    intervalKm: number | null;
    intervalMonths: number | null;
    lastPerformedAt: string | null;
    lastPerformedMileage: number | null;
    notes: string | null;
    active: boolean;
    status: MaintStatus;
    nextDueMileage: number | null;
    nextDueDate: string | null;
    remainingKm: number | null;
    remainingDays: number | null;
    neverPerformed: boolean;
};

// One performed-maintenance record (VehicleMaintenanceHistory::toPayload).
export type MaintenanceHistoryEntry = {
    id: number;
    scheduleId: number | null;
    name: string;
    performedAt: string | null;
    mileage: number;
    cost: number | null;
    provider: string | null;
    notes: string | null;
};

// A predefined template for the add-schedule picker (MaintenanceTemplate::options).
export type MaintenanceTemplate = {
    key: string;
    intervalKm: number | null;
    intervalMonths: number | null;
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

// ---- Maintenance (P2) ------------------------------------------------------------------------------

// Colour + label per maintenance status, from the theme tokens so it tracks the active skin. Mirrors the
// four engine statuses; the card dot collapses due/due_soon into one amber (see maintStatusToVehicleState).
export const MAINT_STATUS_META: Record<MaintStatus, { color: string }> = {
    good: { color: 'var(--success)' },
    due_soon: { color: 'var(--warning)' },
    due: { color: 'var(--warning)' },
    overdue: { color: 'var(--destructive)' },
};

/** Localised status label (Good / Due soon / Due / Overdue). */
export const maintStatusLabel = (t: TFn, status: MaintStatus): string => t(`vehicles.maint_status_${status}`);

/** Localised template label ("Oil change"); an unknown key (or "custom") falls back to the raw type. */
export const templateLabel = (t: TFn, key: string): string => {
    const k = `vehicles.mtpl_${key}`;
    const label = t(k);
    return label === k ? key : label;
};

// The recurring interval as a human line: "Every 15,000 km · 12 months", skipping a missing trigger.
export const intervalText = (t: TFn, km: number | null, months: number | null): string => {
    const parts: string[] = [];
    if (km != null) parts.push(t('vehicles.every_km', { value: fmtMileage(km) }));
    if (months != null) parts.push(t('vehicles.every_months', { value: months }));
    return parts.join(' · ');
};

/**
 * The one-line "how far off" summary for a schedule, from whichever trigger is more pressing. Reads as
 * "6,860 km remaining", "in 24 days", "due now", or "overdue by 500 km / 12 days". Never-performed
 * schedules have no baseline, so they read "Not recorded yet".
 */
export const remainingText = (t: TFn, s: MaintenanceSchedule): string => {
    if (s.neverPerformed) return t('vehicles.never_performed');

    // Pick the trigger closest to (or furthest past) its due point — the smaller signed remaining wins.
    const km = s.remainingKm;
    const days = s.remainingDays;
    const useKm = km != null && (days == null || km <= days);

    if (useKm && km != null) {
        if (km > 0) return t('vehicles.km_remaining', { value: fmtMileage(km) });
        if (km === 0) return t('vehicles.due_now');
        return t('vehicles.km_overdue', { value: fmtMileage(-km) });
    }
    if (days != null) {
        if (days > 0) return t('vehicles.days_remaining', { value: days });
        if (days === 0) return t('vehicles.due_now');
        return t('vehicles.days_overdue', { value: -days });
    }
    return t('vehicles.due_now');
};
