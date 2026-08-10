import * as React from 'react';

// After a deploy, an already-open (installed) PWA can still hold the old HTML, which points at page
// chunks whose hashes changed, so a navigation fails to import and React renders nothing = blank
// white screen. This boundary catches that: chunk/load errors auto-reload once (pulling fresh HTML +
// assets), and any other render error shows a Reload button instead of a dead blank page.

const RELOAD_FLAG = 'fw:reloaded-after-crash';

export function isChunkError(message?: string | null): boolean {
    return /dynamically imported module|module script failed|ChunkLoadError|Failed to fetch|Load failed/i.test(message ?? '');
}

// Reload the page at most once (guarded by sessionStorage so a persistent failure can't loop).
export function reloadOnceOnChunkError(message?: string | null): boolean {
    if (isChunkError(message) && !sessionStorage.getItem(RELOAD_FLAG)) {
        sessionStorage.setItem(RELOAD_FLAG, '1');
        window.location.reload();
        return true;
    }
    return false;
}

// Once the app has been alive a few seconds without crashing, clear the guard so a *future* deploy
// can auto-reload again.
export function armCrashRecovery(): void {
    window.addEventListener('unhandledrejection', (e) => reloadOnceOnChunkError((e.reason as Error)?.message ?? String(e.reason)));
    window.addEventListener('error', (e) => reloadOnceOnChunkError(e.message));
    setTimeout(() => sessionStorage.removeItem(RELOAD_FLAG), 4000);
}

export class ErrorBoundary extends React.Component<{ children: React.ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error: unknown) {
        reloadOnceOnChunkError(error instanceof Error ? error.message : String(error));
    }

    render() {
        if (!this.state.failed) return this.props.children;
        return (
            <div className="bg-background flex min-h-dvh flex-col items-center justify-center gap-4 p-8 text-center">
                <p className="font-heading text-primary text-xl">Something went wrong</p>
                <p className="text-muted-foreground max-w-xs text-sm">The app hit a snag. Reloading usually fixes it.</p>
                <button
                    type="button"
                    onClick={() => {
                        sessionStorage.removeItem(RELOAD_FLAG);
                        window.location.reload();
                    }}
                    className="bg-primary text-primary-foreground rounded-full px-5 py-2 text-sm font-medium"
                >
                    Reload
                </button>
            </div>
        );
    }
}
