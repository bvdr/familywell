import { Head, usePage } from '@inertiajs/react';
import { Bell, Send, Smartphone, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';

import { SettingsGroup, SettingsPage, SettingsRow, SettingsSwitch } from '@/components/settings-ui';
import { MODULES } from '@/components/your-modules';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { iosNeedsInstall, isSubscribed, pushSupported, savePushPrefs, sendTestPush, subscribeToPush, unsubscribeFromPush } from '@/lib/push';

type Category = { id: string; label: string; icon: string; on: boolean };

// Notifications (design M6): one switch for push on this device, then one per category. The server only sends the
// categories of modules the family has on (plus non-module ones like "Shared with me"). Push is per device; the
// categories are per person, saved through PATCH /push/prefs as before.
export default function Notifications({ categories }: { categories: Category[] }) {
    const t = useT();
    const vapidPublicKey = (usePage().props as { vapidPublicKey?: string }).vapidPublicKey ?? '';
    const [prefs, setPrefs] = useState<Record<string, boolean>>(() => Object.fromEntries(categories.map((c) => [c.id, c.on])));
    const [supported, setSupported] = useState(true);
    const [needsInstall, setNeedsInstall] = useState(false);
    const [on, setOn] = useState(false);
    const [busy, setBusy] = useState(false);
    const [sent, setSent] = useState(false);

    // Push support and the subscription live in the browser, so they're read after mount.
    useEffect(() => {
        setSupported(pushSupported());
        setNeedsInstall(iosNeedsInstall());
        void isSubscribed().then(setOn);
    }, []);

    const togglePref = (id: string) => {
        const next = { ...prefs, [id]: !prefs[id] };
        setPrefs(next);
        void savePushPrefs(next);
    };

    const togglePush = async () => {
        setBusy(true);
        try {
            if (on) {
                await unsubscribeFromPush();
                setOn(false);
            } else {
                setOn(await subscribeToPush(vapidPublicKey));
            }
        } finally {
            setBusy(false);
        }
    };

    const test = async () => {
        setSent(false);
        if (await sendTestPush()) {
            setSent(true);
            setTimeout(() => setSent(false), 4000);
        }
    };

    // Module categories use the module's own name and icon (translated, like the nav); the rest are named here.
    const label = (c: Category) => (MODULES[c.id] ? t(`nav.${c.id}`) : c.id === 'sharing' ? t('notifications.shared_with_me') : c.label);
    const icon = (c: Category) => MODULES[c.id]?.icon ?? (c.id === 'sharing' ? UserPlus : Bell);
    const blocked = !supported || needsInstall;

    return (
        <AppLayout breadcrumbs={[{ title: t('notifications.title'), href: '/settings/notifications' }]}>
            <Head title={t('notifications.title')} />
            <SettingsPage back={{ href: '/settings', label: t('settings_hub.me') }} title={t('notifications.title')}>
                <div className="flex flex-col gap-2.5">
                    <SettingsGroup>
                        <SettingsRow
                            icon={Smartphone}
                            title={t('notifications.push_this_phone')}
                            sub={!supported ? t('notifications.push_unsupported') : needsInstall ? t('notifications.push_install_ios') : undefined}
                            right={
                                <SettingsSwitch
                                    on={on && !blocked}
                                    onToggle={() => void togglePush()}
                                    label={t('notifications.push_this_phone')}
                                    disabled={busy || blocked}
                                />
                            }
                        />
                        {on && !blocked && (
                            <SettingsRow
                                icon={Send}
                                title={t('notifications.push_test')}
                                value={sent ? t('notifications.push_test_sent') : undefined}
                                onClick={() => void test()}
                            />
                        )}
                    </SettingsGroup>
                    {!blocked && <p className="text-muted-foreground px-1 text-sm">{t('notifications.push_per_device')}</p>}
                </div>

                {categories.length > 0 && (
                    <div className="flex flex-col gap-2.5">
                        <SettingsGroup label={t('notifications.notify_me_about')}>
                            {categories.map((c) => (
                                <SettingsRow
                                    key={c.id}
                                    icon={icon(c)}
                                    title={label(c)}
                                    right={<SettingsSwitch on={prefs[c.id]} onToggle={() => togglePref(c.id)} label={label(c)} />}
                                />
                            ))}
                        </SettingsGroup>
                        {/* Today a muted category only stops the push; the in-app bell still lists it (PushAlert::via). */}
                        <p className="text-muted-foreground px-1 text-sm">{t('notifications.muted_note')}</p>
                    </div>
                )}
            </SettingsPage>
        </AppLayout>
    );
}
