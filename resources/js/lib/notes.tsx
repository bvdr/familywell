import { Fragment, type ReactNode } from 'react';

// Full-card tint per note color (mockup shows softly tinted cards). `default` is the plain surface.
export const NOTE_COLORS: Record<string, string> = {
    default: 'bg-surface-card border-border',
    yellow: 'bg-amber-50 border-amber-200/70 dark:bg-amber-950/30 dark:border-amber-900/50',
    blue: 'bg-blue-50 border-blue-200/70 dark:bg-blue-950/30 dark:border-blue-900/50',
    green: 'bg-emerald-50 border-emerald-200/70 dark:bg-emerald-950/30 dark:border-emerald-900/50',
    pink: 'bg-pink-50 border-pink-200/70 dark:bg-pink-950/30 dark:border-pink-900/50',
    purple: 'bg-purple-50 border-purple-200/70 dark:bg-purple-950/30 dark:border-purple-900/50',
};
export const noteColor = (slug: string) => NOTE_COLORS[slug] ?? NOTE_COLORS.default;
export const NOTE_COLOR_KEYS = Object.keys(NOTE_COLORS);

// Small preview dot for the color picker (uses the border/bg tint at swatch size).
export const NOTE_SWATCHES: Record<string, string> = {
    default: 'bg-surface border-border',
    yellow: 'bg-amber-200 border-amber-300',
    blue: 'bg-blue-200 border-blue-300',
    green: 'bg-emerald-200 border-emerald-300',
    pink: 'bg-pink-200 border-pink-300',
    purple: 'bg-purple-200 border-purple-300',
};

const CHECK_RE = /^- \[( |x|X)\] (.*)$/;

// A markdown-lite line is one of: heading (#/##/###), checkbox (- [ ] / - [x]), bullet (- / *),
// blank, or plain paragraph. We keep the model this small on purpose: the editor is a plain textarea,
// this is only the rendered read view.
type Line =
    | { kind: 'h'; level: number; text: string; index: number }
    | { kind: 'check'; done: boolean; text: string; index: number }
    | { kind: 'bullet'; text: string; index: number }
    | { kind: 'blank'; index: number }
    | { kind: 'p'; text: string; index: number };

function parseLine(raw: string, index: number): Line {
    if (raw.trim() === '') return { kind: 'blank', index };
    const h = raw.match(/^(#{1,3}) (.*)$/);
    if (h) return { kind: 'h', level: h[1].length, text: h[2], index };
    const c = raw.match(CHECK_RE);
    if (c) return { kind: 'check', done: c[1].toLowerCase() === 'x', text: c[2], index };
    const b = raw.match(/^[-*] (.*)$/);
    if (b) return { kind: 'bullet', text: b[1], index };
    return { kind: 'p', text: raw, index };
}

// Inline: **bold** only. Everything else renders literally.
function inline(text: string): ReactNode {
    const parts = text.split(/(\*\*[^*]+\*\*)/g);
    return parts.map((p, i) =>
        p.startsWith('**') && p.endsWith('**') && p.length > 4 ? (
            <strong key={i} className="font-semibold">
                {p.slice(2, -2)}
            </strong>
        ) : (
            <Fragment key={i}>{p}</Fragment>
        ),
    );
}

// Plain-text snippet for cards: drop markers, collapse whitespace, trim to ~length chars.
export function snippet(body: string, length = 140): string {
    const text = body
        .split('\n')
        .map((l) => l.replace(/^(#{1,3}) /, '').replace(CHECK_RE, '$2').replace(/^[-*] /, ''))
        .join(' ')
        .replace(/\*\*/g, '')
        .replace(/\s+/g, ' ')
        .trim();
    return text.length > length ? text.slice(0, length).trimEnd() + '…' : text;
}

// Toggle the checkbox on `line` of `body` between [ ] and [x]. Returns the new body (or the same
// string if that line is not a checkbox). Used by the read-view tappable checkboxes.
export function toggleCheckbox(body: string, line: number): string {
    const lines = body.split('\n');
    const m = lines[line]?.match(CHECK_RE);
    if (!m) return body;
    const done = m[1].toLowerCase() === 'x';
    lines[line] = `- [${done ? ' ' : 'x'}] ${m[2]}`;
    return lines.join('\n');
}

// Rendered read view. Tapping a checkbox calls onToggle(lineIndex); pass undefined to render inert.
export function NoteBody({ body, onToggle }: { body: string; onToggle?: (line: number) => void }) {
    if (body.trim() === '') return <p className="text-muted-foreground text-sm italic">Empty note. Tap to edit.</p>;
    const lines = body.split('\n').map(parseLine);

    return (
        <div className="flex flex-col gap-1 text-[15px] leading-relaxed">
            {lines.map((l) => {
                if (l.kind === 'blank') return <div key={l.index} className="h-2" />;
                if (l.kind === 'h') {
                    const size = l.level === 1 ? 'text-xl font-semibold' : l.level === 2 ? 'text-lg font-semibold' : 'text-base font-semibold';
                    return (
                        <p key={l.index} className={`text-primary ${size} mt-1`}>
                            {inline(l.text)}
                        </p>
                    );
                }
                if (l.kind === 'check') {
                    return (
                        <button
                            key={l.index}
                            type="button"
                            onClick={() => onToggle?.(l.index)}
                            className="flex items-start gap-2 text-left"
                        >
                            <span
                                className={`mt-0.5 flex size-4.5 shrink-0 items-center justify-center rounded border transition-colors ${
                                    l.done ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/50'
                                }`}
                            >
                                {l.done && <span className="text-[11px] leading-none">✓</span>}
                            </span>
                            <span className={l.done ? 'text-muted-foreground line-through' : ''}>{inline(l.text)}</span>
                        </button>
                    );
                }
                if (l.kind === 'bullet') {
                    return (
                        <div key={l.index} className="flex items-start gap-2">
                            <span className="text-muted-foreground mt-2 size-1.5 shrink-0 rounded-full bg-current" />
                            <span>{inline(l.text)}</span>
                        </div>
                    );
                }
                return <p key={l.index}>{inline(l.text)}</p>;
            })}
        </div>
    );
}
