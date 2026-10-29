import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/settings-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { SharedData } from '@/types';

// Settings > Delete account (design M8): what happens, and a 6-digit code we email to confirm. The design draws it
// as a sheet over Me; it's a page here because Me links to /settings/delete-account. The code is sent by a button,
// not on page load: Me prefetches this page, so sending on GET would mail everyone who just opens Me.
export default function DeleteAccount() {
    const t = useT();
    const { auth } = usePage<SharedData>().props;
    const [sent, setSent] = useState(false);

    // One form for both calls: send errors (demo account, too many codes) come back under `code` too.
    const { data, setData, post, delete: destroy, processing, errors } = useForm({ code: '' });
    const send = () => post(route('profile.delete.code'), { preserveScroll: true, onSuccess: () => setSent(true) });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        destroy(route('profile.destroy'), { preserveScroll: true, onError: () => setData('code', '') });
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('settings_me.delete_title'), href: '/settings/delete-account' }]}>
            <Head title={t('settings_me.delete_title')} />
            <SettingsPage back={{ href: '/settings', label: t('settings_hub.me') }} title={t('settings_me.delete_title')}>
                <p className="text-primary text-[17px] leading-normal">{t('settings_me.delete_lede')}</p>

                {!sent ? (
                    <div className="flex flex-col gap-2">
                        <Button
                            type="button"
                            onClick={send}
                            disabled={processing}
                            className="border-primary h-14 w-full rounded-full border-2 text-lg font-bold"
                        >
                            {t('settings_me.send_code')}
                        </Button>
                        <InputError message={errors.code} />
                    </div>
                ) : (
                    <form onSubmit={submit} className="flex flex-col gap-5">
                        <div className="grid gap-2">
                            <Label htmlFor="delete-code" className="text-primary text-[15px] font-semibold">
                                {t('settings_me.code_to', { email: auth.user.email })}
                            </Label>
                            <Input
                                id="delete-code"
                                autoFocus
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                pattern="[0-9]*"
                                maxLength={6}
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.replace(/\D/g, '').slice(0, 6))}
                                placeholder={t('settings_me.code_placeholder')}
                                // 16px+ text: iOS zooms into smaller inputs on focus.
                                className="bg-surface-card h-14 rounded-2xl px-4 text-base md:text-base"
                            />
                            <InputError message={errors.code} />
                            <button
                                type="button"
                                onClick={send}
                                disabled={processing}
                                className="text-muted-foreground self-start text-sm underline underline-offset-4"
                            >
                                {t('settings_me.send_new_code')}
                            </button>
                        </div>
                        <Link href="/settings" className="text-primary flex min-h-11 items-center self-start text-lg font-semibold">
                            {t('settings_me.keep_account')}
                        </Link>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing || data.code.length !== 6}
                            className="border-primary h-14 w-full rounded-full border-2 text-lg font-bold"
                        >
                            {t('settings_me.delete_button')}
                        </Button>
                    </form>
                )}

                {!sent && (
                    <Link href="/settings" className="text-primary flex min-h-11 items-center self-start text-lg font-semibold">
                        {t('settings_me.keep_account')}
                    </Link>
                )}
            </SettingsPage>
        </AppLayout>
    );
}
