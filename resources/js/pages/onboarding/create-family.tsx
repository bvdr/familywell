import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Globe, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useRef, useState } from 'react';

import { FamilyLookPicker } from '@/components/family-look-picker';
import { FamilyMark, inkOn } from '@/components/family-mark';
import { reactHomer } from '@/components/homer';
import InputError from '@/components/input-error';
import { browserZone, RegionSheet, zoneCity, type RegionCountry, type RegionZone } from '@/components/region-sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { useT } from '@/lib/i18n';

type Region = {
    country: string | null;
    countryName: string | null;
    // Present only when the module that owns it is installed (OnboardingController::region).
    currency?: string;
    currencies?: string[];
    units?: 'metric' | 'imperial';
    // For the shared Region & units sheet's country picker: each with what it implies.
    countries: RegionCountry[];
    timezones: RegionZone[];
};

// `family` is set when they came back from step 2: the family exists and the form edits it.
type Props = { userName: string; colors: string[]; region: Region; family: { name: string; color: string; symbol: string | null } | null };

// The family name we start with, from the person's surname ("Ana Rivera" -> "The Riveras" in English,
// "Familia Rivera" in Romanian), so nobody faces an empty box. No surname -> empty, the chips still help.
function surnameOf(name: string): string {
    const parts = name.trim().split(/\s+/);
    return parts.length > 1 ? parts[parts.length - 1] : '';
}
// English plural for the "The :surnames" default (Rivera -> Riveras, Jones -> Joneses). Only the English
// string uses :surnames; the other languages say "Familia :surname" and never need it.
const pluralOf = (s: string) => (/(s|x|z|ch|sh)$/i.test(s) ? `${s}es` : `${s}s`);

// Step 1 (design 01): name, colour + symbol with a live preview, region & units. Creates the family.
export default function CreateFamily({ userName, colors, region, family }: Props) {
    const t = useT();
    const surname = surnameOf(userName);
    const firstName = userName.trim().split(/\s+/)[0] ?? '';

    const form = useForm({
        name: family?.name ?? (surname ? t('onboarding.default_name', { surname, surnames: pluralOf(surname) }) : ''),
        color: family?.color ?? colors[0],
        symbol: (family?.symbol ?? 'house') as string,
        currency: region.currency ?? null,
        units: region.units ?? null,
        // The family's zone (FamilyClock) starts as this browser's: where the person making it actually is, which the
        // country guess (Cloudflare's) can't tell apart inside a multi-zone country. The sheet can change it.
        timezone: browserZone(),
    });
    const { data, setData } = form;
    // Homer (on the desktop rail) thinks along while they type: at most once per his 2.5 s "thinking" beat.
    const lastThink = useRef(0);
    const typing = (name: string) => {
        setData('name', name);
        if (Date.now() - lastThink.current > 2500) {
            lastThink.current = Date.now();
            reactHomer('thinking');
        }
    };
    const pick = (key: 'color' | 'symbol', value: string) => {
        setData(key, value);
        reactHomer('success');
    };
    const [regionOpen, setRegionOpen] = useState(false);
    // The country only fills in the rest (it isn't saved), so it lives here, not in the form.
    const [country, setCountry] = useState(region.country);
    const countryName = region.countries.find((c) => c.code === country)?.name ?? region.countryName;

    const suggestions = surname
        ? [1, 2, 3].map((n) => t(`onboarding.suggest_${n}`, { surname }))
        : [1, 2, 3].map((n) => t(`onboarding.suggest_plain_${n}`));
    const displayName = data.name.trim() || t('onboarding.your_family');

    const regionLine = [countryName, region.units && t(`onboarding.units_${data.units}`), data.currency, zoneCity(data.timezone)]
        .filter(Boolean)
        .join(' · ');

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('onboarding.store'));
    };

    return (
        <form onSubmit={submit} className="contents">
            <Head title={t('onboarding.family_title')} />
            <OnboardingLayout
                step={1}
                wide
                homer={{ face: 'happy' }}
                back={family ? undefined : route('onboarding.index')}
                title={t('onboarding.family_title')}
                sub={t('onboarding.family_sub')}
                cta={
                    <Button type="submit" variant="sun" size="cta" className="w-full md:w-auto md:min-w-[260px]" disabled={form.processing}>
                        <span className="truncate">{family ? t('onboarding.continue') : t('onboarding.family_cta', { name: displayName })}</span>
                        {form.processing ? <LoaderCircle className="animate-spin" /> : <ArrowRight />}
                    </Button>
                }
                hint={t('onboarding.family_hint')}
            >
                {/* Phone: preview on top, form under it. Tablet / desktop: form left, a bigger live preview right. */}
                <div className="flex flex-col px-5 md:grid md:grid-cols-[minmax(0,1fr)_250px] md:items-start md:gap-8 md:px-0 lg:grid-cols-[minmax(0,474px)_290px] lg:gap-12">
                    <div className="flex flex-col">
                        {/* Live preview: what everyone sees when they open FamilyWell. */}
                        <div className="border-line flex items-center gap-3.5 rounded-[20px] border bg-white p-4 md:hidden">
                            <FamilyMark
                                name={displayName}
                                color={data.color}
                                symbol={data.symbol}
                                className="border-ink size-14 rounded-[18px] border-[1.5px] text-2xl [&_svg]:size-7"
                            />
                            <div className="min-w-0 flex-1">
                                <p className="font-display truncate text-xl font-bold tracking-[-0.4px]">{displayName}</p>
                                <p className="text-stone truncate text-[13px]">{t('onboarding.just_you', { name: firstName })}</p>
                            </div>
                            <span className="text-stone font-mono text-[10px] font-semibold tracking-[0.8px]">{t('onboarding.preview')}</span>
                        </div>

                        <div className="flex flex-col gap-[18px] pt-5 md:pt-0">
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="name" className="text-[13px] font-semibold">
                                    {t('onboarding.family_name')}
                                </Label>
                                <Input
                                    id="name"
                                    required
                                    maxLength={255}
                                    value={data.name}
                                    onChange={(e) => typing(e.target.value)}
                                    className="border-ink focus-visible:border-ink h-12 rounded-[14px] border-[1.5px] bg-white px-4 text-base font-medium focus-visible:ring-0 focus-visible:ring-offset-0"
                                />
                                <InputError message={form.errors.name} />
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-stone text-xs">{t('onboarding.try')}</span>
                                    {suggestions.map((s) => (
                                        <button
                                            key={s}
                                            type="button"
                                            onClick={() => {
                                                setData('name', s);
                                                reactHomer('wink');
                                            }}
                                            className="bg-paper-2 text-ink-2 rounded-full px-[11px] py-1.5 text-xs font-medium"
                                        >
                                            {s}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div className="flex flex-col gap-3">
                                <span className="text-[13px] font-semibold">{t('onboarding.color_symbol')}</span>
                                <FamilyLookPicker brand colors={colors} color={data.color} symbol={data.symbol} name={displayName} onPick={pick} />
                                <InputError message={form.errors.color || form.errors.symbol} />
                            </div>

                            {/* Applied when the family is created, so not offered again when editing it. */}
                            {!family && region.countryName && (
                                <div className="flex flex-col gap-2">
                                    <span className="text-[13px] font-semibold">{t('onboarding.region')}</span>
                                    <div className="bg-paper-2 flex items-center gap-3 rounded-2xl px-3.5 py-3">
                                        <Globe className="size-[18px] shrink-0" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-[14.5px] font-bold">{regionLine}</p>
                                            <p className="text-stone truncate text-xs">{t('onboarding.region_sub')}</p>
                                        </div>
                                        {/* Always something to change now: the time zone is core, not a module's. */}
                                        <button type="button" onClick={() => setRegionOpen(true)} className="text-[13.5px] font-bold">
                                            {t('onboarding.change')}
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                    <LivePreview name={displayName} color={data.color} symbol={data.symbol} firstName={firstName} />
                </div>
            </OnboardingLayout>

            <RegionSheet
                open={regionOpen}
                onOpenChange={setRegionOpen}
                value={{ country, units: data.units ?? undefined, currency: data.currency ?? undefined, timezone: data.timezone }}
                onDone={(v) => {
                    setCountry(v.country);
                    setData((d) => ({ ...d, units: v.units ?? d.units, currency: v.currency ?? d.currency, timezone: v.timezone ?? d.timezone }));
                    setRegionOpen(false);
                }}
                countries={region.countries}
                currencies={region.currencies}
                timezones={region.timezones}
                familyName={displayName}
            />
        </form>
    );
}

// Tablet / desktop: their home taking shape as they type, as it'll open on everyone's phone.
function LivePreview({ name, color, symbol, firstName }: { name: string; color: string; symbol: string; firstName: string }) {
    const t = useT();
    return (
        <aside className="hidden flex-col gap-2.5 md:flex">
            <span className="text-stone font-mono text-[10.5px] font-semibold tracking-[0.8px] uppercase">{t('onboarding.live_preview')}</span>
            <div className="border-line overflow-hidden rounded-[20px] border bg-white">
                <div className="flex flex-col gap-3 p-[18px]" style={{ backgroundColor: color, color: inkOn(color) }}>
                    <FamilyMark
                        name={name}
                        color="#FFFFFF"
                        symbol={symbol}
                        className="border-ink size-14 rounded-2xl border-[1.5px] text-2xl [&_svg]:size-6"
                    />
                    <div className="min-w-0">
                        <p className="font-display truncate text-2xl font-bold tracking-[-0.6px]">{name}</p>
                        <p className="truncate text-[13px] opacity-80">{t('onboarding.just_you', { name: firstName })}</p>
                    </div>
                </div>
                <div className="flex flex-col gap-3 p-[18px]">
                    <p className="font-display text-[17px] font-bold">{t('onboarding.preview_greeting', { name: firstName })}</p>
                    {[80, 55, 90].map((w) => (
                        <span key={w} className="flex items-center gap-2.5">
                            <span className="bg-paper-2 size-[18px] rounded-[5px]" />
                            <span className="bg-paper-2 h-2 rounded-full" style={{ width: `${w}%` }} />
                        </span>
                    ))}
                </div>
            </div>
            <p className="text-stone text-xs leading-normal">{t('onboarding.preview_caption')}</p>
        </aside>
    );
}
