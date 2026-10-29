import { Head, usePage } from '@inertiajs/react';
import { CreditCard, Globe, House, Image, LayoutGrid, Trash2, Users } from 'lucide-react';

import { MODULE_ITEMS } from '@/components/mobile-tab-bar';
import { SettingsGroup, SettingsPage, SettingsRow } from '@/components/settings-ui';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';

type Props = {
    family: { name: string; color: string; symbol: string | null };
    isAdmin: boolean;
    members: number;
    modules: { on: number; total: number };
    plan: 'lifetime' | 'paid' | 'free' | null;
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
export default function FamilySettings({ family, members, modules, plan }: Props) {
    const t = useT();
    const enabled = new Set(usePage<{ modules?: { enabled?: string[] } }>().props.modules?.enabled ?? []);
    const moduleRows = Object.entries(MODULE_SETTINGS).filter(([id]) => enabled.has(id));

    return (
        <AppLayout breadcrumbs={[{ title: family.name, href: '/settings/family' }]}>
            <Head title={family.name} />
            <SettingsPage back={{ href: '/settings', label: t('settings_hub.me') }} title={family.name}>
                <SettingsGroup label={t('settings_hub.family')}>
                    <SettingsRow icon={House} title={t('settings_hub.family_look')} href="/settings/family/look" />
                    <SettingsRow icon={Globe} title={t('settings_hub.region')} href="/settings/family/region" />
                    <SettingsRow
                        icon={Users}
                        title={t('settings_hub.members')}
                        value={t('settings_hub.people', { count: members })}
                        href="/family/members"
                    />
                    <SettingsRow
                        icon={LayoutGrid}
                        title={t('settings_hub.modules')}
                        value={t('settings_hub.n_of_m_on', { on: modules.on, total: modules.total })}
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
        </AppLayout>
    );
}
