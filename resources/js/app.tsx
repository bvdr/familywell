// Self-hosted brand fonts (bundled by Vite, no runtime CDN).
import '@fontsource/geist-mono/400.css';
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
import { initializeTheme } from './hooks/use-appearance';
import { loadModules, registerSlot } from './lib/modules';

declare global {
    const route: typeof routeFn;
    interface Window {
        Familywell: { React: typeof React; registerSlot: typeof registerSlot };
        __familywellModules?: string[];
    }
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Expose the shell runtime so pre-built module assets register UI without a bundler or a second React.
window.Familywell = { React, registerSlot };
void loadModules(window.__familywellModules ?? []);

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#2D5E3A',
    },
});

// This will set light / dark mode on load...
initializeTheme();
