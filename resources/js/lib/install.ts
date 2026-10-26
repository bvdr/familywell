// Installing FamilyWell as an app (PWA). Chrome/Edge/Android fire `beforeinstallprompt` once, early, often before
// the onboarding's install step is even on screen (it's an SPA), so app.tsx starts listening at boot and the event
// is kept here for whoever asks later. Not preventDefault()-ed: Chrome's own install hint stays as it was.

type InstallPrompt = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> };

let deferred: InstallPrompt | null = null;
const listeners = new Set<() => void>();

export function listenForInstallPrompt(): void {
    if (typeof window === 'undefined') return;
    window.addEventListener('beforeinstallprompt', (e) => {
        deferred = e as InstallPrompt;
        listeners.forEach((l) => l());
    });
}

/** True when the browser offered one-tap install (Android Chrome, desktop Chrome/Edge) and it's still unused. */
export const canPromptInstall = (): boolean => deferred !== null;

/** Re-render on the event, for pages mounted before it fired. Returns the unsubscribe. */
export function onInstallPromptChange(l: () => void): () => void {
    listeners.add(l);
    return () => listeners.delete(l);
}

/** Show the browser's install dialog. True when the person accepted. */
export async function promptInstall(): Promise<boolean> {
    if (!deferred) return false;
    const e = deferred;
    deferred = null; // a prompt event can only be used once
    await e.prompt();
    return (await e.userChoice).outcome === 'accepted';
}

/** Running as the installed app (Home Screen / dock icon), not in a browser tab. */
export function isStandalone(): boolean {
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true;
}
