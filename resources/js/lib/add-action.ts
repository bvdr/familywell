import { useEffect, useRef } from 'react';

// Lets the current page claim the mobile nav's "+" button so it can be context-aware: on the shopping
// index it creates a list, inside a list it adds an item, etc. A page registers a handler while it is
// mounted (useAddAction); the nav runs it on tap, falling back to the global quick-add sheet when no
// page owns the button (e.g. the dashboard). Single-owner: the most recently mounted page wins.
let current: (() => void) | null = null;

// Run the active page's add action. Returns false when nothing is registered so the nav can fall back.
export function runAddAction(): boolean {
    if (current) {
        current();
        return true;
    }
    return false;
}

// Register `fn` as the "+" action for as long as this component is mounted. The handler is stable but
// always calls the latest `fn` (via a ref), so passing an inline closure each render is fine.
export function useAddAction(fn: () => void): void {
    const ref = useRef(fn);
    ref.current = fn;
    useEffect(() => {
        const handler = () => ref.current();
        current = handler;
        return () => {
            if (current === handler) current = null;
        };
    }, []);
}
