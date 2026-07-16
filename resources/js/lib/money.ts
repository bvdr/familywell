// Format integer minor units (cents) as a currency string. The backend never sends floats.
export function money(cents: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(cents / 100);
}
