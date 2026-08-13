// v1 ships light-only. Dark mode was Laravel starter-kit scaffolding that fought the theme.json
// token system: theme.json tokens (--bg, --surface-card, --text-primary, ...) only ever get light
// values, so toggling `.dark` produced white cards with pale-on-white text, an unreadable UI. Worse,
// the old default was `system`, so a visitor whose OS was dark hit that broken state without ever
// choosing it. Until a real dark theme exists (a dark theme.json, the intended path per ShareTheme),
// force light and never add `.dark`. Kept as a function so app.tsx's call site stays unchanged and
// any stale `.dark`/localStorage from a previous build is cleared on boot.
export function initializeTheme() {
    document.documentElement.classList.remove('dark');
    localStorage.removeItem('appearance');
}
