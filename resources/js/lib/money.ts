import type { FocusEvent } from 'react';

import type { PickerOption } from '@/components/picker';

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

// Currency choices for a Picker: the code as the label (what the closed control shows) and the currency's
// name in the person's language (default: the page's <html lang>, the app locale) as the hint, so search finds "leu", "yen" or "franc" as well as "RON".
// Every supported currency is offered (Modules\Spending\Currencies), so search is how people find theirs.
export function currencyOptions(codes: string[], locale?: string): PickerOption[] {
    let names: Intl.DisplayNames | null = null;
    try {
        names = new Intl.DisplayNames([locale ?? (document.documentElement.lang || 'en')], { type: 'currency' });
    } catch {
        // very old browsers: codes only
    }
    return codes.map((c) => {
        const name = names?.of(c);
        return { value: c, label: c, hint: name && name !== c ? name : undefined };
    });
}
