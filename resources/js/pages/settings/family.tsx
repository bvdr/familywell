import { Head, router, usePage } from '@inertiajs/react';
import { CreditCard, Globe, House, Image, LayoutGrid, Trash2, Users } from 'lucide-react';
import { useState } from 'react';

import { MODULE_ITEMS } from '@/components/mobile-tab-bar';
import { browserZone, RegionSheet, zoneCity, type RegionCountry, type RegionSamples, type RegionZone } from '@/components/region-sheet';
import { SettingsGroup, SettingsPage, SettingsRow } from '@/components/settings-ui';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';

type Props = {
    family: { name: string; color: string; symbol: string | null };
    isAdmin: boolean;
    members: number;
    moduleCount: { on: number; total: number };
    plan: 'lifetime' | 'paid' | 'free' | null;
    // Keys only for installed modules (App\Support\Region).
    region: { units?: 'metric' | 'imperial'; currency?: string; timezone: string | null };
    // Only on /settings/family/region: the hub with the Region & units sheet open on top.
    regionSheet?: Props['region'] & { currencies?: string[]; samples?: RegionSamples; countries: RegionCountry[]; timezones: RegionZone[] };
};

// Modules with their own settings page, shown under "Module settings" only when the family has them on.
// A module page keeps its own URL and layout; this is the one place to find them. New module with a settings
// page? Add it here.
const MODULE_SETTINGS: Record<string, string> = {
    spending: '/m/spending/settings',
    shopping: '/m/shopping/settings',
    inventory: '/m/inventory/settings',
    vehicles: '/m/vehicles/settings',
    expense_import: '/m/expense-import/settings',
};

// Family settings (design board 11, Y1): opened from Me → the family. Only family-wide things; the person's own
// settings live on Me.
export default function FamilySettings({ family, isAdmin, members, moduleCount, plan, region, regionSheet }: Props) {
    const t = useT();
    const [saving, setSaving] = useState(false);
    // "Metric · RON · Bucharest"; no zone picked yet = nothing to show for it. The city only from sm up: on a phone
    // the three don't fit next to "Region & units" and the title got cut instead; the sheet shows the zone a tap away.
    const regionBase = [region.units && t(`settings_region.${region.units}`), region.currency].filter(Boolean).join(' · ');
    const city = region.timezone ? zoneCity(region.timezone) : '';
    const regionValue = (
        <>
            {regionBase}
            {city && <span className="hidden sm:inline">{regionBase ? ` · ${city}` : city}</span>}
        </>
    );
    // The sheet is its own URL (a deep link, and the row stays a plain link); closing goes back to the hub.
    const closeRegion = () => router.visit('/settings/family', { preserveScroll: true, preserveState: true });
    const enabled = new Set(usePage<{ modules?: { enabled?: string[] } }>().props.modules?.enabled ?? []);
    const moduleRows = Object.entries(MODULE_SETTINGS).filter(([id]) => enabled.has(id));

    return (
        <AppLayout hideMobileHeader breadcrumbs={[{ title: family.name, href: '/settings/family' }]}>
            <Head title={family.name} />
            <SettingsPage back={{ href: '/settings', label: t('settings_hub.me') }} title={family.name}>
                <SettingsGroup label={t('settings_hub.family')}>
                    <SettingsRow icon={House} title={t('settings_hub.family_look')} href="/settings/family/look" />
                    <SettingsRow icon={Globe} title={t('settings_hub.region')} value={regionValue} href="/settings/family/region" />
                    <SettingsRow
                        icon={Users}
                        title={t('settings_hub.members')}
                        value={t('settings_hub.people', { count: members })}
                        href="/family/members"
                    />
                    <SettingsRow
                        icon={LayoutGrid}
                        title={t('settings_hub.modules')}
                        value={t('settings_hub.n_of_m_on', { on: moduleCount.on, total: moduleCount.total })}
                        href="/family/modules"
                    />
                    <SettingsRow icon={Image} title={t('settings_hub.media')} href="/settings/media" />
                    {plan && (
                        <SettingsRow
                            icon={CreditCard}
                            title={t('settings_hub.billing')}
                            value={t(`settings_hub.plan_${plan}`)}
                            href="/settings/billing"
                        />
                    )}
                </SettingsGroup>

                <SettingsGroup label={t('settings_hub.data')}>
                    <SettingsRow icon={Trash2} title={t('settings_hub.recently_deleted')} href="/settings/recently-deleted" />
                </SettingsGroup>

                {moduleRows.length > 0 && (
                    <SettingsGroup label={t('settings_hub.module_settings')}>
                        {moduleRows.map(([id, href]) => (
                            <SettingsRow key={id} icon={MODULE_ITEMS.find((m) => m.key === id)?.icon} title={t(`nav.${id}`)} href={href} />
                        ))}
                    </SettingsGroup>
                )}
            </SettingsPage>

            {regionSheet && (
                <RegionSheet
                    open
                    onOpenChange={(open) => !open && closeRegion()}
                    // No zone stored yet: offer this browser's, saved with Done like any other line.
                    value={{
                        country: null,
                        units: regionSheet.units,
                        currency: regionSheet.currency,
                        timezone: regionSheet.timezone ?? browserZone(),
                    }}
                    onDone={(v) =>
                        router.patch('/settings/family/region', v, {
                            preserveScroll: true,
                            onStart: () => setSaving(true),
                            onFinish: () => setSaving(false),
                        })
                    }
                    countries={regionSheet.countries}
                    currencies={regionSheet.currencies}
                    timezones={regionSheet.timezones}
                    samples={regionSheet.samples}
                    familyName={family.name}
                    readOnly={!isAdmin}
                    processing={saving}
                />
            )}
        </AppLayout>
    );
}
