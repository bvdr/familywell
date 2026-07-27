import { useState } from 'react';

// Optimistic overlay for list-item state that syncs to the server in the background.
//
// The problem: an Inertia `router.patch`/`delete` only updates the UI once the server responds and
// the page props reload, so tapping a checkbox feels laggy. This hook lets a page apply the change
// locally *now* (instant tap feedback), fire the request in the background, then clear the override
// once it settles — at which point the reloaded props are authoritative again. On error the override
// is cleared too, so the UI snaps back to the confirmed server value instead of lying.
//
// Usage (boolean "bought" toggle):
//   const bought = useOptimistic<boolean>();
//   const isBought = (i) => bought.get(i.id, i.bought);        // render THIS everywhere, not i.bought
//   const toggle = (i) => {
//     bought.set(i.id, !isBought(i));
//     router.patch(url, {}, { preserveScroll: true, preserveState: true,
//       onSuccess: () => bought.clear(i.id), onError: () => bought.clear(i.id) });
//   };
//
// preserveState:true keeps these overrides alive across the prop reload the request triggers.
export function useOptimistic<V>() {
    const [overrides, setOverrides] = useState<Record<string, V>>({});

    return {
        // The value to render: the pending override if one exists, else the server value.
        get: (id: string | number, serverValue: V): V => (String(id) in overrides ? overrides[String(id)] : serverValue),
        has: (id: string | number): boolean => String(id) in overrides,
        set: (id: string | number, value: V) => setOverrides((o) => ({ ...o, [String(id)]: value })),
        clear: (id: string | number) =>
            setOverrides((o) => {
                const next = { ...o };
                delete next[String(id)];
                return next;
            }),
    };
}
