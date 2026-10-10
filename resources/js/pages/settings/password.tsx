import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';

import HeadingSmall from '@/components/heading-small';
import { AppleLogo, GoogleLogo } from '@/components/social-buttons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type SocialAccount = { id: number; provider: 'google' | 'apple'; email: string | null };

export default function Password({ socialAccounts = [], status }: { socialAccounts?: SocialAccount[]; status?: string }) {
    const t = useT();
    const { socialProviders = [], errors: pageErrors } = usePage<{ socialProviders?: string[]; errors: Record<string, string> }>().props;
    const providerName = (p: string) => (p === 'apple' ? 'Apple' : 'Google');
    const disconnect = (account: SocialAccount) => {
        if (!confirm(t('settings.disconnect_social_confirm', { provider: providerName(account.provider) }))) return;
        router.delete(`/settings/social-accounts/${account.id}`, { preserveScroll: true });
    };
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.password_settings'),
            href: '/settings/password',
        },
    ];
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.profile_settings')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('settings.update_password_title')} description={t('settings.update_password_desc')} />

                    <form onSubmit={updatePassword} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="current_password">{t('settings.current_password')}</Label>

                            <Input
                                id="current_password"
                                ref={currentPasswordInput}
                                value={data.current_password}
                                onChange={(e) => setData('current_password', e.target.value)}
                                type="password"
                                className="mt-1 block w-full"
                                autoComplete="current-password"
                                placeholder={t('settings.current_password')}
                            />

                            <InputError message={errors.current_password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">{t('settings.new_password')}</Label>

                            <Input
                                id="password"
                                ref={passwordInput}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                type="password"
                                className="mt-1 block w-full"
                                autoComplete="new-password"
                                placeholder={t('settings.new_password')}
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">{t('settings.confirm_password')}</Label>

                            <Input
                                id="password_confirmation"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                type="password"
                                className="mt-1 block w-full"
                                autoComplete="new-password"
                                placeholder={t('settings.confirm_password')}
                            />

                            <InputError message={errors.password_confirmation} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>{t('settings.save_password')}</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">{t('settings.saved')}</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                {/* Linked "Continue with Google / Apple" accounts. Hidden on installs with no provider configured
                    (unless something is still linked from before). Disconnect is refused server-side for the
                    last one (see PasswordController::disconnectSocial), so the button only shows with two+. */}
                {(socialAccounts.length > 0 || socialProviders.length > 0) && (
                    <div className="space-y-4">
                        <HeadingSmall title={t('settings.sign_in_methods')} description={t('settings.sign_in_methods_desc')} />
                        {/* "Google connected" after a password login completed a parked link (AuthenticatedSessionController). */}
                        {status && <p className="text-sm font-medium text-green-600">{status}</p>}
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
                                        {socialAccounts.length > 1 && (
                                            <Button type="button" size="sm" variant="outline" onClick={() => disconnect(account)}>
                                                {t('settings.disconnect')}
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <InputError message={pageErrors?.social} />
                    </div>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
