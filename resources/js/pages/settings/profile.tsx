import { type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { MailWarning } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/settings-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';

// Settings > Profile & email (design M2). The delete-account UI moved to its own page (/settings/delete-account, M8).
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
    const { auth, errors: pageErrors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const t = useT();
    const [resent, setResent] = useState(false);

    // The field shows the pending address (design: the new email sits in the field with "Not verified yet" under it),
    // so saving again just re-sends its code instead of silently reverting to the old one.
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: auth.user.name,
        email: pendingEmail ?? auth.user.email,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('profile.update'), { preserveScroll: true });
    };

    // "Resend": the same PATCH with the pending address, which mails a fresh code (rate-limited server side).
    const resend = () => {
        if (!pendingEmail) return;
        router.patch(
            route('profile.update'),
            { name: auth.user.name, email: pendingEmail },
            { preserveScroll: true, onSuccess: () => setResent(true) },
        );
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

    // h-14 + text-base: the design's tall rounded fields; 16px keeps iOS from zooming on focus.
    const field = 'bg-surface-card h-14 rounded-2xl px-4 text-base md:text-base';

    return (
        <AppLayout hideMobileHeader breadcrumbs={[{ title: t('settings_me.profile_title'), href: '/settings/profile' }]}>
            <Head title={t('settings_me.profile_title')} />
            <SettingsPage back={{ href: '/settings', label: t('settings_hub.me') }} title={t('settings_me.profile_title')}>
                {/* ponytail: no profile photo upload exists, so the initial circle without "Change photo". */}
                <div className="flex items-center gap-4">
                    <span className="bg-accent-strong text-accent-strong-foreground border-surface-card flex size-[72px] shrink-0 items-center justify-center rounded-full border-2 text-3xl font-bold">
                        {auth.user.name.charAt(0).toUpperCase()}
                    </span>
                    <p className="text-muted-foreground min-w-0 text-[15px]">{t('settings_me.avatar_hint')}</p>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-5">
                    <div className="grid gap-2">
                        <Label htmlFor="name" className="text-primary text-[15px] font-semibold">
                            {t('settings.name')}
                        </Label>
                        <Input
                            id="name"
                            className={field}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            autoComplete="name"
                            placeholder={t('settings.full_name_placeholder')}
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email" className="text-primary text-[15px] font-semibold">
                            {t('settings_me.email')}
                        </Label>
                        <Input
                            id="email"
                            type="email"
                            className={field}
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            autoComplete="username"
                            placeholder={t('settings.email_address')}
                        />
                        {/* pageErrors: Resend goes through router.patch, whose errors (e.g. too many codes) don't land on useForm. */}
                        <InputError message={errors.email || pageErrors?.email} />
                        {pendingEmail && (
                            <div className="flex items-start gap-2 px-1 text-sm">
                                <MailWarning className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                                <span className="text-muted-foreground min-w-0 flex-1">
                                    {resent ? t('settings_me.code_sent') : t('settings_me.not_verified', { email: pendingEmail })}
                                </span>
                                <button type="button" onClick={resend} disabled={processing} className="text-primary shrink-0 font-semibold">
                                    {t('settings_me.resend')}
                                </button>
                            </div>
                        )}
                    </div>

                    {/* The pending change is confirmed with the 6-digit code sent to the new address (ProfileController::confirmEmail). */}
                    {pendingEmail && (
                        <div className="bg-surface-card grid gap-3 rounded-[22px] border p-4">
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
                                className="h-12 text-center font-mono text-lg tracking-[0.4em]"
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
                        <p className="text-muted-foreground text-sm">
                            {t('settings.email_unverified')}{' '}
                            <Link href={route('verification.send')} method="post" as="button" className="text-primary underline underline-offset-4">
                                {t('settings.resend_verification_link')}
                            </Link>
                            {status === 'verification-link-sent' && (
                                <span className="mt-1 block font-medium">{t('settings.verification_link_sent')}</span>
                            )}
                        </p>
                    )}

                    <Button disabled={processing} className="border-primary h-14 w-full rounded-full border-2 text-lg font-bold">
                        {recentlySuccessful ? t('settings.saved') : t('common.save')}
                    </Button>
                </form>
            </SettingsPage>
        </AppLayout>
    );
}
