import { useT } from '@/lib/i18n';
import { Check, ChevronDown, Plus, Search, Store, X } from 'lucide-react';
import { type ComponentType, useEffect, useId, useRef, useState } from 'react';

// `icon` is any component taking className (a lucide icon, or a wrapper like <CategoryIcon> that adds its
// own colour), so callers can pass per-option renderers, not just static lucide components.
export type PickerIcon = ComponentType<{ className?: string }>;
export type PickerOption = { value: string; label: string; icon?: PickerIcon };

// THE app dropdown: every select in the app uses this (one look, search on top), except tiny fixed sets
// (2-3 options), which use a segmented control. It expands INLINE (no portal), so it works inside the
// vaul drawers the editors live in. Single or multi select, quick-search (on by default), optional
// create-on-the-fly. `selected` is always an array (single = at most one); the caller maps it back.
// A11y: the control is a button with aria-expanded; the options are a listbox of options with
// aria-selected; Escape closes and returns focus to the control. Pass `id` to tie a <Label htmlFor>.
export function Picker({
    options,
    selected,
    onChange,
    multi = false,
    searchable = true,
    onCreate,
    placeholder,
    fallbackIcon = Store,
    id,
    ariaLabel,
    disabled = false,
}: {
    options: PickerOption[];
    selected: string[];
    onChange: (next: string[]) => void;
    multi?: boolean;
    searchable?: boolean;
    onCreate?: (name: string) => void; // when set, offer "Add '<query>'" for an unknown search term
    placeholder?: string;
    fallbackIcon?: PickerIcon;
    id?: string; // set on the control, so a <Label htmlFor={id}> names it
    ariaLabel?: string; // accessible name when there is no visible <Label htmlFor>
    disabled?: boolean;
}) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const listId = useId();
    const rootRef = useRef<HTMLDivElement>(null);
    const controlRef = useRef<HTMLButtonElement>(null);
    const close = () => {
        setOpen(false);
        setQuery('');
        controlRef.current?.focus();
    };
    // Escape closes the open list, and ONLY the list. The drawers/dialogs around a Picker (Radix/vaul)
    // listen for Escape on `document` in the capture phase, which runs before any React handler here, so
    // a React onKeyDown + stopPropagation is too late and the whole drawer closed. A window-level capture
    // listener runs first; it only acts while this list is open and focus is inside this Picker.
    useEffect(() => {
        if (!open) return;
        const onEscape = (e: globalThis.KeyboardEvent) => {
            if (e.key !== 'Escape' || !rootRef.current?.contains(document.activeElement)) return;
            e.stopPropagation();
            e.preventDefault();
            setOpen(false);
            setQuery('');
            controlRef.current?.focus();
        };
        window.addEventListener('keydown', onEscape, true);
        return () => window.removeEventListener('keydown', onEscape, true);
    }, [open]);

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
            close();
        }
    };
    const create = () => {
        const name = query.trim();
        if (!name) return;
        onCreate?.(name);
        onChange(multi ? [...selected, name] : [name]);
        setQuery('');
        if (!multi) close();
    };

    return (
        <div ref={rootRef} className={`border-border overflow-hidden rounded-xl border ${disabled ? 'opacity-60' : ''}`}>
            {/* Control: shows the current selection as chips (multi) or a single label. */}
            <button
                ref={controlRef}
                id={id}
                type="button"
                disabled={disabled}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                aria-label={ariaLabel}
                onClick={() => setOpen((v) => !v)}
                className="bg-background flex w-full items-center gap-2 px-3 py-2.5 text-left disabled:cursor-not-allowed"
            >
                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                    {selected.length === 0 ? (
                        <span className="text-muted-foreground text-sm">{placeholder ?? t('common.select')}</span>
                    ) : multi ? (
                        selected.map((v) => {
                            const Icon = iconFor(v);
                            return (
                                <span
                                    key={v}
                                    className="bg-accent-subtle text-primary inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs"
                                >
                                    <Icon className="size-3" /> {labelFor(v)}
                                    <span
                                        role="button"
                                        tabIndex={0}
                                        aria-label={t('common.remove_named', { name: labelFor(v) })}
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
                                aria-label={t('common.search')}
                                aria-controls={listId}
                                className="text-primary w-full min-w-0 bg-transparent text-base outline-none md:text-sm"
                            />
                        </div>
                    )}
                    <div id={listId} role="listbox" aria-multiselectable={multi || undefined} className="max-h-56 overflow-y-auto py-1">
                        {shown.map((o) => {
                            const Icon = o.icon ?? fallbackIcon;
                            const on = selected.includes(o.value);
                            return (
                                <button
                                    key={o.value}
                                    type="button"
                                    role="option"
                                    aria-selected={on}
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
                            <button
                                type="button"
                                onClick={create}
                                className="hover:bg-surface-card flex w-full items-center gap-2.5 px-3 py-2 text-left"
                            >
                                <Plus className="text-primary size-4 shrink-0" />
                                <span className="text-primary text-sm">{t('common.add_named', { name: query.trim() })}</span>
                            </button>
                        )}
                        {shown.length === 0 && !canCreate && <div className="text-muted-foreground px-3 py-3 text-sm">{t('common.no_matches')}</div>}
                    </div>
                </div>
            )}
        </div>
    );
}
