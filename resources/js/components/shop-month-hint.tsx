import { useT } from '@/lib/i18n';
import { money } from '@/lib/money';

// Per-shop month-to-date map the spending pages ship alongside the form data (see MonthToDate::byShop):
// shop name => cents in `currency` (the same currency the "This month" widget reads in).
export type ShopTotals = { currency: string; totals: Record<string, number> };

// "This month at Lidl: 1,000 RON" under the shop picker on the add-expense sheets: what the family has
// ALREADY recorded at the shop currently in the form. A client-side lookup, so it follows the picker live
// (scan a Lidl receipt, or switch shop => the figure switches). Renders nothing for no shop, a shop with
// no spends this month, or an unknown/new name — never a bare "0".
export function ShopMonthHint({ shop, shopTotals }: { shop: string; shopTotals: ShopTotals }) {
    const t = useT();
    const total = shop ? shopTotals.totals[shop] : undefined;
    if (!total) return null;

    return (
        <p className="text-muted-foreground text-xs tabular-nums" data-testid="shop-month-hint">
            {t('spending.this_month_at', { shop, amount: money(total, shopTotals.currency) })}
        </p>
    );
}
