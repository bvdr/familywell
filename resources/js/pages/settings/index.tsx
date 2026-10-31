import { Head, Link, usePage } from '@inertiajs/react';
import { Bell, ChevronRight, Languages, LayoutGrid, LogIn, Pencil, Plug, SunMoon, User } from 'lucide-react';

import { FamilyMark } from '@/components/family-mark';
import { SettingsGroup, SettingsPage, SettingsRow } from '@/components/settings-ui';
import { useAppearance } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { SharedData } from '@/types';

type Props = {
    signIn: string[];
    localeName: string | null;
    notifications: { on: number; total: number };
    apps: string[];
    family: { name: string; color: string; symbol: string | null } | null;
    home: { on: number; total: number };
};

// Me (design board 12, M1): what the avatar opens. Only the person's own settings, then one row into the family's.
export default function Me({ signIn, localeName, notifications, apps, family, home }: Props) {
    const t = useT();
    const { auth } = usePage<SharedData>().props;
    const { appearance } = useAppearance();
    const provider = (p: string) => p.charAt(0).toUpperCase() + p.slice(1);

    return (
        <AppLayout hideMobileHeader breadcrumbs={[{ title: t('settings_hub.me'), href: '/settings' }]}>
            <Head title={t('settings_hub.me')} />
            <SettingsPage back={{ href: '/dashboard', label: t('settings_hub.back') }}>
                <div className="flex items-center gap-4">
                    <span className="bg-accent-strong text-accent-strong-foreground flex size-16 shrink-0 items-center justify-center rounded-full text-3xl font-bold">
                        {auth.user.name.charAt(0).toUpperCase()}
                    </span>
                    <div className="min-w-0 flex-1">
                        <h1 className="font-display text-primary truncate text-[28px] leading-tight font-bold tracking-[-0.8px]">{auth.user.name}</h1>
                        <p className="text-muted-foreground truncate text-base">{auth.user.email}</p>
                    </div>
                    <Link
                        href="/settings/profile"
                        aria-label={t('settings_hub.profile_email')}
                        className="bg-surface text-primary flex size-11 shrink-0 items-center justify-center rounded-full"
                    >
                        <Pencil className="size-5" />
                    </Link>
                </div>

                <SettingsGroup label={t('settings_hub.you')}>
                    <SettingsRow icon={User} title={t('settings_hub.profile_email')} value={auth.user.name} href="/settings/profile" />
                    <SettingsRow
                        icon={LogIn}
                        title={t('settings_hub.sign_in')}
                        value={signIn.length ? signIn.map(provider).join(', ') : t('settings_hub.email_code')}
                        href="/settings/sign-in"
                    />
                    <SettingsRow
                        icon={SunMoon}
                        title={t('settings_hub.appearance')}
                        value={t(`settings_hub.mode_${appearance}`)}
                        href="/settings/appearance"
                    />
                    <SettingsRow icon={Languages} title={t('settings_hub.language')} value={localeName} href="/settings/language" />
                    <SettingsRow
                        icon={Bell}
                        title={t('settings_hub.notifications')}
                        value={t('settings_hub.n_of_m_on', { on: notifications.on, total: notifications.total })}
                        href="/settings/notifications"
                    />
                    <SettingsRow
                        icon={LayoutGrid}
                        title={t('settings_hub.home_dashboard')}
                        value={t('settings_hub.n_of_m_on', { on: home.on, total: home.total })}
                        href="/settings/home"
                    />
                    <SettingsRow
                        icon={Plug}
                        title={t('settings_hub.connected_apps')}
                        value={apps.join(', ') || t('settings_hub.none')}
                        href="/settings/connected-apps"
                    />
                </SettingsGroup>

                {family && (
                    <SettingsGroup label={t('settings_hub.your_family')}>
                        <Link href="/settings/family" prefetch className="hover:bg-muted/50 flex items-center gap-3.5 px-4 py-4">
                            <FamilyMark {...family} className="size-12 rounded-xl text-xl" />
                            <span className="min-w-0 flex-1">
                                <span className="text-primary block truncate text-lg font-semibold">{family.name}</span>
                                <span className="text-muted-foreground block truncate text-sm">{t('settings_hub.family_sub')}</span>
                            </span>
                            <ChevronRight className="text-muted-foreground size-5 shrink-0" />
                        </Link>
                    </SettingsGroup>
                )}

                <div className="flex flex-col items-start gap-1 px-2">
                    <Link method="post" href={route('logout')} as="button" className="text-primary min-h-11 text-lg font-semibold">
                        {t('nav.log_out')}
                    </Link>
                    <Link href="/settings/delete-account" className="text-destructive flex min-h-11 items-center text-lg font-semibold">
                        {t('settings_hub.delete_account')}
                    </Link>
                </div>
            </SettingsPage>
        </AppLayout>
    );
}
