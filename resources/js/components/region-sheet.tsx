import { Banknote, CarFront, ChefHat, Clock, Globe, Wallet } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Picker } from '@/components/picker';
import { Segmented } from '@/components/segmented';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { appLocale, useT } from '@/lib/i18n';
import { currencyOptions, money } from '@/lib/money';
import { distanceUnitLabel, fmtMileage, kmToUnit, type UnitSystem } from '@/lib/vehicles';

// A key is present only when the module that owns it is installed (App\Support\Region): no Vehicles, no Units line.
// `timezone` is core (families.timezone, FamilyClock): present whenever the caller passes `timezones`.
export type RegionValue = { country: string | null; units?: UnitSystem; currency?: string; timezone?: string };
export type RegionCountry = { code: string; name: string; units?: UnitSystem; currency?: string; timezone?: string };
// [IANA zone id, its country code] for every zone (App\Support\Region::timezones).
export type RegionZone = [string, string];

// "America/Argentina/Buenos_Aires" -> "Buenos Aires": the picker's label and the hub row's value. The full id is
// the picker hint, so "Europe" or "Argentina" still finds it.
export const zoneCity = (zone: string) => zone.slice(zone.lastIndexOf('/') + 1).replace(/_/g, ' ');
export const browserZone = () => Intl.DateTimeFormat().resolvedOptions().timeZone;
// Real numbers from the family's modules for the preview; missing ones (onboarding, empty modules) use the design's.
export type RegionSamples = { grams?: number; km?: number; cents?: number };

// The preview's time: a fixed Tuesday 16:30, written the way the app language writes times (24h / 12h comes from
// the locale: the app stores no time-format or week-start setting, so the sheet doesn't offer them).
const SAMPLE_TIME = new Date(2026, 0, 6, 16, 30);

/**
 * The one "Region & units" sheet (design Y7), opened from "Change" in onboarding step 1 and from Settings › Region
 * & units. Picking a country fills in the rest; each line can still be changed; "How it looks" shows real values
 * with the draft applied. Edits stay in a draft until Done, so closing it changes nothing. Theme tokens only, so
 * it follows the skin in Settings and the onboarding look in onboarding.
 */
export function RegionSheet({
    open,
    onOpenChange,
    value,
    onDone,
    countries,
    currencies,
    timezones,
    samples,
    familyName,
    readOnly = false,
    processing = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: RegionValue;
    onDone: (value: RegionValue) => void;
    countries: RegionCountry[];
    currencies?: string[];
    timezones?: RegionZone[];
    samples?: RegionSamples;
    familyName: string;
    // Members who aren't admins see the family's values but can't change them.
    readOnly?: boolean;
    processing?: boolean;
}) {
    const t = useT();
    const [draft, setDraft] = useState(value);
    useEffect(() => {
        if (open) setDraft(value);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when it opens, not on every parent render
    }, [open]);

    const zoneCountry = useMemo(() => new Map(timezones ?? []), [timezones]);
    // Plus the family's own zone when it's an old alias a browser reported ("Asia/Calcutta"): valid, but not in the list.
    const zoneOptions = useMemo(() => {
        const ids = (timezones ?? []).map(([z]) => z);
        if (value.timezone && timezones && !ids.includes(value.timezone)) ids.push(value.timezone);
        return ids.map((z) => ({ value: z, label: zoneCity(z), hint: z.replace(/_/g, ' ') }));
    }, [timezones, value.timezone]);

    // Fill in only the lines this family has (a key in the draft = its module is installed). The zone changes only
    // when the one picked isn't in that country already: a family in Los Angeles picking the US keeps Los Angeles.
    const pickCountry = (code: string) => {
        const c = countries.find((x) => x.code === code);
        setDraft((d) => ({
            ...d,
            country: code,
            ...(d.units !== undefined && c?.units ? { units: c.units } : {}),
            ...(d.currency !== undefined && c?.currency ? { currency: c.currency } : {}),
            ...(timezones && c?.timezone && zoneCountry.get(d.timezone ?? '') !== code ? { timezone: c.timezone } : {}),
        }));
    };
    // The family's clock right now, next to the pick, so a wrong zone shows at a glance. Re-read every 30s while the
    // sheet is open (it showed the time from when the page loaded: "10:41" at 11:03).
    const [, setTick] = useState(0);
    useEffect(() => {
        if (!open) return;
        const id = window.setInterval(() => setTick((n) => n + 1), 30_000);
        return () => window.clearInterval(id);
    }, [open]);
    const localTime = draft.timezone
        ? new Date().toLocaleTimeString(appLocale(), { timeZone: draft.timezone, hour: 'numeric', minute: '2-digit' })
        : null;

    const grams = samples?.grams ?? 375;
    const km = samples?.km ?? 84210;
    const cents = samples?.cents ?? 14237;
    const preview = [
        { icon: ChefHat, label: t('nav.recipes'), text: `${grams.toLocaleString(appLocale())} g` },
        draft.units && {
            icon: CarFront,
            label: t('nav.vehicles'),
            text: `${fmtMileage(kmToUnit(km, draft.units))} ${distanceUnitLabel(draft.units)}`,
        },
        draft.currency && { icon: Wallet, label: t('nav.spending'), text: money(cents, draft.currency) },
        {
            icon: Clock,
            label: t('nav.calendar'),
            text: SAMPLE_TIME.toLocaleString(appLocale(), { weekday: 'short', hour: 'numeric', minute: '2-digit' }),
        },
    ].filter((p): p is { icon: typeof Clock; label: string; text: string } => !!p);

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('settings_region.title')}
            // A bit wider than the default dialog so the four preview values sit on one line each (md+ = dialog only).
            className="md:max-w-lg"
            footer={
                <div className="flex flex-col gap-3">
                    <p className="text-muted-foreground text-sm leading-normal">
                        {readOnly ? t('settings_region.admin_only') : t('settings_region.applies', { family: familyName })}
                    </p>
                    <Button
                        type="button"
                        size="cta"
                        className="w-full rounded-full font-bold"
                        disabled={processing}
                        onClick={() => (readOnly ? onOpenChange(false) : onDone(draft))}
                    >
                        {t('settings_region.done')}
                    </Button>
                </div>
            }
        >
            <div className="flex flex-col gap-4 pb-2">
                <FieldRow label={t('settings_region.country')}>
                    <Picker
                        options={countries.map((c) => ({ value: c.code, label: c.name }))}
                        selected={draft.country ? [draft.country] : []}
                        onChange={([c]) => c && pickCountry(c)}
                        placeholder={t('settings_region.country_pick')}
                        fallbackIcon={Globe}
                        ariaLabel={t('settings_region.country')}
                        disabled={readOnly}
                    />
                </FieldRow>

                {draft.units !== undefined && (
                    <FieldRow label={t('settings_region.units')}>
                        <Segmented
                            options={[
                                { value: 'metric', label: t('settings_region.metric') },
                                { value: 'imperial', label: t('settings_region.imperial') },
                            ]}
                            value={draft.units}
                            onChange={(units) => setDraft((d) => ({ ...d, units }))}
                            ariaLabel={t('settings_region.units')}
                            disabled={readOnly}
                        />
                    </FieldRow>
                )}

                {draft.currency !== undefined && currencies && (
                    <FieldRow label={t('settings_region.currency')}>
                        <Picker
                            options={currencyOptions(currencies, appLocale())}
                            fallbackIcon={Banknote}
                            selected={[draft.currency]}
                            onChange={([c]) => c && setDraft((d) => ({ ...d, currency: c }))}
                            ariaLabel={t('settings_region.currency')}
                            disabled={readOnly}
                        />
                    </FieldRow>
                )}

                {timezones && (
                    <FieldRow
                        label={t('settings_region.timezone')}
                        hint={localTime ? t('settings_region.local_time', { time: localTime }) : undefined}
                    >
                        <Picker
                            options={zoneOptions}
                            selected={draft.timezone ? [draft.timezone] : []}
                            onChange={([z]) => z && setDraft((d) => ({ ...d, timezone: z }))}
                            placeholder={t('settings_region.timezone_pick')}
                            fallbackIcon={Clock}
                            ariaLabel={t('settings_region.timezone')}
                            disabled={readOnly}
                        />
                    </FieldRow>
                )}

                <section className="bg-primary text-primary-foreground flex flex-col gap-3 rounded-[20px] p-4">
                    <h3 className="font-mono text-xs font-semibold tracking-[0.12em] uppercase opacity-80">{t('settings_region.how_it_looks')}</h3>
                    <ul className="grid grid-cols-2 gap-x-3 gap-y-3 sm:grid-cols-4">
                        {preview.map(({ icon: Icon, label, text }) => (
                            <li key={label} className="flex min-w-0 flex-col items-center gap-1.5 text-center">
                                <Icon className="size-5 opacity-80" aria-hidden />
                                <span className="sr-only">{label}</span>
                                <span className="font-mono text-sm font-semibold break-words">{text}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </ResponsiveModal>
    );
}

// One field row (the app-wide form rule): label left, control right, every control in the same column with the
// same width; the label centres on its control whatever its height, and helper text sits under the control.
function FieldRow({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-[6.5rem_minmax(0,1fr)] items-center gap-x-3 gap-y-1.5">
            <Label>{label}</Label>
            <div className="min-w-0">{children}</div>
            {hint && <p className="text-muted-foreground col-start-2 text-sm">{hint}</p>}
        </div>
    );
}
