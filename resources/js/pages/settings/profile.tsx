import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import DeleteUser from '@/components/delete-user';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useT } from '@/lib/i18n';

export default function Profile({
    mustVerifyEmail,
    status,
    pendingEmail,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    // A new address waiting for the code we sent it; the email only changes once that code is typed.
    pendingEmail?: string | null;
}) {
    const { auth } = usePage<SharedData>().props;
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: t('settings.profile_settings'),
            href: '/settings/profile',
        },
    ];

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: auth.user.name,
        email: auth.user.email,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'), { preserveScroll: true });
    };

    const codeForm = useForm({ code: '' });
    const confirmEmail: FormEventHandler = (e) => {
        e.preventDefault();
        codeForm.post(route('profile.email.confirm'), {
            preserveScroll: true,
            onSuccess: () => codeForm.reset(),
            onError: () => codeForm.setData('code', ''),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.profile_settings')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('settings.profile_info_title')} description={t('settings.profile_info_desc')} />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('settings.name')}</Label>

                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder={t('settings.full_name_placeholder')}
                            />

                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">{t('settings.email_address')}</Label>

                            <Input
                                id="email"
                                type="email"
                                className="mt-1 block w-full"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                autoComplete="username"
                                placeholder={t('settings.email_address')}
                            />

                            <InputError className="mt-2" message={errors.email} />
                        </div>

                        {pendingEmail && (
                            <div className="bg-accent-subtle grid gap-3 rounded-xl p-4">
                                <p className="text-sm">{t('settings.email_change_sent', { email: pendingEmail })}</p>
                                <Label htmlFor="email-code">{t('auth.code_label')}</Label>
                                <Input
                                    id="email-code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    pattern="[0-9]*"
                                    maxLength={6}
                                    value={codeForm.data.code}
                                    onChange={(e) => codeForm.setData('code', e.target.value.replace(/\D/g, '').slice(0, 6))}
                                    placeholder="123456"
                                    className="text-center font-mono text-lg tracking-[0.4em]"
                                />
                                <InputError message={codeForm.errors.code} />
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button type="button" onClick={confirmEmail} disabled={codeForm.processing || codeForm.data.code.length !== 6}>
                                        {t('settings.email_change_confirm')}
                                    </Button>
                                    <Link
                                        href={route('profile.email.cancel')}
                                        method="delete"
                                        as="button"
                                        preserveScroll
                                        className="text-muted-foreground text-sm underline underline-offset-4"
                                    >
                                        {t('settings.email_change_cancel')}
                                    </Link>
                                </div>
                            </div>
                        )}

                        {mustVerifyEmail && auth.user.email_verified_at === null && (
                            <div>
                                <p className="mt-2 text-sm text-neutral-800">
                                    {t('settings.email_unverified')}
                                    <Link
                                        href={route('verification.send')}
                                        method="post"
                                        as="button"
                                        className="rounded-md text-sm text-neutral-600 underline hover:text-neutral-900 focus:ring-2 focus:ring-offset-2 focus:outline-hidden"
                                    >
                                        {t('settings.resend_verification_link')}
                                    </Link>
                                </p>

                                {status === 'verification-link-sent' && (
                                    <div className="mt-2 text-sm font-medium text-green-600">{t('settings.verification_link_sent')}</div>
                                )}
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>{t('common.save')}</Button>

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

                <DeleteUser />
            </SettingsLayout>
        </AppLayout>
    );
}
