// Segmented control for tiny fixed choices (2-3 options: a role, the unit system, a tire season...).
// Anything bigger uses <Picker> (searchable dropdown). The rounded pill look of Shopping's Lists / Shops tabs
// (the user preferred it over the old square-ish one, 2026-10-28), now everywhere a Segmented is used. A11y: a radiogroup of radios with aria-checked; arrow keys move
// between options like a native radio group.
import { type ComponentType, type KeyboardEvent } from 'react';

// `icon` is optional: the share sheet's "Who can access" switch shows one per option.
export type SegmentedOption<T extends string> = { value: T; label: string; icon?: ComponentType<{ className?: string }> };

export function Segmented<T extends string>({
    options,
    value,
    onChange,
    ariaLabel,
    disabled = false,
}: {
    options: SegmentedOption<T>[];
    value: T;
    onChange: (next: T) => void;
    ariaLabel?: string;
    disabled?: boolean;
}) {
    const move = (e: KeyboardEvent<HTMLDivElement>) => {
        const step = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0;
        if (!step || disabled) return;
        e.preventDefault();
        const i = options.findIndex((o) => o.value === value);
        onChange(options[(i + step + options.length) % options.length].value);
    };

    return (
        <div
            role="radiogroup"
            aria-label={ariaLabel}
            onKeyDown={move}
            className={`bg-surface border-border grid gap-1 rounded-full border p-1 ${disabled ? 'opacity-60' : ''}`}
            style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }}
        >
            {options.map((o, i) => {
                const on = o.value === value;
                // Roving tabindex: the checked option is the Tab stop, or the first one when none is checked.
                const tabStop = on || (i === 0 && !options.some((x) => x.value === value));
                return (
                    <button
                        key={o.value}
                        type="button"
                        role="radio"
                        aria-checked={on}
                        tabIndex={tabStop ? 0 : -1}
                        disabled={disabled}
                        onClick={() => onChange(o.value)}
                        className={`inline-flex min-w-0 items-center justify-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed ${on ? 'bg-accent-strong text-accent-strong-foreground' : 'text-primary'}`}
                    >
                        {o.icon && <o.icon className="size-4 shrink-0" />}
                        <span className="truncate">{o.label}</span>
                    </button>
                );
            })}
        </div>
    );
}
