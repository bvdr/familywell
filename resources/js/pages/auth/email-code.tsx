import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle, Sparkles } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import LegalNote from '@/components/legal-note';
import SocialButtons from '@/components/social-buttons';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

interface Props {
    // Same flow either way (a new address gets an account); the URL only picks the wording.
    mode: 'login' | 'register';
    status?: string;
    canDemo: boolean;
    // The address the last code went to: show the code box for it.
    codeSentTo?: string | null;
}

// Sign in AND sign up: email -> 6-digit code by email -> in (AuthenticatedSessionController). No passwords.
export default function EmailCode({ mode, status, canDemo, codeSentTo }: Props) {
    const t = useT();
    const register = mode === 'register';
    // "Use another email" goes back to step 1 without a server round trip.
    const [changing, setChanging] = useState(false);
    const askCode = !!codeSentTo && !changing;

    // ponytail: TEMPORARY password sign-in for app reviewers (AuthenticatedSessionController::passwordLogin).
    // Remove withPassword, the password field and its link once the app directory approvals are through.
    const [withPassword, setWithPassword] = useState(false);
    const emailForm = useForm({ email: codeSentTo ?? '', password: '' });
    const codeForm = useForm({ code: '' });

    const sendCode: FormEventHandler = (e) => {
        e.preventDefault();
        if (withPassword) {
            emailForm.post(route('login.password'), { onFinish: () => emailForm.reset('password') });
            return;
        }
        emailForm.post(route('auth.code.send'), { preserveScroll: true, onSuccess: () => setChanging(false) });
    };
    // Sends the code it's given, not codeForm.data: on the auto-submit after the sixth digit, setData hasn't
    // landed yet and the form would still hold five.
    const verify = (code: string) => {
        codeForm.transform(() => ({ code }));
        codeForm.post(route('auth.code.verify'), { onError: () => codeForm.setData('code', '') });
    };

    return (
        <AuthLayout
            title={askCode ? t('auth.code_title') : register ? t('auth.create_account_title') : t('auth.welcome_back')}
            description={askCode ? t('auth.code_desc', { email: codeSentTo ?? '' }) : register ? t('auth.register_desc') : t('auth.signin_desc')}
        >
            <Head title={register ? t('auth.create_account') : t('auth.log_in')} />

            {askCode ? (
                <form
                    className="flex flex-col gap-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        verify(codeForm.data.code);
                    }}
                >
                    <div className="grid gap-2">
                        <Label htmlFor="code">{t('auth.code_label')}</Label>
                        {/* Native one-time-code field: iOS/Android offer the code from the email right above the keyboard. */}
                        <Input
                            id="code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            pattern="[0-9]*"
                            maxLength={6}
                            autoFocus
                            required
                            value={codeForm.data.code}
                            onChange={(e) => {
                                const code = e.target.value.replace(/\D/g, '').slice(0, 6);
                                codeForm.setData('code', code);
                                // Six digits is the whole answer: go on without making them find the button.
                                if (code.length === 6 && !codeForm.processing) verify(code);
                            }}
                            placeholder="123456"
                            className="h-14 text-center font-mono text-2xl tracking-[0.5em]"
                        />
                        <InputError message={codeForm.errors.code} />
                    </div>

                    <Button type="submit" className="w-full" disabled={codeForm.processing || codeForm.data.code.length !== 6}>
                        {codeForm.processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        {t('auth.code_continue')}
                    </Button>

                    <div className="text-muted-foreground flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-sm">
                        <button type="button" className="underline underline-offset-4" onClick={() => setChanging(true)}>
                            {t('auth.code_change_email')}
                        </button>
                        <button
                            type="button"
                            className="underline underline-offset-4 disabled:opacity-50"
                            disabled={emailForm.processing}
                            onClick={() => emailForm.post(route('auth.code.send'), { preserveScroll: true })}
                        >
                            {t('auth.code_resend')}
                        </button>
                    </div>
                    <InputError message={emailForm.errors.email} className="text-center" />
                </form>
            ) : (
                <>
                    <SocialButtons />

                    <form className="flex flex-col gap-6" onSubmit={sendCode}>
                        <div className="grid gap-2">
                            <Label htmlFor="email">{t('auth.email_address')}</Label>
                            <Input
                                id="email"
                                type="email"
                                required
                                autoFocus
                                autoComplete="email"
                                value={emailForm.data.email}
                                onChange={(e) => emailForm.setData('email', e.target.value)}
                                placeholder={t('auth.email_placeholder')}
                            />
                            <InputError message={emailForm.errors.email} />
                        </div>

                        {withPassword && (
                            <div className="grid gap-2">
                                <Label htmlFor="password">{t('auth.password')}</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    required
                                    autoComplete="current-password"
                                    value={emailForm.data.password}
                                    onChange={(e) => emailForm.setData('password', e.target.value)}
                                />
                            </div>
                        )}

                        <Button type="submit" className="w-full" disabled={emailForm.processing}>
                            {emailForm.processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            {withPassword ? t('auth.password_sign_in') : t('auth.code_send')}
                        </Button>

                        {!register && (
                            <button
                                type="button"
                                className="text-muted-foreground -mt-2 text-center text-sm underline underline-offset-4"
                                onClick={() => setWithPassword(!withPassword)}
                            >
                                {withPassword ? t('auth.password_use_code') : t('auth.password_use')}
                            </button>
                        )}

                        <div className="text-muted-foreground text-center text-sm">
                            {register ? t('auth.already_have_account') : t('auth.new_to_familywell')}{' '}
                            <TextLink href={register ? route('login') : route('register')}>
                                {register ? t('auth.log_in') : t('auth.create_an_account')}
                            </TextLink>
                        </div>
                    </form>
                </>
            )}

            <LegalNote />

            {/* Public demo: a plain link (full page load) so the server can establish the session. */}
            {canDemo && !askCode && (
                <div className="mt-6 flex flex-col items-center gap-2 border-t pt-6">
                    <Button asChild variant="outline" className="w-full">
                        <a href={route('demo.enter')}>
                            <Sparkles className="h-4 w-4" />
                            {t('auth.try_demo')}
                        </a>
                    </Button>
                    <p className="text-muted-foreground text-center text-xs">{t('auth.try_demo_hint')}</p>
                </div>
            )}

            {status && <div className="mb-4 text-center text-sm font-medium text-green-600">{status}</div>}
        </AuthLayout>
    );
}
