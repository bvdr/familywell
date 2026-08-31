import { LucideIcon } from '@/components/lucide-icon';
import { useT } from '@/lib/i18n';
import { fmtNumber, STATE_META, stateLabel, unitLabel, type InvCategory, type InvItem, type InvState } from '@/lib/inventory';

// A thin quantity bar (e.g. Eggs 4/12). The fill grows to `item.bar` (0..1), coloured by state; an
// item with no measurable target (or unknown quantity) shows a faint empty track so rows stay aligned.
export function QtyBar({ item, className = '' }: { item: Pick<InvItem, 'bar' | 'state'>; className?: string }) {
    const pct = item.bar === null ? 0 : Math.round(item.bar * 100);
    return (
        <div className={`bg-surface h-1.5 w-full overflow-hidden rounded-full ${className}`}>
            <div
                className="h-full rounded-full transition-[width] duration-500 ease-out motion-reduce:transition-none"
                style={{ width: `${pct}%`, background: STATE_META[item.state].color }}
            />
        </div>
    );
}

// A small state pill: a coloured dot + the localised state label (Good / Low / Empty / …). Colour
// comes from the theme tokens via STATE_META, so it tracks the active skin.
export function StateBadge({ state, className = '' }: { state: InvState; className?: string }) {
    const t = useT();
    const color = STATE_META[state].color;
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium ${className}`}
            style={{ color, borderColor: color }}
        >
            <span className="size-1.5 rounded-full" style={{ background: color }} />
            {stateLabel(t, state)}
        </span>
    );
}

// The little coloured square that carries a category's icon — same idiom as the modules/dashboard tiles.
export function CategoryTile({ category, size = 'md' }: { category?: InvCategory; size?: 'sm' | 'md' }) {
    const sq = size === 'sm' ? 'size-8' : 'size-10';
    const ic = size === 'sm' ? 'size-4' : 'size-5';
    // Tint the tile with the category colour at low opacity; the icon takes the full colour.
    const color = category?.color ?? 'var(--muted-foreground)';
    return (
        <span
            className={`${sq} flex shrink-0 items-center justify-center rounded-lg`}
            style={{ background: `color-mix(in srgb, ${color} 16%, transparent)`, color }}
        >
            <LucideIcon name={category?.icon ?? 'package'} className={ic} />
        </span>
    );
}

// The quantity, rendered as "4 / 12 pcs" when there's a target, "4 pcs" otherwise, and "Unknown" when
// the quantity is null. Numbers are tabular so columns line up.
export function QtyText({ item }: { item: Pick<InvItem, 'quantity' | 'unit' | 'targetQuantity'> }) {
    const t = useT();
    if (item.quantity === null) {
        return <span className="text-muted-foreground">{t('inventory.qty_unknown')}</span>;
    }
    return (
        <span className="tabular-nums">
            {fmtNumber(item.quantity)}
            {item.targetQuantity ? <span className="text-muted-foreground"> {t('inventory.of')} {fmtNumber(item.targetQuantity)}</span> : null}{' '}
            <span className="text-muted-foreground">{unitLabel(t, item.unit)}</span>
        </span>
    );
}
