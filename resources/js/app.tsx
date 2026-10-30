// Self-hosted fonts for every skin (bundled by Vite, no runtime CDN). All skins' fonts load so
// switching a theme never fetches at runtime; the active theme's --font-* tokens pick which apply.
import OfflineBanner from '@/components/offline-banner';
import { Picker } from '@/components/picker';
// Bricolage Grotesque: the brand display face of the onboarding (design-app.pen) and family monograms. Like
// every @font-face here, a weight only downloads when something on the page uses it.
import '@fontsource/bricolage-grotesque/700.css';
import '@fontsource/bricolage-grotesque/800.css';
import '@fontsource/geist-mono/400.css';
import '@fontsource/geist-mono/600.css';
// Geist Sans — the Minimal Mono skin.
import '@fontsource/geist-sans/400.css';
import '@fontsource/geist-sans/500.css';
import '@fontsource/geist-sans/600.css';
import '@fontsource/geist-sans/700.css';
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';
import '@fontsource/playfair-display/500.css';
import '@fontsource/playfair-display/600.css';
import '@fontsource/playfair-display/700.css';

import '../css/app.css';
import './echo';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import * as React from 'react';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { armCrashRecovery, ErrorBoundary } from './components/error-boundary';
import { initializeTheme, syncThemeColor } from './hooks/use-appearance';
import { identify, trackQueued } from './lib/analytics';
import { setFamilyTimezone } from './lib/date-groups';
import { setAppLocale, useT } from './lib/i18n';
import { listenForInstallPrompt } from './lib/install';
import { loadModules, registerSlot } from './lib/modules';
import { registerServiceWorker } from './lib/push';
import { startShoppingOutbox } from './lib/shopping-outbox';

declare global {
    const route: typeof routeFn;
    interface Window {
        Familywell: { React: typeof React; registerSlot: typeof registerSlot; Picker: typeof Picker; useT: typeof useT };
        __familywellModules?: string[];
    }
}

// Expose the shell runtime so pre-built module assets register UI without a bundler or a second React.
// Picker is exposed so prebuilt module frontends (modules/*/resources/dist) use the app's one dropdown
// instead of a native <select> (they can't import from the app bundle). useT so their strings follow the app language.
window.Familywell = { React, registerSlot, Picker, useT };
void loadModules(window.__familywellModules ?? []);

// Catch stale-chunk failures after a deploy and auto-reload once, so an installed PWA never gets
// stuck on a blank white screen.
armCrashRecovery();

// The browser's zone, for the server to fill in a family that has none yet (FillFamilyTimezone: admins only, only while
// the family's zone is empty). A cookie, so every request carries it with no extra call. A year, refreshed every load.
document.cookie = `fw_tz=${encodeURIComponent(Intl.DateTimeFormat().resolvedOptions().timeZone)}; path=/; max-age=31536000; samesite=lax`;

createInertiaApp({
    // No app-name suffix on page titles — just the page's own title.
    title: (title) => title,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        // Identify to Umami on the first page, then on every visit (the navigate listener below) so a
        // sign-in, sign-up or account switch re-identifies. Both: identify() dedupes repeat calls.
        setAppLocale(props.initialPage.props.locale as string | undefined);
        setFamilyTimezone(props.initialPage.props.timezone as string | null | undefined);
        identify(props.initialPage.props.analyticsId);
        trackQueued(props.initialPage.props.analyticsEvents);

        root.render(
            <ErrorBoundary>
                {/* Render prop so the offline banner sits next to every page and can read its translations. */}
                <App {...props}>
                    {({ Component, key, props: pageProps }) => (
                        <>
                            <Component key={key} {...pageProps} />
                            <OfflineBanner />
                        </>
                    )}
                </App>
            </ErrorBoundary>,
        );
    },
    progress: {
        color: '#2D5E3A',
    },
});

router.on('navigate', (event) => {
    // The onboarding is drawn in fixed brand colours, light only. In dark mode the page behind it (body) was dark,
    // and iOS Safari / Android paint their bars and the overscroll with that colour: a black band above a light
    // screen. data-brand makes the page itself paper (app.css), then the bar colour is re-read from it.
    document.documentElement.toggleAttribute('data-brand', event.detail.page.component.startsWith('onboarding/'));
    syncThemeColor();
    setAppLocale(event.detail.page.props.locale as string | undefined);
    setFamilyTimezone(event.detail.page.props.timezone as string | null | undefined);
    identify(event.detail.page.props.analyticsId);
    trackQueued(event.detail.page.props.analyticsEvents);
});

// This will set light / dark mode on load...
initializeTheme();

// Replay shopping ticks/adds made with no connection (lib/shopping-outbox), now and whenever we're back online.
startShoppingOutbox();

// Register the service worker so push notifications are received + clickable (no-op where unsupported).
void registerServiceWorker();
// Keep Chrome's one-tap install offer for the onboarding's install step (lib/install.ts).
listenForInstallPrompt();
