import { useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

const applyTheme = (appearance: Appearance) => {
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());

    document.documentElement.classList.toggle('dark', isDark);
    // Native UI (form controls, scrollbars, Android's system-bar icons) follows the same light/dark.
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    syncThemeColor();
};

// Android (Chrome, installed PWA) paints the status bar with <meta name="theme-color">. It was a fixed
// brand green, so the bar stood out above the app (green over a dark page). Paint it with the page's own
// background instead, read from the computed style so it follows light/dark AND the active skin.
export const syncThemeColor = () => {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta && document.body) {
        meta.setAttribute('content', getComputedStyle(document.body).backgroundColor);
    }
};

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance;
    applyTheme(currentAppearance || 'system');
};

export function initializeTheme() {
    const savedAppearance = (localStorage.getItem('appearance') as Appearance) || 'system';

    applyTheme(savedAppearance);

    // Add the event listener for system theme changes...
    mediaQuery.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('system');

    const updateAppearance = (mode: Appearance) => {
        setAppearance(mode);
        localStorage.setItem('appearance', mode);
        applyTheme(mode);
    };

    useEffect(() => {
        const savedAppearance = localStorage.getItem('appearance') as Appearance | null;
        updateAppearance(savedAppearance || 'system');

        return () => mediaQuery.removeEventListener('change', handleSystemThemeChange);
    }, []);

    return { appearance, updateAppearance };
}

/**
 * Apply the skin CSS a page was rendered with (Appearance, Family > Members: the two places a skin is
 * picked). The skin lives in the server-rendered <style id="theme">, which an Inertia visit never
 * replaces, so a new pick used to show only after a full page load. Swapping the text in place updates
 * every token at once; then the Android status bar follows the new background.
 */
export function useSkinCss(css: string | undefined) {
    useEffect(() => {
        const style = document.getElementById('theme');
        if (css === undefined || !style || style.textContent === css) {
            return;
        }
        style.textContent = css;
        syncThemeColor();
    }, [css]);
}
