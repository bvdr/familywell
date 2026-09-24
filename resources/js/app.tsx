// Self-hosted fonts for every skin (bundled by Vite, no runtime CDN). All skins' fonts load so
// switching a theme never fetches at runtime; the active theme's --font-* tokens pick which apply.
import '@fontsource/geist-mono/400.css';
// Geist Sans — the Minimal Mono skin.
import '@fontsource/geist-sans/400.css';
import '@fontsource/geist-sans/500.css';
import '@fontsource/geist-sans/600.css';
import '@fontsource/geist-sans/700.css';
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/playfair-display/500.css';
import '@fontsource/playfair-display/600.css';
import '@fontsource/playfair-display/700.css';

import '../css/app.css';
import './echo';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import * as React from 'react';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { armCrashRecovery, ErrorBoundary } from './components/error-boundary';
import { initializeTheme } from './hooks/use-appearance';
import { loadModules, registerSlot } from './lib/modules';
import { registerServiceWorker } from './lib/push';

declare global {
    const route: typeof routeFn;
    interface Window {
        Familywell: { React: typeof React; registerSlot: typeof registerSlot };
        __familywellModules?: string[];
    }
}

// Expose the shell runtime so pre-built module assets register UI without a bundler or a second React.
window.Familywell = { React, registerSlot };
void loadModules(window.__familywellModules ?? []);

// Catch stale-chunk failures after a deploy and auto-reload once, so an installed PWA never gets
// stuck on a blank white screen.
armCrashRecovery();

createInertiaApp({
    // No app-name suffix on page titles — just the page's own title.
    title: (title) => title,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ErrorBoundary>
                <App {...props} />
            </ErrorBoundary>,
        );
    },
    progress: {
        color: '#2D5E3A',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// Register the service worker so push notifications are received + clickable (no-op where unsupported).
void registerServiceWorker();
