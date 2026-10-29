import { Head, router, usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';

import InputError from '@/components/input-error';
import { SettingsGroup, SettingsPage, SettingsRow } from '@/components/settings-ui';
import { AppleLogo, GoogleLogo } from '@/components/social-buttons';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { SharedData } from '@/types';

type SocialAccount = { id: number; provider: 'google' | 'apple'; email: string | null };

// Settings > Sign-in (design M3). There are no passwords: the email code always works, so any linked Google /
// Apple account can be disconnected, the last one included.
export default function SignIn({ socialAccounts = [], providers = [] }: { socialAccounts?: SocialAccount[]; providers?: string[] }) {
    const t = useT();
    const { auth, errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const providerName = (p: string) => (p === 'apple' ? 'Apple' : 'Google');
    const disconnect = (account: SocialAccount) => {
        if (!confirm(t('settings.disconnect_social_confirm', { provider: providerName(account.provider) }))) return;
        router.delete(`/settings/social-accounts/${account.id}`, { preserveScroll: true });
    };
    // Linked accounts first, then each provider this install offers that isn't linked yet.
    const linked = new Set(socialAccounts.map((a) => a.provider));
    const unlinked = providers.filter((p) => !linked.has(p as SocialAccount['provider']));
    const logo = (p: string) => (p === 'apple' ? <AppleLogo className="size-5" /> : <GoogleLogo className="size-5" />);
    const tile = (p: string) => (
        <span className="bg-surface text-primary flex size-11 shrink-0 items-center justify-center rounded-xl">{logo(p)}</span>
    );

    return (
        <AppLayout breadcrumbs={[{ title: t('settings_hub.sign_in'), href: '/settings/sign-in' }]}>
            <Head title={t('settings_hub.sign_in')} />
            <SettingsPage back={{ href: '/settings', label: t('settings_hub.me') }} title={t('settings_hub.sign_in')}>
                <div className="flex flex-col gap-2.5">
                    <SettingsGroup label={t('settings_me.sign_in_group_email')}>
                        <SettingsRow
                            icon={Mail}
                            tile
                            title={t('settings_me.email')}
                            sub={<span className="block truncate">{auth.user.email}</span>}
                            value={t('settings_me.always_on')}
                        />
                    </SettingsGroup>
                    <p className="text-muted-foreground px-1 text-[15px]">{t('settings_me.email_note')}</p>
                </div>

                {(socialAccounts.length > 0 || unlinked.length > 0) && (
                    <div className="flex flex-col gap-2.5">
                        <SettingsGroup label={t('settings_me.also_sign_in_with')}>
                            {socialAccounts.map((account) => (
                                <div key={account.id} className="flex min-h-14 items-center gap-3.5 border-b px-4 py-3 last:border-b-0">
                                    {tile(account.provider)}
                                    <span className="min-w-0 flex-1">
                                        <span className="text-primary block text-[17px] leading-snug">{providerName(account.provider)}</span>
                                        {account.email && <span className="text-muted-foreground block truncate text-sm">{account.email}</span>}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => disconnect(account)}
                                        className="text-destructive min-h-11 shrink-0 text-base font-semibold"
                                    >
                                        {t('settings.disconnect')}
                                    </button>
                                </div>
                            ))}
                            {/* ponytail: no "Connect" button. Social routes are guest-only and link by matching email, so
                                connecting means signing in with the provider; the note below says how. */}
                            {unlinked.map((p) => (
                                <div key={p} className="flex min-h-14 items-center gap-3.5 border-b px-4 py-3 last:border-b-0">
                                    {tile(p)}
                                    <span className="min-w-0 flex-1">
                                        <span className="text-primary block text-[17px] leading-snug">{providerName(p)}</span>
                                        <span className="text-muted-foreground block text-sm">{t('settings_me.not_connected')}</span>
                                    </span>
                                </div>
                            ))}
                        </SettingsGroup>
                        {unlinked.length > 0 && <p className="text-muted-foreground px-1 text-[15px]">{t('settings_me.connect_note')}</p>}
                    </div>
                )}
                <InputError message={errors?.social} />
            </SettingsPage>
        </AppLayout>
    );
}
