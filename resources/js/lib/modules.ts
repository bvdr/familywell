import * as React from 'react';

type Comp = React.ComponentType<Record<string, unknown>>;
const EMPTY: Comp[] = [];
const slots = new Map<string, Comp[]>();
const listeners = new Set<() => void>();

export function registerSlot(name: string, comp: Comp): void {
    slots.set(name, [...(slots.get(name) ?? []), comp]);
    listeners.forEach((l) => l());
}

function subscribe(l: () => void): () => void {
    listeners.add(l);
    return () => listeners.delete(l);
}

export function Slot({ name, fallback }: { name: string; fallback?: React.ReactNode }) {
    const comps = React.useSyncExternalStore(
        subscribe,
        () => slots.get(name) ?? EMPTY,
        () => slots.get(name) ?? EMPTY,
    );
    // No module has registered anything here (e.g. a family with no widget modules enabled):
    // render the caller's fallback so the surface isn't just an empty void.
    if (comps.length === 0) return fallback ?? null;
    return React.createElement(
        React.Fragment,
        null,
        comps.map((C, i) => React.createElement(C, { key: i })),
    );
}

export async function loadModules(urls: string[]): Promise<void> {
    for (const url of urls) {
        try {
            await import(/* @vite-ignore */ url);
        } catch (e) {
            console.error('module frontend failed to load', url, e);
        }
    }
}
