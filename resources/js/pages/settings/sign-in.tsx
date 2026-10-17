import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

import HeadingSmall from '@/components/heading-small';
import { AppleLogo, GoogleLogo } from '@/components/social-buttons';
import { Button } from '@/components/ui/button';

type SocialAccount = { id: number; provider: 'google' | 'apple'; email: string | null };

// Settings > Sign-in. There are no passwords (sign-in is an email code), so this only lists the Google /
// Apple accounts linked for one-tap sign-in. Any of them can be disconnected: the email code always works.
export default function SignIn({ socialAccounts = [] }: { socialAccounts?: SocialAccount[] }) {
    const t = useT();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const providerName = (p: string) => (p === 'apple' ? 'Apple' : 'Google');
    const disconnect = (account: SocialAccount) => {
        if (!confirm(t('settings.disconnect_social_confirm', { provider: providerName(account.provider) }))) return;
        router.delete(`/settings/social-accounts/${account.id}`, { preserveScroll: true });
    };
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('settings.sign_in_methods'), href: '/settings/sign-in' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.sign_in_methods')} />

            <SettingsLayout>
                <div className="space-y-4">
                    <HeadingSmall title={t('settings.sign_in_methods')} description={t('settings.sign_in_methods_desc')} />
                    {socialAccounts.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('settings.sign_in_methods_none')}</p>
                    ) : (
                        <ul className="divide-border border-border divide-y rounded-xl border">
                            {socialAccounts.map((account) => (
                                <li key={account.id} className="flex items-center gap-3 p-4">
                                    {account.provider === 'apple' ? (
                                        <AppleLogo className="size-5 shrink-0" />
                                    ) : (
                                        <GoogleLogo className="size-5 shrink-0" />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <div className="font-medium">{providerName(account.provider)}</div>
                                        {account.email && <div className="text-muted-foreground truncate text-sm">{account.email}</div>}
                                    </div>
                                    <Button type="button" size="sm" variant="outline" onClick={() => disconnect(account)}>
                                        {t('settings.disconnect')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <InputError message={errors?.social} />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
