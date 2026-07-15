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
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
