import { Head, usePage } from '@inertiajs/react';
import { Bell, BellOff, Send } from 'lucide-react';
import { useEffect, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { iosNeedsInstall, isSubscribed, pushSupported, sendTestPush, subscribeToPush, unsubscribeFromPush } from '@/lib/push';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notifications', href: '/settings/notifications' }];

export default function Notifications() {
    const vapidPublicKey = (usePage().props as { vapidPublicKey?: string }).vapidPublicKey ?? '';
    const [supported, setSupported] = useState(true);
    const [needsInstall, setNeedsInstall] = useState(false);
    const [on, setOn] = useState(false);
    const [busy, setBusy] = useState(false);
    const [testState, setTestState] = useState<'idle' | 'sent'>('idle');

    useEffect(() => {
        setSupported(pushSupported());
        setNeedsInstall(iosNeedsInstall());
        void isSubscribed().then(setOn);
    }, []);

    const toggle = async () => {
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
        setTestState('idle');
        if (await sendTestPush()) {
            setTestState('sent');
            setTimeout(() => setTestState('idle'), 4000);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notifications" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Notifications" description="Get a push on this device when things happen in your family." />

                    {!supported ? (
                        <div className="text-muted-foreground rounded-xl border border-dashed p-4 text-sm">
                            This browser doesn't support push notifications.
                        </div>
                    ) : needsInstall ? (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                            On iPhone/iPad, add FamilyWell to your Home Screen first (Share → Add to Home Screen), then open it from
                            there to turn on notifications.
                        </div>
                    ) : (
                        <>
                            <div className="bg-surface-card flex items-center gap-3 rounded-xl border p-4">
                                <span
                                    className={`flex size-10 shrink-0 items-center justify-center rounded-lg ${on ? 'bg-accent-subtle text-primary' : 'bg-surface text-muted-foreground'}`}
                                >
                                    {on ? <Bell className="size-5" /> : <BellOff className="size-5" />}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="text-primary text-sm font-medium">Push on this device</div>
                                    <div className="text-muted-foreground text-xs">{on ? 'Enabled' : 'Off'}</div>
                                </div>
                                <Button variant={on ? 'outline' : 'default'} onClick={toggle} disabled={busy}>
                                    {busy ? 'Working…' : on ? 'Turn off' : 'Turn on'}
                                </Button>
                            </div>

                            {on && (
                                <Button variant="outline" onClick={test} className="gap-2">
                                    <Send className="size-4" />
                                    {testState === 'sent' ? 'Sent — check your notifications' : 'Send a test notification'}
                                </Button>
                            )}

                            <p className="text-muted-foreground text-xs leading-relaxed">
                                Notifications are per-device: turn them on wherever you want to be alerted. You'll get a push when a task
                                is assigned to you or something is shared with you.
                            </p>
                        </>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
