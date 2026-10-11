// A label (+ optional hint) with a switch on the right, for in-form booleans (e.g. "Count in monthly
// spending" on the expense forms). Same switch look as Settings > Notifications / Vehicles settings.
export function ToggleRow({ label, hint, on, onToggle }: { label: string; hint?: string; on: boolean; onToggle: () => void }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <span className="min-w-0">
                <span className="text-primary block text-sm font-medium">{label}</span>
                {hint && <span className="text-muted-foreground block text-xs">{hint}</span>}
            </span>
            <button
                type="button"
                role="switch"
                aria-checked={on}
                aria-label={label}
                onClick={onToggle}
                className={`relative h-6 w-10 shrink-0 rounded-full transition-colors ${on ? 'bg-accent-strong' : 'bg-border'}`}
            >
                <span className={`absolute top-0.5 size-5 rounded-full bg-white shadow-sm transition-all ${on ? 'left-[18px]' : 'left-0.5'}`} />
            </button>
        </div>
    );
}
