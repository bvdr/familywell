// Segmented control for tiny fixed choices (2-3 options: a role, the unit system, a tire season...).
// Anything bigger uses <Picker> (searchable dropdown). Same look as the app's existing inline segmented
// rows (project status, split mode). A11y: a radiogroup of radios with aria-checked; arrow keys move
// between options like a native radio group.
import { type KeyboardEvent } from 'react';

export type SegmentedOption<T extends string> = { value: T; label: string };

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
            className={`bg-surface grid gap-1 rounded-lg border p-1 ${disabled ? 'opacity-60' : ''}`}
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
                        className={`rounded-md px-2 py-1.5 text-xs font-medium transition-colors disabled:cursor-not-allowed ${on ? 'bg-accent-strong text-accent-strong-foreground' : 'text-muted-foreground'}`}
                    >
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}
