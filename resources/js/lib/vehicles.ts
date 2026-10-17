import { appLocale, type TFn } from '@/lib/i18n';
import { usePage } from '@inertiajs/react';

import { money as moneyOf } from '@/lib/money';

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

// A maintenance schedule (recurring, or a one-off planned job) with its live computed status (VehicleMaintenanceSchedule::toPayload).
export type MaintenanceSchedule = {
    id: number;
    vehicleId: number;
    name: string;
    type: string;
    intervalKm: number | null;
    intervalMonths: number | null;
    // Explicit due point; with no interval the schedule is a one-off planned job (closed when completed).
    dueMileage: number | null;
    dueOn: string | null;
    oneOff: boolean;
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

// One performed-maintenance record (VehicleMaintenanceHistory::toPayload). `provider` is the RESOLVED
// display name (linked provider wins over free text); `serviceProviderId` is the link when set.
export type MaintenanceHistoryEntry = {
    id: number;
    scheduleId: number | null;
    name: string;
    performedAt: string | null;
    mileage: number;
    cost: number | null;
    provider: string | null;
    serviceProviderId: number | null;
    notes: string | null;
};

// The open Tasks task a schedule spawned when it fell due (P3: Maintenance -> Tasks). Null when there is
// none; `listId` deep-links to the task's list in the Tasks module.
export type MaintenanceLinkedTask = {
    id: number;
    listId: number | null;
    title: string;
};

// A predefined template for the add-schedule picker (MaintenanceTemplate::options).
export type MaintenanceTemplate = {
    key: string;
    intervalKm: number | null;
    intervalMonths: number | null;
};

// ---- Repairs / Documents / Notes (P4) --------------------------------------------------------------

// One file attached to a repair (invoice/photo). `url` streams the full file; `thumbUrl` the card preview.
export type RepairAttachment = {
    id: number;
    name: string | null;
    url: string;
    thumbUrl: string;
};

// An unplanned repair (Repair::toPayload) — a permanent-history record, not a recurring rule. `provider` is
// the RESOLVED display name (linked provider wins over free text); `serviceProviderId` is the link when set.
export type Repair = {
    id: number;
    vehicleId: number;
    title: string;
    occurredOn: string | null;
    mileage: number | null;
    cost: number | null;
    provider: string | null;
    serviceProviderId: number | null;
    notes: string | null;
    attachments: RepairAttachment[];
    createdBy: number | null;
};

// ---- Service providers / garages (V1.1) ------------------------------------------------------------

// A service provider / garage (ServiceProvider::toPayload).
export type ServiceProvider = {
    id: number;
    name: string;
    address: string | null;
    phone: string | null;
    website: string | null;
    notes: string | null;
    createdBy: number | null;
};

// A provider row on the manage screen — the provider plus how many services it has on record.
export type ServiceProviderWithCount = ServiceProvider & { serviceCount: number };

// The compact {id,name} option the maintenance/repair forms render their provider picker from.
export type ProviderOption = { id: number; name: string };

// One service row on a provider's detail (a maintenance completion or a repair done there).
export type ProviderServiceEntry = {
    id: string;
    type: 'maintenance' | 'repair';
    date: string | null;
    title: string;
    vehicle: string | null;
    mileage: number | null;
    cost: number | null;
};

// The document types the picker offers (VehicleDocument::TYPES). Kept in sync with the server list.
export type DocumentType =
    | 'registration'
    | 'insurance'
    | 'inspection'
    | 'purchase_contract'
    | 'warranty'
    | 'service_invoice'
    | 'repair_invoice'
    | 'manual'
    | 'road_tax'
    | 'other';

// A vehicle document (VehicleDocument::toPayload). `expiring` is the derived badge (within the lead window).
export type VehicleDocument = {
    id: number;
    vehicleId: number;
    type: string;
    title: string;
    expiresOn: string | null;
    expiring: boolean;
    hasFile: boolean;
    fileName: string | null;
    fileUrl: string | null;
    notes: string | null;
    hasRenewalTask: boolean;
    createdBy: number | null;
};

// A note attached to a vehicle (a reused Notes-module note; VehicleNotesController::notePayload).
export type VehicleNote = {
    id: number;
    title: string;
    body: string;
    createdBy: number | null;
    updatedAt: string | null;
};

/** Localised document-type label; an unknown/blank value falls back to the raw type. */
export const documentTypeLabel = (t: TFn, type: string): string => {
    const key = `vehicles.doctype_${type}`;
    const label = t(key);
    return label === key ? type : label;
};

export type MileageEntry = {
    id: number;
    mileage: number;
    recordedBy: number | null;
    recordedAt: string | null;
    recorder: string | null;
};

// ---- Overview roll-up / Timeline / Settings (P5) ---------------------------------------------------

// The combined Overview status (VehicleOverview::status): 'attention' when maintenance is due/overdue OR a
// document is expiring, else 'good'.
export type OverviewStatus = {
    overall: 'good' | 'attention';
    maintenanceAttention: number;
    documentsExpiring: number;
};

// The soonest maintenance schedule to come up (VehicleOverview::next.maintenance).
export type NextMaintenance = {
    id: number;
    name: string;
    type: string;
    status: MaintStatus;
    remainingKm: number | null;
    remainingDays: number | null;
    nextDueDate: string | null;
    nextDueMileage: number | null;
    neverPerformed: boolean;
};

// The soonest-expiring document (VehicleOverview::next.document).
export type NextDocument = {
    id: number;
    title: string;
    type: string;
    expiresOn: string | null;
    expiring: boolean;
};

export type OverviewNext = { maintenance: NextMaintenance | null; document: NextDocument | null };

// One "Recent" event on the Overview (maintenance completion or repair, with cost/mileage).
export type RecentEvent = {
    id: string;
    type: 'maintenance' | 'repair';
    date: string | null;
    title: string;
    cost: number | null;
    mileage: number | null;
};

// One row in the Vehicle Timeline (VehicleTimeline::forVehicle) — a uniform entry across every event type.
export type TimelineType = 'mileage' | 'maintenance' | 'repair' | 'document' | 'expense' | 'tire';
export type TimelineEntry = {
    id: string;
    type: TimelineType;
    date: string | null;
    title: string;
    subtitle: string | null;
    mileage: number | null;
    cost: number | null;
};

// One customisable maintenance-template default interval on the Settings screen (VehicleSetting::toPayload).
export type SettingsTemplate = {
    key: string;
    intervalKm: number | null;
    intervalMonths: number | null;
    customised: boolean;
};

// The family's Vehicles settings (VehicleSetting::toPayload).
export type VehicleSettings = {
    unitSystem: UnitSystem;
    notifyMaintenance: boolean;
    notifyDocuments: boolean;
    notifyMileageMilestone: boolean;
    templates: SettingsTemplate[];
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

// A thousands-grouped number string ("142340" -> "142,340"). Locale-agnostic grouping keeps the value
// readable without pulling a formatter per render.
export const fmtMileage = (n: number): string => n.toLocaleString(appLocale());
// "34.5" / "34,5": one decimal in the app language.
const oneDecimal = (n: number): string => n.toLocaleString(appLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 });

// ---- Units (P5) ------------------------------------------------------------------------------------
// Distance is ALWAYS stored in km; the family's unit_system only decides how it is DISPLAYED (km vs
// miles). Vehicles has no wider unit framework — this one axis is it. Mirrors modules/vehicles/VehicleUnits.php
// so the server-rendered widget and the client read identically.
export type UnitSystem = 'metric' | 'imperial';
export const KM_PER_MILE = 1.60934;

/** The short distance-unit label ("km" / "mi"). */
export const distanceUnitLabel = (unit: UnitSystem): string => (unit === 'imperial' ? 'mi' : 'km');

/** Stored km -> the display unit's whole value (an odometer is never shown with decimals). */
export const kmToUnit = (km: number, unit: UnitSystem): number => (unit === 'imperial' ? Math.round(km / KM_PER_MILE) : km);

/** A display-unit value (what the user typed / a preset) -> stored km. */
export const unitToKm = (value: number, unit: UnitSystem): number => (unit === 'imperial' ? Math.round(value * KM_PER_MILE) : value);

/** Thousands-grouped distance value in the display unit, WITHOUT the unit label. */
export const fmtDistance = (km: number, unit: UnitSystem): string => fmtMileage(kmToUnit(km, unit));

/** "142,340 km" / "88,447 mi" — a distance with its unit label, in the family's system. */
export const distanceText = (km: number, unit: UnitSystem): string => `${fmtDistance(km, unit)} ${distanceUnitLabel(unit)}`;

// The odometer as shown across the module. `unit` defaults to metric so pre-P5 call sites (which don't
// thread the family unit) keep rendering km unchanged; the P5 surfaces pass the real unit.
export const mileageText = (t: TFn, km: number, unit: UnitSystem = 'metric'): string => distanceText(km, unit);

// ---- Fuel volume + consumption (V1.1) --------------------------------------------------------------
// Volume is ALWAYS stored in litres; imperial displays US gallons. Consumption is stored L/100km; imperial
// displays mpg (US). Cost-per-distance is stored per-km; imperial displays per-mile. Mirrors the distance
// pattern — the server keeps one canonical unit and the client converts only for display.
export const LITERS_PER_US_GALLON = 3.785411784;
export const MPG_L100_CONSTANT = 235.214583; // 1 L/100km == 235.214583 mpg (US)

/** The short volume-unit label ("L" / "gal"). */
export const volumeUnitLabel = (unit: UnitSystem): string => (unit === 'imperial' ? 'gal' : 'L');

/** Stored litres -> the display unit's value (litres, or US gallons for imperial). */
export const litersToUnit = (liters: number, unit: UnitSystem): number => (unit === 'imperial' ? liters / LITERS_PER_US_GALLON : liters);

/** "52.0 L" / "13.7 gal" — a volume with its unit label, to one decimal. */
export const volumeText = (liters: number, unit: UnitSystem): string => `${oneDecimal(litersToUnit(liters, unit))} ${volumeUnitLabel(unit)}`;

/** The consumption-unit label ("L/100km" / "mpg"). */
export const consumptionUnitLabel = (unit: UnitSystem): string => (unit === 'imperial' ? 'mpg' : 'L/100km');

/** Stored L/100km -> the display unit's value (L/100km, or mpg for imperial). null passes through. */
export const consumptionValue = (lPer100: number | null, unit: UnitSystem): number | null => {
    if (lPer100 == null || lPer100 <= 0) return lPer100;
    return unit === 'imperial' ? MPG_L100_CONSTANT / lPer100 : lPer100;
};

/** "6.5 L/100km" / "36.2 mpg" — consumption with its unit label. Null renders as an em dash. */
export const consumptionText = (lPer100: number | null, unit: UnitSystem): string => {
    const v = consumptionValue(lPer100, unit);
    if (v == null) return '—';
    return `${oneDecimal(v)} ${consumptionUnitLabel(unit)}`;
};

/**
 * Car costs (repair/maintenance/fuel/part `cost`) are decimal amounts in the car currency: the family's
 * "Vehicles" spend list, shared to every Vehicles page as `carCurrency` (Modules\Vehicles\ShareCarCurrency).
 * They used to render through a hard-coded "€", so a RON family saw lei amounts as euros. Returns a
 * formatter: `const carMoney = useCarMoney(); carMoney(1072.29)` -> "RON 1,072.29" / "1.072,29 lei".
 */
export function useCarMoney(): (amount: number) => string {
    const currency = usePage<{ carCurrency?: string }>().props.carCurrency ?? 'EUR';
    return (amount) => moneyOf(Math.round(amount * 100), currency);
}

/** "RON 0.12/km" / "€0.19/mi": cost per distance in the family's unit and car currency. Stored per-km. Null -> em dash. */
export const costPerDistanceText = (costPerKm: number | null, unit: UnitSystem, carMoney: (amount: number) => string): string => {
    if (costPerKm == null) return '—';
    const perUnit = unit === 'imperial' ? costPerKm * KM_PER_MILE : costPerKm;
    return `${carMoney(perUnit)}/${distanceUnitLabel(unit)}`;
};

// One recorded fuel fill (VehicleFuelEntry::toPayload). Litres/cost/mileage are stored metric; the Fuel tab
// converts for display via the helpers above.
export type FuelEntry = {
    id: number;
    vehicleId: number;
    filledOn: string | null;
    liters: number;
    cost: number | null;
    mileage: number;
    isFullTank: boolean;
    station: string | null;
    note: string | null;
    createdBy: number | null;
};

// The computed fuel metrics for a vehicle (FuelStats::forEntries). All values metric; convert for display.
export type FuelStats = {
    entryCount: number;
    totalLiters: number;
    totalCost: number;
    latestConsumption: number | null; // L/100km, most recent full-to-full segment
    avgConsumption: number | null; // L/100km across all segments
    costPerKm: number | null; // currency per km
    distanceTracked: number | null; // km between first and last fill
    monthlyCost: number | null;
    annualCost: number | null;
};

// ---- Tyres (V1.1) ----------------------------------------------------------------------------------

export type TireSeason = 'summer' | 'winter' | 'all_season';

// One tyre set (VehicleTireSet::toPayload). Distances stored in km; the tab converts for display.
export type TireSet = {
    id: number;
    vehicleId: number;
    season: string;
    brand: string | null;
    size: string | null;
    installedOn: string | null;
    installedMileage: number | null;
    treadDepthMm: number | null;
    storageLocation: string | null;
    isMounted: boolean;
    notes: string | null;
    createdBy: number | null;
};

/** Localised tyre-season label; an unknown/blank value falls back to the raw season. */
export const tireSeasonLabel = (t: TFn, season: string): string => {
    const key = `vehicles.tire_season_${season}`;
    const label = t(key);
    return label === key ? season : label;
};

// ---- Parts -> Shopping (V1.1) ----------------------------------------------------------------------

export type PartStatus = 'requested' | 'bought';

// One part requested/bought for a vehicle via Shopping (VehiclePartRequest::toPayload).
export type PartRequest = {
    id: number;
    vehicleId: number;
    name: string;
    estimatedCost: number | null;
    status: PartStatus;
    boughtOn: string | null;
    onShoppingList: boolean;
    maintenanceScheduleId: number | null;
    repairId: number | null;
    createdBy: number | null;
};

// ---- Cost & consumption analytics (V1.1 P3) --------------------------------------------------------
// Everything here is DERIVED server-side (VehicleAnalytics) from the vehicle->Spending links, the fuel log
// and the odometer history — no new financial tracking. Money is in MINOR UNITS (cents) in the family's
// spending currency, exactly like the Spending analytics screen this mirrors; distance is metric km.

// The expense types a vehicle's spend breaks down into (VehicleAnalytics::TYPES).
export type CostType = 'maintenance' | 'repair' | 'fuel' | 'parts' | 'other';

// One breakdown row (type, amount in display cents, % of the period total).
export type CostTypeRow = { key: string; amount: number; pct: number };

// One point on a money trend curve (amount in display cents).
export type TrendDay = { date: string; amount: number };

// The per-period spend roll-up (VehicleAnalytics::forVehicle).
export type VehicleAnalyticsData = {
    currency: string;
    total: number; // display cents
    count: number;
    byType: CostTypeRow[];
    daily: TrendDay[];
    granularity: 'day' | 'month';
    kmDriven: number | null; // km driven across the period (from odometer history)
    costPerKm: number | null; // operating cost per km, in display CENTS per km
};

// One consumption point (FuelStats::consumptionTrend) — L/100km at the fill that closed a full-to-full segment.
export type ConsumptionPoint = { date: string; value: number };

// The estimated next fill (VehicleAnalytics::estimates.nextFuelFill) — a projection from the average gap
// between fills; never presented as exact.
export type NextFuelFill = { estimatedDate: string; days: number; avgKmBetweenFills: number | null };

// The estimations block (VehicleAnalytics::estimates): soonest service (reuses NextMaintenance) + next fill.
export type VehicleEstimates = { nextService: NextMaintenance | null; nextFuelFill: NextFuelFill | null };

// The compact headline the Overview shows (VehicleAnalytics::compact).
export type CompactAnalytics = {
    currency: string;
    ytdTotal: number; // display cents
    ytdCostPerKm: number | null; // display cents per km
    avgConsumption: number | null; // L/100km
    hasData: boolean;
};

// Colour per expense type, from the theme tokens so it tracks the active skin. Reuses the maintenance /
// warning / destructive tones so the breakdown reads consistently with the rest of the module.
export const COST_TYPE_META: Record<CostType, { color: string }> = {
    maintenance: { color: 'var(--primary)' },
    repair: { color: 'var(--warning)' },
    fuel: { color: 'var(--accent-strong)' },
    parts: { color: 'var(--success)' },
    other: { color: 'var(--muted-foreground)' },
};

/** The colour for an expense-type key, falling back to the "other" tone for an unknown key. */
export const costTypeColor = (key: string): string => COST_TYPE_META[key as CostType]?.color ?? COST_TYPE_META.other.color;

/** Localised expense-type label ("Maintenance" / "Fuel" / …); an unknown key falls back to the raw key. */
export const costTypeLabel = (t: TFn, key: string): string => {
    const k = `vehicles.cost_type_${key}`;
    const label = t(k);
    return label === k ? key : label;
};

/**
 * Operating cost per distance, formatted in the family's currency + unit. Input is in display CENTS per km
 * (what VehicleAnalytics returns); imperial scales to per-mile. Rounds to whole cents — a per-km figure is
 * a headline, not an invoice — and null renders as an em dash.
 */
export const costPerKmText = (costPerKmCents: number | null, currency: string, unit: UnitSystem): string => {
    if (costPerKmCents == null) return '—';
    const perUnit = unit === 'imperial' ? costPerKmCents * KM_PER_MILE : costPerKmCents;
    return `${moneyOf(Math.round(perUnit), currency)}/${distanceUnitLabel(unit)}`;
};

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

// The recurring interval as a human line: "15,000 km · 12 months", skipping a missing trigger. The km
// side is shown in the family's unit (default metric for pre-P5 callers).
export const intervalText = (t: TFn, km: number | null, months: number | null, unit: UnitSystem = 'metric'): string => {
    const parts: string[] = [];
    if (km != null) parts.push(t('vehicles.every_km', { value: distanceText(km, unit) }));
    if (months != null) parts.push(t('vehicles.every_months', { value: months }));
    return parts.join(' · ');
};

/**
 * The one-line "how far off" summary for a schedule, from whichever trigger is more pressing. Reads as
 * "6,860 km remaining", "in 24 days", "due now", or "overdue by 500 km / 12 days". Never-performed
 * schedules have no baseline, so they read "Not recorded yet".
 */
export const remainingText = (t: TFn, s: MaintenanceSchedule, unit: UnitSystem = 'metric'): string => {
    if (s.neverPerformed) return t('vehicles.never_performed');

    // Pick the trigger closest to (or furthest past) its due point — the smaller signed remaining wins.
    const km = s.remainingKm;
    const days = s.remainingDays;
    const useKm = km != null && (days == null || km <= days);

    if (useKm && km != null) {
        if (km > 0) return t('vehicles.km_remaining', { value: distanceText(km, unit) });
        if (km === 0) return t('vehicles.due_now');
        return t('vehicles.km_overdue', { value: distanceText(-km, unit) });
    }
    if (days != null) {
        if (days > 0) return t('vehicles.days_remaining', { value: days });
        if (days === 0) return t('vehicles.due_now');
        return t('vehicles.days_overdue', { value: -days });
    }
    return t('vehicles.due_now');
};
