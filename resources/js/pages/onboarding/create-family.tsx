import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Check, Ellipsis, Globe, LoaderCircle } from 'lucide-react';
import { type IconName } from 'lucide-react/dynamic';
import { FormEventHandler, useState } from 'react';

import { FamilyMark, inkOn, monogramOf, SymbolIcon } from '@/components/family-mark';
import { FamilySymbolSheet } from '@/components/family-symbol-sheet';
import InputError from '@/components/input-error';
import { Picker } from '@/components/picker';
import { Segmented } from '@/components/segmented';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Region = {
    country: string | null;
    countryName: string | null;
    // Present only when the module that owns it is installed (OnboardingController::region).
    currency?: string;
    currencies?: string[];
    units?: 'metric' | 'imperial';
};

// `family` is set when they came back from step 2: the family exists and the form edits it.
type Props = { userName: string; colors: string[]; region: Region; family: { name: string; color: string; symbol: string | null } | null };

// The quick row of symbols next to the monogram; the rest live in the library sheet.
const QUICK: IconName[] = ['house', 'sun', 'sprout', 'mountain'];

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
    });
    const { data, setData } = form;
    const [sheet, setSheet] = useState(false);
    const [regionOpen, setRegionOpen] = useState(false);

    const suggestions = surname
        ? [1, 2, 3].map((n) => t(`onboarding.suggest_${n}`, { surname }))
        : [1, 2, 3].map((n) => t(`onboarding.suggest_plain_${n}`));
    const quick: IconName[] =
        QUICK.includes(data.symbol as IconName) || data.symbol === 'monogram' ? QUICK : [data.symbol as IconName, ...QUICK.slice(0, 3)];
    const displayName = data.name.trim() || t('onboarding.your_family');

    const regionLine = [region.countryName, region.units && t(`onboarding.units_${data.units}`), data.currency].filter(Boolean).join(' · ');

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('onboarding.store'));
    };

    return (
        <form onSubmit={submit} className="contents">
            <Head title={t('onboarding.family_title')} />
            <OnboardingLayout
                step={1}
                back={family ? undefined : route('onboarding.index')}
                title={t('onboarding.family_title')}
                sub={t('onboarding.family_sub')}
                cta={
                    <Button type="submit" variant="sun" size="cta" className="w-full lg:w-auto lg:min-w-[260px]" disabled={form.processing}>
                        <span className="truncate">{family ? t('onboarding.continue') : t('onboarding.family_cta', { name: displayName })}</span>
                        {form.processing ? <LoaderCircle className="animate-spin" /> : <ArrowRight />}
                    </Button>
                }
                hint={t('onboarding.family_hint')}
            >
                <div className="flex flex-col px-5 lg:px-0">
                    {/* Live preview: what everyone sees when they open FamilyWell. */}
                    <div className="border-line flex items-center gap-3.5 rounded-[20px] border bg-white p-4">
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

                    <div className="flex flex-col gap-[18px] pt-5">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="name" className="text-[13px] font-semibold">
                                {t('onboarding.family_name')}
                            </Label>
                            <Input
                                id="name"
                                required
                                maxLength={255}
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="border-ink focus-visible:border-ink h-12 rounded-[14px] border-[1.5px] bg-white px-4 text-base font-medium focus-visible:ring-0 focus-visible:ring-offset-0"
                            />
                            <InputError message={form.errors.name} />
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-stone text-xs">{t('onboarding.try')}</span>
                                {suggestions.map((s) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setData('name', s)}
                                        className="bg-paper-2 text-ink-2 rounded-full px-[11px] py-1.5 text-xs font-medium"
                                    >
                                        {s}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="flex flex-col gap-3">
                            <span className="text-[13px] font-semibold">{t('onboarding.color_symbol')}</span>
                            <div className="flex justify-between lg:justify-start lg:gap-3" role="radiogroup" aria-label={t('onboarding.color')}>
                                {colors.map((c) => (
                                    <button
                                        key={c}
                                        type="button"
                                        role="radio"
                                        aria-checked={data.color === c}
                                        aria-label={c}
                                        onClick={() => setData('color', c)}
                                        className={cn(
                                            'flex size-11 items-center justify-center rounded-full',
                                            data.color === c && 'border-ink border-[1.5px]',
                                        )}
                                        style={{ backgroundColor: c, color: inkOn(c) }}
                                    >
                                        {data.color === c && <Check className="size-5" strokeWidth={2.5} />}
                                    </button>
                                ))}
                            </div>
                            <div className="flex justify-between lg:justify-start lg:gap-3" role="radiogroup" aria-label={t('onboarding.symbol')}>
                                {(['monogram', ...quick] as string[]).map((s) => {
                                    const on = data.symbol === s;
                                    return (
                                        <button
                                            key={s}
                                            type="button"
                                            role="radio"
                                            aria-checked={on}
                                            aria-label={s}
                                            onClick={() => setData('symbol', s)}
                                            className={cn(
                                                'flex size-11 items-center justify-center rounded-[14px] border',
                                                on ? 'border-ink border-[1.5px]' : 'border-line bg-white',
                                            )}
                                            style={on ? { backgroundColor: data.color, color: inkOn(data.color) } : undefined}
                                        >
                                            {s === 'monogram' ? (
                                                <span className="font-display text-[22px] font-extrabold tracking-[-0.5px]">
                                                    {monogramOf(displayName)}
                                                </span>
                                            ) : (
                                                <SymbolIcon name={s as IconName} className="size-[22px]" />
                                            )}
                                        </button>
                                    );
                                })}
                                <button
                                    type="button"
                                    aria-label={t('onboarding.symbol_more')}
                                    onClick={() => setSheet(true)}
                                    className="bg-paper-2 flex size-11 items-center justify-center rounded-[14px]"
                                >
                                    <Ellipsis className="size-[22px]" />
                                </button>
                            </div>
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
                                    {(region.currencies || region.units) && (
                                        <button type="button" onClick={() => setRegionOpen(true)} className="text-[13.5px] font-bold">
                                            {t('onboarding.change')}
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </OnboardingLayout>

            <FamilySymbolSheet
                open={sheet}
                onOpenChange={setSheet}
                value={data.symbol}
                onPick={(s) => setData('symbol', s)}
                familyName={displayName}
                color={data.color}
            />

            <Dialog open={regionOpen} onOpenChange={setRegionOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('onboarding.region')}</DialogTitle>
                        <DialogDescription>{t('onboarding.region_desc')}</DialogDescription>
                    </DialogHeader>
                    {region.currencies && (
                        <div className="flex flex-col gap-2">
                            <Label>{t('onboarding.currency')}</Label>
                            <Picker
                                options={region.currencies.map((c) => ({ value: c, label: c }))}
                                selected={data.currency ? [data.currency] : []}
                                onChange={([c]) => c && setData('currency', c)}
                                ariaLabel={t('onboarding.currency')}
                            />
                        </div>
                    )}
                    {region.units && (
                        <div className="flex flex-col gap-2">
                            <Label>{t('onboarding.distance')}</Label>
                            <Segmented
                                options={[
                                    { value: 'metric', label: t('onboarding.units_metric') },
                                    { value: 'imperial', label: t('onboarding.units_imperial') },
                                ]}
                                value={data.units ?? 'metric'}
                                onChange={(u) => setData('units', u)}
                                ariaLabel={t('onboarding.distance')}
                            />
                        </div>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="ink" onClick={() => setRegionOpen(false)}>
                            {t('onboarding.done')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </form>
    );
}
