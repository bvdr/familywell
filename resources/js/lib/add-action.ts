import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';

// Lets the current page claim the mobile nav's "+" button so it can be context-aware: on the shopping
// index it creates a list, inside a list it adds an item, etc. A page registers a handler while it is
// mounted (useAddAction); the nav runs it on tap, falling back to the global quick-add sheet when no
// page owns the button (e.g. the dashboard). Single-owner: the most recently mounted page wins.
//
// The Add sheet names the page's action instead of a generic "Add on this page" (the user, 2026-11-05): `label` is a
// translation key, `icon` its tile, and `replaces` the sheet's own suggestion it stands for (the Shopping index's "New
// shopping list" IS the create.shopping shortcut), which the sheet then drops so another suggestion takes its place.
export type AddActionInfo = { label: string; icon: LucideIcon; replaces?: string };
type Registration = { fn: (() => void) | null; info: AddActionInfo };
let current: { current: Registration } | null = null;

// Run the active page's add action. Returns false when nothing is registered (or the page passed null)
// so the nav can fall back.
export function runAddAction(): boolean {
    const fn = current?.current.fn;
    if (fn) {
        fn();
        return true;
    }
    return false;
}

// What the current page's "+" does (the Add sheet shows it first), or null when no page claimed it.
export function addActionInfo(): AddActionInfo | null {
    return current?.current.fn ? current.current.info : null;
}

// Register `fn` as the "+" action for as long as this component is mounted. The nav always calls the
// latest `fn` (via a ref), so passing an inline closure each render is fine. Pass null while the page
// has nothing to add (e.g. an archived spending list) to hand "+" back to the global quick-add sheet.
export function useAddAction(fn: (() => void) | null, info: AddActionInfo): void {
    const ref = useRef<Registration>({ fn, info });
    ref.current = { fn, info };
    useEffect(() => {
        current = ref;
        return () => {
            if (current === ref) current = null;
        };
    }, []);
}
