import type { FocusEvent } from 'react';

// Format integer minor units (cents) as a currency string. The backend never sends floats.
export function money(cents: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(cents / 100);
}

// Select the whole value when a money input gains focus, so a tap-and-type overwrites it.
// Deferred past the click's mouseup, which would otherwise drop the selection and place a caret.
export function selectAllOnFocus(e: FocusEvent<HTMLInputElement>): void {
    const el = e.currentTarget;
    setTimeout(() => el.select(), 0);
}
