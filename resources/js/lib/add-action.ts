import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';

// Lets the current page claim the mobile nav's "+" button so it can be context-aware: on the shopping
// index it creates a list, inside a list it adds an item, etc. A page registers its actions while it is
// mounted (useAddActions / useAddAction); the nav runs the first on tap, falling back to the global quick-add
// sheet when no page owns the button (e.g. the dashboard). Single-owner: the most recently mounted page wins.
//
// Inside a module the Add sheet lists only that page's actions, in the page's order, and drops "Most used" and
// "Everything else" (the user, 2026-11-10: the other modules' shortcuts made it too crowded). A page with two kinds
// of thing (Spending: an expense, a list) passes both and puts the one its current view is about first.
// `label` is a translation key, `icon` its tile.
export type AddActionInfo = { label: string; icon: LucideIcon };
export type AddAction = AddActionInfo & { run: () => void };
let current: { current: AddAction[] } | null = null;

// Run the active page's first add action. Returns false when nothing is registered (or the page passed none)
// so the nav can fall back.
export function runAddAction(): boolean {
    const first = current?.current[0];
    if (first) {
        first.run();
        return true;
    }
    return false;
}

// What the current page's "+" can add (the Add sheet shows exactly these), or [] when no page claimed it.
export function addActions(): AddAction[] {
    return current?.current ?? [];
}

// Register `actions` as the "+" actions for as long as this component is mounted. The nav always reads the
// latest list (via a ref), so passing inline closures each render is fine. Pass [] while the page has nothing
// to add (e.g. an archived spending list) to hand "+" back to the global quick-add sheet.
export function useAddActions(actions: AddAction[]): void {
    const ref = useRef<AddAction[]>(actions);
    ref.current = actions;
    useEffect(() => {
        current = ref;
        return () => {
            if (current === ref) current = null;
        };
    }, []);
}

// The one-action form most pages use. Pass null as `fn` while the page has nothing to add.
export function useAddAction(fn: (() => void) | null, info: AddActionInfo): void {
    useAddActions(fn ? [{ ...info, run: fn }] : []);
}

// Opening Add from outside the phone's "+" (design 01c): the desktop top bar's "+ Add", the N key and the calendar's
// right-click menu. The Add sheet lives in MobileTabBar (rendered at every width; only its bar is phone-only), which
// registers the opener. `sheet`: always the Add sheet, never the page's own add (right-click › Add anything). `paste`:
// the sheet in Just say it with the clipboard read in (right-click › Paste). Neither: exactly what the phone's "+" does.
export type OpenAddOptions = { sheet?: boolean; paste?: boolean };
let opener: ((o: OpenAddOptions) => void) | null = null;
export function openAdd(o: OpenAddOptions = {}): void {
    opener?.(o);
}
export function setAddOpener(fn: ((o: OpenAddOptions) => void) | null): void {
    opener = fn;
}
