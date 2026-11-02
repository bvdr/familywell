import { router, usePage } from '@inertiajs/react';
import { ChevronRight, Smartphone, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { useT } from '@/lib/i18n';

// "Get the app" nudge (2026-11-02): FamilyWell used in a browser tab, not installed, gets a small card that opens
// the onboarding's install step and then its alerts step (OnboardingController::startSetup, users.setup_step).
// Dismissed, it comes back after 6 hours. The installed app never shows it; instead, when it opens with that setup
// still under way (the person just added it to their Home Screen), it goes straight to the step they were on.
const DISMISS_KEY = 'fw-install-nudge-dismissed-at';
const COME_BACK_MS = 6 * 60 * 60 * 1000; // the user's choice: 6 hours after a dismiss

export const isInstalled = () =>
    window.matchMedia('(display-mode: standalone)').matches || (window.navigator as { standalone?: boolean }).standalone === true;

// localStorage can be missing or throw (private mode, blocked storage): then the nudge simply shows.
const dismissedRecently = () => {
    try {
        return Date.now() - Number(localStorage.getItem(DISMISS_KEY) ?? 0) < COME_BACK_MS;
    } catch {
        return false;
    }
};

export function InstallNudge() {
    const t = useT();
    const { setupStep, installNudge } = usePage<{ setupStep?: string | null; installNudge?: boolean }>().props;
    const [show, setShow] = useState(false);

    useEffect(() => {
        if (isInstalled()) {
            // Opened from the Home Screen mid-setup: on to that step (install moves itself on to the alerts).
            if (setupStep && !window.location.pathname.startsWith('/onboarding/')) router.visit(`/onboarding/${setupStep}`);
            return;
        }
        if (!installNudge || dismissedRecently()) return;
        // Let the page settle before nudging.
        const id = window.setTimeout(() => setShow(true), 1500);
        return () => window.clearTimeout(id);
    }, [setupStep, installNudge]);

    if (!show) return null;

    const dismiss = () => {
        try {
            localStorage.setItem(DISMISS_KEY, String(Date.now()));
        } catch {
            // storage blocked: it just shows again next time
        }
        setShow(false);
    };

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-[calc(6rem+env(safe-area-inset-bottom))] z-40 flex justify-center px-4 md:inset-x-auto md:right-6 md:bottom-6">
            <div className="bg-primary text-primary-foreground pointer-events-auto flex w-full max-w-md items-center gap-1 rounded-2xl p-1.5 shadow-lg">
                <button
                    type="button"
                    onClick={() => router.post(route('setup.start'))}
                    className="flex min-w-0 flex-1 items-center gap-3 rounded-xl p-1.5 text-left"
                >
                    <span className="bg-accent-strong text-accent-strong-foreground flex size-10 shrink-0 items-center justify-center rounded-xl">
                        <Smartphone className="size-5" />
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block text-[15px] leading-snug font-semibold">
                            {t(setupStep ? 'install_nudge.title_continue' : 'install_nudge.title')}
                        </span>
                        <span className="block text-[13px] leading-snug opacity-75">{t('install_nudge.sub')}</span>
                    </span>
                    <ChevronRight className="size-5 shrink-0 opacity-75" />
                </button>
                <button
                    type="button"
                    onClick={dismiss}
                    aria-label={t('install_nudge.dismiss')}
                    className="flex size-10 shrink-0 items-center justify-center rounded-xl opacity-75 hover:opacity-100"
                >
                    <X className="size-4" />
                </button>
            </div>
        </div>
    );
}
