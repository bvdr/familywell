import { AudioLines, ClipboardPaste, Loader2, Mic, Minus, Plus, X } from 'lucide-react';

import { useT } from '@/lib/i18n';

export type ReviewItem = { name: string; quantity: string; department: string; note: string; on_list?: boolean };
export type ReviewGroup = { slug: string; name: string; entries: { it: ReviewItem; idx: number }[] };

// The stepper's number: the quantity's leading number ("5", "2 kg" -> 2), empty = 1. Free text with no number ("a few")
// has none, so that row gets no stepper (its quantity stays as said).
const leadingNumber = (q: string): number | null => {
    const cur = q.trim();
    if (cur === '') return 1;
    const m = /^(\d+)/.exec(cur);
    return m ? Number(m[1]) : null;
};
// The same quantity with its leading number set to n ("2 kg" -> "3 kg"); empty becomes plain "n".
const withNumber = (q: string, n: number): string => (/^\d+/.test(q.trim()) ? q.trim().replace(/^\d+/, String(n)) : String(n));

// S3 "Here's what I heard" (design gNFrL, the Voice Sheet): a white sheet over the dimmed list. What you said in a quote
// box, the items by aisle in one card with a −/+ stepper each, anything already on the list flagged in yellow with a
// one-tap Bump, then Add more by voice / Redo and the sun "Add N items" button. The list page owns the items; this draws
// them and reports edits.
export function VoiceReview({
    heading,
    transcript,
    groups,
    count,
    paste,
    adding,
    existing,
    onName,
    onQuantity,
    onRemove,
    onBump,
    onClose,
    onAddMore,
    onRedo,
    onAdd,
}: {
    heading: string;
    transcript: string | null;
    groups: ReviewGroup[];
    count: number;
    paste: boolean;
    adding: boolean;
    // For an on_list item: the quantity already on the list and what Bump would make it (null = can't bump free text).
    existing: (it: ReviewItem) => { quantity: string | null; bump: string | null } | null;
    onName: (idx: number, name: string) => void;
    onQuantity: (idx: number, quantity: string) => void;
    onRemove: (idx: number) => void;
    onBump: (idx: number) => void;
    onClose: () => void;
    onAddMore: () => void;
    onRedo: () => void;
    onAdd: () => void;
}) {
    const t = useT();
    return (
        <div className="bg-card text-foreground absolute inset-x-0 bottom-0 flex max-h-[88dvh] flex-col rounded-t-[28px] shadow-[0_-8px_40px_rgba(18,17,0,0.12)]">
            <div className="flex justify-center pt-2.5">
                <span className="bg-border h-[5px] w-10 rounded-full" />
            </div>
            <div className="flex items-center justify-between gap-3 px-5 pt-3 pb-4">
                <h2 className="font-display text-2xl font-bold tracking-tight">{heading}</h2>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('shopping.close')}
                    className="bg-muted flex size-9 shrink-0 items-center justify-center rounded-full"
                >
                    <X className="size-[18px]" />
                </button>
            </div>

            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 pb-4">
                {transcript && (
                    <p className="bg-background flex gap-3 rounded-[20px] px-4 py-3.5 text-[15px] leading-relaxed italic">
                        <AudioLines className="mt-0.5 size-5 shrink-0" />
                        <span>“{transcript}”</span>
                    </p>
                )}
                {count === 0 ? (
                    <p className="text-muted-foreground py-8 text-center text-sm">{t('shopping.nothing_left')}</p>
                ) : (
                    <div className="border-border overflow-hidden rounded-[20px] border">
                        {groups.map((g) => (
                            <section key={g.slug}>
                                <h3 className="text-muted-foreground px-4 pt-3.5 pb-1 font-mono text-[11.5px] font-semibold tracking-[0.11em] uppercase">
                                    {g.name}
                                </h3>
                                {g.entries.map(({ it, idx }) => {
                                    const dup = it.on_list ? existing(it) : null;
                                    const n = leadingNumber(it.quantity);
                                    return (
                                        // A duplicate is the design's yellow row (fixed ink on sun-soft, in either theme).
                                        <div key={idx} className={`flex items-center gap-2 px-4 py-2.5 ${dup ? 'bg-sun-soft text-ink' : ''}`}>
                                            <div className="min-w-0 flex-1">
                                                {/* The name stays editable (a misheard word), styled as plain text. */}
                                                <input
                                                    value={it.name}
                                                    onChange={(e) => onName(idx, e.target.value)}
                                                    aria-label={t('shopping.item_name')}
                                                    className="w-full bg-transparent text-base font-semibold outline-none"
                                                />
                                                <p className={`truncate text-sm ${dup ? 'text-[#8A6A00]' : 'text-muted-foreground'}`}>
                                                    {dup
                                                        ? `${t('shopping.on_this_list')}${dup.quantity ? ` (${dup.quantity})` : ''}`
                                                        : [it.quantity || '1', it.note].filter(Boolean).join(' · ')}
                                                </p>
                                            </div>
                                            {dup?.bump ? (
                                                <button
                                                    type="button"
                                                    onClick={() => onBump(idx)}
                                                    className="bg-ink h-10 shrink-0 rounded-full px-4 text-[15px] font-semibold text-white"
                                                >
                                                    {t('shopping.bump_to', { n: dup.bump })}
                                                </button>
                                            ) : (
                                                n !== null && (
                                                    <span className="bg-muted flex h-10 shrink-0 items-center rounded-full">
                                                        <button
                                                            type="button"
                                                            onClick={() => onQuantity(idx, withNumber(it.quantity, Math.max(1, n - 1)))}
                                                            aria-label={t('shopping.decrease')}
                                                            className="flex size-10 items-center justify-center"
                                                        >
                                                            <Minus className="size-4" />
                                                        </button>
                                                        <span className="min-w-4 text-center font-semibold tabular-nums">{n}</span>
                                                        <button
                                                            type="button"
                                                            onClick={() => onQuantity(idx, withNumber(it.quantity, n + 1))}
                                                            aria-label={t('shopping.increase')}
                                                            className="flex size-10 items-center justify-center"
                                                        >
                                                            <Plus className="size-4" />
                                                        </button>
                                                    </span>
                                                )
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => onRemove(idx)}
                                                aria-label={t('shopping.remove')}
                                                className="flex size-9 shrink-0 items-center justify-center"
                                            >
                                                <X className="size-5" />
                                            </button>
                                        </div>
                                    );
                                })}
                            </section>
                        ))}
                    </div>
                )}
            </div>

            <div className="space-y-3 px-5 pt-1 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
                <div className="flex items-center justify-between px-1">
                    <button type="button" onClick={onAddMore} className="flex items-center gap-2.5 text-base font-semibold">
                        {paste ? <ClipboardPaste className="size-5" /> : <Mic className="size-5" />}
                        {t(paste ? 'shopping.add_more_by_paste' : 'shopping.add_more_by_voice')}
                    </button>
                    <button type="button" onClick={onRedo} className="text-muted-foreground text-base font-medium">
                        {t('shopping.redo')}
                    </button>
                </div>
                <button
                    type="button"
                    onClick={onAdd}
                    disabled={count === 0 || adding}
                    className="bg-sun text-ink border-ink flex h-14 w-full items-center justify-center gap-2 rounded-full border-2 text-lg font-bold disabled:opacity-50"
                >
                    {adding && <Loader2 className="size-5 animate-spin" />}
                    {t(count === 1 ? 'shopping.add_n_items_one' : 'shopping.add_n_items_other', { n: count })}
                </button>
            </div>
        </div>
    );
}
