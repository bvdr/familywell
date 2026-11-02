// Pre-built module frontend (shell runtime, no bundler, no second React). Registers into the task
// edit screen's slots: an interval picker in the editor (task.editor.fields) and a repeat badge on
// the row (task.row.badges). `ctx` is spread as props by <Slot>; onPatch persists via the tasks
// endpoint (the backend `task.saved` hook stores it).
//
// Rhythms (design j6QDy4 T4), kept in sync with RecurringRule::INTERVALS: the presets below, or a custom
// "N:day|week|month" (every N days / weeks / months). "Count next from" (recurrence_from): 'due' (the next one
// follows the due date) or 'done' (it follows the day it got ticked).
const { React, registerSlot, useT, appLocale } = window.Familywell;
const h = React.createElement;

const PRESETS = ['none', 'daily', 'weekdays', 'weekly', 'monthly', 'quarterly', 'yearly'];
const CUSTOM = /^(\d{1,3}):(day|week|month)$/;

// "Every 3 months", "Every 2 weeks", "Never": what a rhythm is called (the row's value, the badge).
function rhythmLabel(t, v) {
    if (!v || v === 'none') return t('recurring_todos.none');
    const m = CUSTOM.exec(v);
    if (m) return t('recurring_todos.every_' + m[2], { count: Number(m[1]) });
    return t('recurring_todos.every_' + v);
}

// One rhythm on from a date, like RecurringRule::advance (no-overflow months: Jan 31 + 1 month = Feb 28).
function addMonths(d, n) {
    const r = new Date(d.getFullYear(), d.getMonth() + n, 1);
    r.setDate(Math.min(d.getDate(), new Date(r.getFullYear(), r.getMonth() + 1, 0).getDate()));
    return r;
}
function advance(d, v) {
    const m = CUSTOM.exec(v);
    const days = (n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
    if (m) return m[2] === 'day' ? days(Number(m[1])) : m[2] === 'week' ? days(7 * Number(m[1])) : addMonths(d, Number(m[1]));
    switch (v) {
        case 'daily':
            return days(1);
        case 'weekdays': {
            let r = days(1);
            while (r.getDay() === 0 || r.getDay() === 6) r = new Date(r.getFullYear(), r.getMonth(), r.getDate() + 1);
            return r;
        }
        case 'weekly':
            return days(7);
        case 'monthly':
            return addMonths(d, 1);
        case 'quarterly':
            return addMonths(d, 3);
        case 'yearly':
            return addMonths(d, 12);
        default:
            return days(7);
    }
}
const parseDay = (s) => {
    const [y, mo, da] = s.split('-').map(Number);
    return new Date(y, mo - 1, da);
};
const fmt = (d, o) => d.toLocaleDateString(appLocale(), o);
// 19 -> "19th" in English; other languages write the day in their own sentence ("am 19.", "le 19").
function nth(n) {
    if (!appLocale().startsWith('en')) return String(n);
    const s = { one: 'st', two: 'nd', few: 'rd', other: 'th' }[new Intl.PluralRules('en', { type: 'ordinal' }).select(n)];
    return n + s;
}

const ICON = (cls) =>
    h(
        'svg',
        {
            viewBox: '0 0 24 24',
            fill: 'none',
            stroke: 'currentColor',
            strokeWidth: 2,
            strokeLinecap: 'round',
            strokeLinejoin: 'round',
            className: cls,
            'aria-hidden': true,
        },
        h('path', { d: 'm2 9 3-3 3 3' }),
        h('path', { d: 'M13 18H7a2 2 0 0 1-2-2V6' }),
        h('path', { d: 'm22 15-3 3-3-3' }),
        h('path', { d: 'M11 6h6a2 2 0 0 1 2 2v10' }),
    );
const CHEVRON = h(
    'svg',
    {
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 2,
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        className: 'text-muted-foreground size-5 shrink-0',
        'aria-hidden': true,
    },
    h('path', { d: 'm9 18 6-6-6-6' }),
);
const CHECK = h(
    'span',
    { className: 'bg-primary text-primary-foreground flex size-6 shrink-0 items-center justify-center rounded-full' },
    h(
        'svg',
        {
            viewBox: '0 0 24 24',
            fill: 'none',
            stroke: 'currentColor',
            strokeWidth: 3,
            strokeLinecap: 'round',
            strokeLinejoin: 'round',
            className: 'size-3.5',
            'aria-hidden': true,
        },
        h('path', { d: 'M20 6 9 17l-5-5' }),
    ),
);

// The repeat picker (T4), pushed into the task sheet: plain-language presets with a hint ("on Mondays", "on the
// 19th"), the current one ticked with when the next one lands, Custom… (every N days / weeks / months), and
// "Count next from" with a sentence saying what it changes. Reads and writes the sheet's draft (task / onPatch).
function RepeatPicker({ task, onPatch }) {
    const t = useT();
    const val = task.recurrence || 'none';
    const from = task.recurrence_from || 'due';
    const custom = CUSTOM.exec(val);
    const [editing, setEditing] = React.useState(!!custom);
    const [n, setN] = React.useState(custom ? custom[1] : '2');
    const [unit, setUnit] = React.useState(custom ? custom[2] : 'week');
    const today = new Date();
    const due = task.dueDate ? parseDay(task.dueDate) : new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const hint = {
        weekdays: t('recurring_todos.hint_weekdays'),
        // "on Mondays": English says the weekday in the plural; the other languages' sentences take it as is.
        weekly: t('recurring_todos.hint_weekly', { days: fmt(due, { weekday: 'long' }) + (appLocale().startsWith('en') ? 's' : '') }),
        monthly: t('recurring_todos.hint_monthly', { day: nth(due.getDate()) }),
        yearly: t('recurring_todos.hint_yearly', { date: fmt(due, { month: 'short', day: 'numeric' }) }),
    };
    const next = (v) => fmt(advance(due, v), { month: 'short', day: 'numeric' });
    const setCustom = (count, u) => {
        const c = Math.max(1, Math.min(365, parseInt(count, 10) || 1));
        onPatch({ recurrence: c + ':' + u });
    };

    const row = (v, label, sub) => {
        const on = v === val;
        return h(
            'button',
            {
                key: v,
                type: 'button',
                onClick: () => {
                    setEditing(false);
                    onPatch({ recurrence: v });
                },
                'aria-pressed': on,
                className: 'flex min-h-12 w-full items-center gap-2 border-b px-4 py-3 text-left last:border-b-0',
            },
            h('span', { className: 'text-primary shrink-0 text-base ' + (on ? 'font-bold' : '') }, label),
            h(
                'span',
                { className: 'text-muted-foreground min-w-0 flex-1 truncate text-sm' },
                on && v !== 'none' ? t('recurring_todos.next_on', { date: next(v) }) : sub || '',
            ),
            on && CHECK,
        );
    };

    const lateDay = fmt(today, { month: 'short', day: 'numeric' });
    const explain =
        val === 'none'
            ? null
            : from === 'done'
              ? t('recurring_todos.from_done_hint', {
                    done: lateDay,
                    next: fmt(advance(today, val), { month: 'short', day: 'numeric' }),
                    instead: next(val),
                })
              : t('recurring_todos.from_due_hint', { next: next(val) });
    const seg = (v, label) =>
        h(
            'button',
            {
                key: v,
                type: 'button',
                'aria-pressed': from === v,
                onClick: () => onPatch({ recurrence_from: v }),
                className:
                    'rounded-full py-2.5 text-base font-semibold ' + (from === v ? 'bg-accent-strong text-accent-strong-foreground' : 'text-primary'),
            },
            label,
        );

    return h(
        'div',
        { className: 'flex flex-col gap-5', 'data-testid': 'repeat-picker' },
        h(
            'div',
            { className: 'bg-surface-card overflow-hidden rounded-2xl border' },
            PRESETS.map((v) => row(v, rhythmLabel(t, v), hint[v])),
            h(
                'button',
                {
                    type: 'button',
                    onClick: () => {
                        setEditing(true);
                        setCustom(n, unit);
                    },
                    'aria-pressed': !!custom,
                    className: 'flex min-h-12 w-full items-center gap-2 px-4 py-3 text-left',
                },
                h(
                    'span',
                    { className: 'text-primary shrink-0 text-base ' + (custom ? 'font-bold' : '') },
                    custom ? rhythmLabel(t, val) : t('recurring_todos.custom'),
                ),
                h(
                    'span',
                    { className: 'text-muted-foreground min-w-0 flex-1 truncate text-right text-sm' },
                    custom ? t('recurring_todos.next_on', { date: next(val) }) : t('recurring_todos.custom_hint'),
                ),
                custom ? CHECK : CHEVRON,
            ),
            editing &&
                h(
                    'div',
                    { className: 'flex items-center gap-2 border-t px-4 py-3' },
                    h('span', { className: 'text-primary text-base' }, t('recurring_todos.every')),
                    h('input', {
                        type: 'number',
                        inputMode: 'numeric',
                        min: 1,
                        max: 365,
                        value: n,
                        'aria-label': t('recurring_todos.every'),
                        onChange: (e) => {
                            setN(e.target.value);
                            if (e.target.value) setCustom(e.target.value, unit);
                        },
                        // 16px so iOS doesn't zoom in on focus.
                        className: 'bg-surface text-primary w-16 rounded-xl px-3 py-2 text-center text-base font-semibold outline-none',
                    }),
                    h(
                        'div',
                        { className: 'bg-surface flex min-w-0 flex-1 rounded-full p-0.5' },
                        ['day', 'week', 'month'].map((u) =>
                            h(
                                'button',
                                {
                                    key: u,
                                    type: 'button',
                                    'aria-pressed': unit === u,
                                    onClick: () => {
                                        setUnit(u);
                                        setCustom(n, u);
                                    },
                                    className:
                                        'min-w-0 flex-1 truncate rounded-full px-2 py-1.5 text-sm font-semibold ' +
                                        (unit === u ? 'bg-surface-card text-primary shadow-sm' : 'text-muted-foreground'),
                                },
                                t('recurring_todos.unit_' + u, { count: Number(n) || 1 }),
                            ),
                        ),
                    ),
                ),
        ),
        val !== 'none' &&
            h(
                'section',
                { className: 'flex flex-col gap-2' },
                h(
                    'h3',
                    { className: 'text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase' },
                    t('recurring_todos.count_from'),
                ),
                h(
                    'div',
                    { className: 'bg-surface grid grid-cols-2 rounded-full border p-1' },
                    seg('due', t('recurring_todos.from_due')),
                    seg('done', t('recurring_todos.from_done')),
                ),
                explain && h('p', { className: 'text-muted-foreground px-1 text-sm' }, explain),
            ),
    );
}

// Editor field: pick how often the task repeats. Pairs with the built-in date field above it.
function RecurrenceField(props) {
    const t = useT();
    const task = props.task || {};
    const onPatch = props.onPatch;
    const [val, setVal] = React.useState(task.recurrence || 'none');
    // The sheet's draft is the truth when there is one (the picker writes to it): follow it.
    const current = props.push ? task.recurrence || 'none' : val;

    function pick(v) {
        setVal(v);
        if (onPatch) onPatch({ recurrence: v });
    }

    // variant 'row': one settings row, "Repeat ... Every 3 months >". In the task sheet (it hands over `push`) the row
    // opens the picker in the sheet (T4); elsewhere (the Add sheet's details) the phone's own picker opens on a tap.
    if (props.variant === 'row') {
        const body = [
            ICON('text-primary size-5 shrink-0'),
            h('span', { key: 'k', className: 'text-primary min-w-0 flex-1 truncate text-[17px]' }, t('recurring_todos.repeat')),
            h('span', { key: 'v', className: 'text-primary shrink-0 text-base font-semibold' }, rhythmLabel(t, current)),
            CHEVRON,
        ];
        const cls = 'relative flex min-h-14 w-full items-center gap-3.5 border-b px-4 py-3 text-left last:border-b-0';
        if (props.push) {
            return h(
                'button',
                {
                    type: 'button',
                    'data-testid': 'recurrence-field',
                    className: cls,
                    onClick: () => props.push({ aside: t('recurring_todos.repeat'), render: (ctx) => h(RepeatPicker, ctx) }),
                },
                ...body,
            );
        }
        return h(
            'label',
            { 'data-testid': 'recurrence-field', className: cls },
            ...body,
            h(
                'select',
                {
                    value: PRESETS.includes(val) ? val : 'none',
                    onChange: (e) => pick(e.target.value),
                    'aria-label': t('recurring_todos.repeat'),
                    className: 'absolute inset-0 size-full cursor-pointer opacity-0',
                },
                PRESETS.map((v) => h('option', { key: v, value: v }, rhythmLabel(t, v))),
            ),
        );
    }

    return h(
        'div',
        { className: 'grid gap-2', 'data-testid': 'recurrence-field' },
        h('label', { className: 'text-muted-foreground text-xs font-semibold tracking-widest uppercase' }, t('recurring_todos.repeat')),
        h(
            'div',
            { className: 'flex flex-wrap gap-2' },
            PRESETS.map((v) =>
                h(
                    'button',
                    {
                        key: v,
                        type: 'button',
                        onClick: () => pick(v),
                        'data-recur': v,
                        className:
                            'rounded-full border px-3 py-1.5 text-sm transition-colors ' +
                            (val === v ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border text-primary'),
                    },
                    rhythmLabel(t, v),
                ),
            ),
        ),
    );
}

// Row badge: a repeat marker with the rhythm, when the task recurs.
function RecurrenceBadge(props) {
    const t = useT(); // before the early return: hooks run on every render
    const task = props.task || {};
    if (!task.recurrence || task.recurrence === 'none') return null;
    return h(
        'span',
        {
            'data-testid': 'recurrence-badge',
            className: 'shrink-0 inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-[11px] text-muted-foreground',
            title: rhythmLabel(t, task.recurrence),
        },
        h('span', null, '↻'),
        h('span', null, rhythmLabel(t, task.recurrence)),
    );
}

registerSlot('task.editor.fields', RecurrenceField);
registerSlot('task.row.badges', RecurrenceBadge);
