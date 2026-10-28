import { usePage } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';

// The modules pinned to the phone tab bar (design XMkKm: Home · three pins · More). One source for every screen that
// shows or changes them (the bar, More's long-press, All modules' pin toggles), so they can't drift apart. Saved per
// device in localStorage, like before the redesign; Home is always first and never counts as a pin.
// Not the account `pins` (users.pinned_modules): those are Home's module tiles ("Pin your top 4"), a separate choice.
const KEY = 'familywell:navPins';
export const MAX_NAV_PINS = 3;
const DEFAULTS = ['shopping', 'tasks', 'calendar', 'spending', 'projects'];

const listeners = new Set<() => void>();
const read = (): string | null => {
    try {
        return localStorage.getItem(KEY);
    } catch {
        return null; // private mode / blocked storage: defaults
    }
};
const subscribe = (fn: () => void) => {
    listeners.add(fn);
    window.addEventListener('storage', fn); // another tab changed them
    return () => {
        listeners.delete(fn);
        window.removeEventListener('storage', fn);
    };
};

/** Pinned module ids (enabled only, at most three) and a toggle. `enabled` = the family's modules that are on. */
export function useNavPins(enabled: string[]) {
    const { pins: account } = usePage().props as { pins?: string[] | null };
    const raw = useSyncExternalStore(subscribe, read, () => null);
    let saved: string[] | null = null;
    try {
        const parsed = JSON.parse(raw ?? 'null');
        if (Array.isArray(parsed)) saved = parsed;
    } catch {
        /* corrupt value: defaults */
    }
    // Older saves may hold 'dashboard' or settings pages: only modules still on count. Never saved on this device:
    // the modules picked in the onboarding (on the account, so every device starts the same), else the default order.
    const pick = (keys: string[] | null | undefined) => (keys ?? []).filter((k) => enabled.includes(k)).slice(0, MAX_NAV_PINS);
    const pinned = saved !== null ? pick(saved) : pick(account).length ? pick(account) : pick(DEFAULTS);

    const toggle = (key: string) => {
        const next = pinned.includes(key) ? pinned.filter((k) => k !== key) : pinned.length < MAX_NAV_PINS ? [...pinned, key] : pinned;
        try {
            localStorage.setItem(KEY, JSON.stringify(next));
        } catch {
            /* blocked storage: nothing to keep */
        }
        listeners.forEach((fn) => fn());
    };

    return { pinned, toggle, full: pinned.length >= MAX_NAV_PINS };
}
