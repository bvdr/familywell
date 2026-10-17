import { useForm } from '@inertiajs/react';
import { FormEventHandler, useRef, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useT } from '@/lib/i18n';

export default function DeleteUser() {
    const t = useT();
    const [open, setOpen] = useState(false);
    const emailInput = useRef<HTMLInputElement>(null);
    // No passwords: typing their own email address confirms it (ProfileController::destroy checks it).
    const { data, setData, delete: destroy, processing, reset, errors, clearErrors } = useForm({ email: '' });

    const deleteUser: FormEventHandler = (e) => {
        e.preventDefault();
        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onError: () => emailInput.current?.focus(),
        });
    };

    const close = (v: boolean) => {
        setOpen(v);
        if (!v) {
            clearErrors();
            reset();
        }
    };

    return (
        <div className="space-y-6">
            <HeadingSmall title={t('settings.delete_account')} description={t('settings.delete_account_desc')} />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">{t('settings.warning')}</p>
                    <p className="text-sm">{t('settings.delete_warning_text')}</p>
                </div>

                <Button variant="destructive" onClick={() => setOpen(true)}>
                    {t('settings.delete_account')}
                </Button>
            </div>

            {/* ResponsiveModal, not a plain Dialog: on phones it's the keyboard-aware sheet whose pinned footer
                stays above the iOS keyboard (a Dialog left the Delete button under the keyboard's accessory bar). */}
            <ResponsiveModal
                open={open}
                onOpenChange={close}
                title={t('settings.confirm_delete_title')}
                description={t('settings.confirm_delete_desc')}
                autoFocusRef={emailInput}
                footer={
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={() => close(false)}>
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" form="delete-account-form" variant="destructive" disabled={processing}>
                            {t('settings.delete_account')}
                        </Button>
                    </div>
                }
            >
                <form id="delete-account-form" className="grid gap-2" onSubmit={deleteUser}>
                    <Label htmlFor="delete-email" className="sr-only">{t('settings.delete_type_email')}</Label>
                    <Input
                        id="delete-email"
                        ref={emailInput}
                        type="email"
                        name="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder={t('auth.email_placeholder')}
                        autoComplete="off"
                    />
                    <InputError message={errors.email} />
                </form>
            </ResponsiveModal>
        </div>
    );
}
