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

export function Slot({ name }: { name: string }) {
    const comps = React.useSyncExternalStore(
        subscribe,
        () => slots.get(name) ?? EMPTY,
        () => slots.get(name) ?? EMPTY,
    );
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
