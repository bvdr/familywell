import { router } from '@inertiajs/react';
import { WifiOff } from 'lucide-react';
import { useEffect, useState } from 'react';

import { useT } from '@/lib/i18n';

// "You're offline": without it a tap with no connection did nothing at all (the optimistic UI updates, the request
// fails silently). Shows when the browser reports offline, or when an app request fails without any response
// (connected to Wi-Fi with no internet, which navigator.onLine misses). Hides on reconnect / the next good response.
// Rendered once next to every page (app.tsx), above the phone tab bar.
export default function OfflineBanner() {
    const t = useT();
    const [offline, setOffline] = useState(() => typeof navigator !== 'undefined' && !navigator.onLine);

    useEffect(() => {
        const down = () => setOffline(true);
        const up = () => setOffline(false);
        window.addEventListener('offline', down);
        window.addEventListener('online', up);
        // exception = no Inertia response at all; a server error still answers, so it isn't "offline".
        const offException = router.on('exception', (e) => {
            const err = e.detail.exception as { response?: unknown; code?: string } | undefined;
            if (!err?.response) down();
        });
        const offSuccess = router.on('success', up);
        return () => {
            window.removeEventListener('offline', down);
            window.removeEventListener('online', up);
            offException();
            offSuccess();
        };
    }, []);

    if (!offline) return null;

    return (
        <div
            role="status"
            aria-live="polite"
            className="bg-surface-dark text-text-inverse fixed inset-x-4 bottom-[calc(6rem+env(safe-area-inset-bottom))] z-[60] mx-auto flex max-w-md items-center gap-3 rounded-2xl px-4 py-3 text-sm shadow-lg md:bottom-6"
        >
            <WifiOff className="size-4 shrink-0" />
            <span className="min-w-0 flex-1">{t('common.offline')}</span>
        </div>
    );
}
