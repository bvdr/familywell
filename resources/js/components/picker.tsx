import { Check, ChevronDown, Plus, Search, X } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { type LucideIcon, Store } from 'lucide-react';
import { useState } from 'react';

export type PickerOption = { value: string; label: string; icon?: LucideIcon };

// A dropdown-style picker that expands INLINE (no portal), so it works inside the vaul drawer the
// item editor lives in. Single or multi select, optional quick-search, optional create-on-the-fly.
// `selected` is always an array (single = at most one); the caller maps it back to its fields.
export function Picker({
    options,
    selected,
    onChange,
    multi = false,
    searchable = false,
    onCreate,
    placeholder = 'Select…',
    fallbackIcon = Store,
}: {
    options: PickerOption[];
    selected: string[];
    onChange: (next: string[]) => void;
    multi?: boolean;
    searchable?: boolean;
    onCreate?: (name: string) => void; // when set, offer "Add '<query>'" for an unknown search term
    placeholder?: string;
    fallbackIcon?: LucideIcon;
}) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    const byValue = new Map(options.map((o) => [o.value, o]));
    const labelFor = (v: string) => byValue.get(v)?.label ?? v;
    const iconFor = (v: string) => byValue.get(v)?.icon ?? fallbackIcon;

    const q = query.trim().toLowerCase();
    const shown = q ? options.filter((o) => o.label.toLowerCase().includes(q)) : options;
    const canCreate = !!onCreate && q.length > 0 && !options.some((o) => o.label.toLowerCase() === q);

    const toggle = (value: string) => {
        if (multi) {
            onChange(selected.includes(value) ? selected.filter((v) => v !== value) : [...selected, value]);
        } else {
            onChange([value]);
            setOpen(false);
            setQuery('');
        }
    };
    const create = () => {
        const name = query.trim();
        if (!name) return;
        onCreate?.(name);
        onChange(multi ? [...selected, name] : [name]);
        setQuery('');
        if (!multi) setOpen(false);
    };

    return (
        <div className="border-border overflow-hidden rounded-xl border">
            {/* Control: shows the current selection as chips (multi) or a single label. */}
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="bg-background flex w-full items-center gap-2 px-3 py-2.5 text-left"
            >
                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                    {selected.length === 0 ? (
                        <span className="text-muted-foreground text-sm">{placeholder}</span>
                    ) : multi ? (
                        selected.map((v) => {
                            const Icon = iconFor(v);
                            return (
                                <span key={v} className="bg-accent-subtle text-primary inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs">
                                    <Icon className="size-3" /> {labelFor(v)}
                                    <span
                                        role="button"
                                        tabIndex={0}
                                        aria-label={`Remove ${labelFor(v)}`}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onChange(selected.filter((x) => x !== v));
                                        }}
                                        className="hover:text-destructive"
                                    >
                                        <X className="size-3" />
                                    </span>
                                </span>
                            );
                        })
                    ) : (
                        (() => {
                            const Icon = iconFor(selected[0]);
                            return (
                                <span className="text-primary inline-flex items-center gap-1.5 text-sm font-medium">
                                    <Icon className="size-4" /> {labelFor(selected[0])}
                                </span>
                            );
                        })()
                    )}
                </div>
                <ChevronDown className={`text-muted-foreground size-4 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`} />
            </button>

            {open && (
                <div className="border-border bg-surface border-t">
                    {searchable && (
                        <div className="border-border flex items-center gap-2 border-b px-3 py-2">
                            <Search className="text-muted-foreground size-4 shrink-0" />
                            <input
                                autoFocus
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && canCreate && create()}
                                placeholder={t('common.search') + '…'}
                                className="text-primary w-full min-w-0 bg-transparent text-base outline-none md:text-sm"
                            />
                        </div>
                    )}
                    <div className="max-h-56 overflow-y-auto py-1">
                        {shown.map((o) => {
                            const Icon = o.icon ?? fallbackIcon;
                            const on = selected.includes(o.value);
                            return (
                                <button
                                    key={o.value}
                                    type="button"
                                    onClick={() => toggle(o.value)}
                                    className="hover:bg-surface-card flex w-full items-center gap-2.5 px-3 py-2 text-left"
                                >
                                    <Icon className="text-primary size-4 shrink-0" />
                                    <span className="text-primary min-w-0 flex-1 truncate text-sm">{o.label}</span>
                                    {on && <Check className="text-primary size-4 shrink-0" />}
                                </button>
                            );
                        })}
                        {canCreate && (
                            <button type="button" onClick={create} className="hover:bg-surface-card flex w-full items-center gap-2.5 px-3 py-2 text-left">
                                <Plus className="text-primary size-4 shrink-0" />
                                <span className="text-primary text-sm">
                                    Add “{query.trim()}”
                                </span>
                            </button>
                        )}
                        {shown.length === 0 && !canCreate && <div className="text-muted-foreground px-3 py-3 text-sm">{t('common.no_matches')}</div>}
                    </div>
                </div>
            )}
        </div>
    );
}
