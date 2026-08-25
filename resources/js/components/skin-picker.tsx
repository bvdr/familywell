import { Check } from 'lucide-react';

export type Skin = { id: string; name: string; swatch: { bg: string; card: string; accent: string; ink: string } };

// A tiny live preview of a skin: page ground with a card, an ink title bar and the action accent dot.
function Swatch({ s }: { s: Skin['swatch'] }) {
    return (
        <span className="border-border flex size-12 shrink-0 flex-col justify-center gap-1 rounded-lg border p-1.5" style={{ background: s.bg }}>
            <span className="flex items-center gap-1 rounded-sm p-1" style={{ background: s.card }}>
                <span className="size-2 rounded-full" style={{ background: s.accent }} />
                <span className="h-1 flex-1 rounded-full" style={{ background: s.ink, opacity: 0.85 }} />
            </span>
            <span className="ml-1 h-1 w-2/3 rounded-full" style={{ background: s.ink, opacity: 0.4 }} />
        </span>
    );
}

// Selectable list of skins. When `inheritLabel` is set, a leading "inherit" option maps to value null.
export function SkinPicker({
    themes,
    value,
    onSelect,
    inheritLabel,
    inheritSwatch,
}: {
    themes: Skin[];
    value: string | null;
    onSelect: (id: string | null) => void;
    inheritLabel?: string;
    inheritSwatch?: Skin['swatch'];
}) {
    const Option = ({ id, name, swatch, sub }: { id: string | null; name: string; swatch?: Skin['swatch']; sub?: string }) => {
        const active = value === id;
        return (
            <button
                type="button"
                onClick={() => onSelect(id)}
                className={`flex items-center gap-3 rounded-xl border p-3 text-left transition-colors ${active ? 'border-primary bg-accent-subtle' : 'border-border'}`}
            >
                {swatch ? <Swatch s={swatch} /> : <span className="border-border bg-surface size-12 shrink-0 rounded-lg border" />}
                <span className="min-w-0 flex-1">
                    <span className="text-primary block text-sm font-medium">{name}</span>
                    {sub && <span className="text-muted-foreground block text-xs">{sub}</span>}
                </span>
                {active && <Check className="text-primary size-4 shrink-0" />}
            </button>
        );
    };

    return (
        <div className="flex flex-col gap-2">
            {inheritLabel && <Option id={null} name={inheritLabel} swatch={inheritSwatch} />}
            {themes.map((s) => (
                <Option key={s.id} id={s.id} name={s.name} swatch={s.swatch} />
            ))}
        </div>
    );
}
