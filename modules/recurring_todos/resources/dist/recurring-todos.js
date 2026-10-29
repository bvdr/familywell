// Pre-built module frontend (shell runtime, no bundler, no second React). Registers into the task
// edit screen's slots: an interval picker in the editor (task.editor.fields) and a repeat badge on
// the row (task.row.badges). `ctx` is spread as props by <Slot>; onPatch persists via the tasks
// endpoint (the backend `task.saved` hook stores it).
const { React, registerSlot, useT } = window.Familywell;
const h = React.createElement;

const OPTIONS = [
    ['none', 'None'],
    ['daily', 'Daily'],
    ['weekly', 'Weekly'],
    ['monthly', 'Monthly'],
    ['yearly', 'Yearly'],
];
const LABEL = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly', yearly: 'Yearly' };

// Editor field: pick how often the task repeats. Pairs with the built-in date field above it.
function RecurrenceField(props) {
    const t = useT();
    const task = props.task || {};
    const onPatch = props.onPatch;
    const [val, setVal] = React.useState(task.recurrence || 'none');

    function pick(v) {
        setVal(v);
        if (onPatch) onPatch({ recurrence: v });
    }

    // variant 'row' (the Add sheet's details form, design H2f): one settings row, "Repeat ... Never >", with the
    // phone's own picker (a transparent native select over the row) instead of the chips.
    if (props.variant === 'row') {
        return h(
            'label',
            {
                'data-testid': 'recurrence-field',
                className: 'relative flex min-h-14 w-full items-center gap-3.5 border-b px-4 py-3 last:border-b-0',
            },
            h(
                'svg',
                { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', className: 'text-primary size-5 shrink-0', 'aria-hidden': true },
                h('path', { d: 'm2 9 3-3 3 3' }),
                h('path', { d: 'M13 18H7a2 2 0 0 1-2-2V6' }),
                h('path', { d: 'm22 15-3 3-3-3' }),
                h('path', { d: 'M11 6h6a2 2 0 0 1 2 2v10' }),
            ),
            h('span', { className: 'text-primary min-w-0 flex-1 truncate text-[17px]' }, t('recurring_todos.repeat')),
            h('span', { className: 'text-primary shrink-0 text-base font-semibold' }, t('recurring_todos.' + val)),
            h(
                'svg',
                { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', className: 'text-muted-foreground size-5 shrink-0', 'aria-hidden': true },
                h('path', { d: 'm9 18 6-6-6-6' }),
            ),
            h(
                'select',
                {
                    value: val,
                    onChange: (e) => pick(e.target.value),
                    'aria-label': t('recurring_todos.repeat'),
                    className: 'absolute inset-0 size-full cursor-pointer opacity-0',
                },
                OPTIONS.map(([v]) => h('option', { key: v, value: v }, t('recurring_todos.' + v))),
            ),
        );
    }

    return h(
        'div',
        { className: 'grid gap-2', 'data-testid': 'recurrence-field' },
        h(
            'label',
            { className: 'text-muted-foreground text-xs font-semibold tracking-widest uppercase' },
            t('recurring_todos.repeat'),
        ),
        h(
            'div',
            { className: 'flex flex-wrap gap-2' },
            OPTIONS.map(([v]) =>
                h(
                    'button',
                    {
                        key: v,
                        type: 'button',
                        onClick: () => pick(v),
                        'data-recur': v,
                        className:
                            'rounded-full border px-3 py-1.5 text-sm transition-colors ' +
                            (val === v
                                ? 'border-primary bg-accent-strong text-accent-strong-foreground'
                                : 'border-border text-primary'),
                    },
                    t('recurring_todos.' + v),
                ),
            ),
        ),
    );
}

// Row badge: a repeat marker with the interval, when the task recurs.
function RecurrenceBadge(props) {
    const t = useT(); // before the early return: hooks run on every render
    const task = props.task || {};
    if (!task.recurrence || task.recurrence === 'none') return null;
    return h(
        'span',
        {
            'data-testid': 'recurrence-badge',
            className:
                'shrink-0 inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-[11px] text-muted-foreground',
            title: 'Repeats ' + task.recurrence,
        },
        h('span', null, '↻'),
        h('span', null, LABEL[task.recurrence] ? t('recurring_todos.' + task.recurrence) : t('recurring_todos.repeat')),
    );
}

registerSlot('task.editor.fields', RecurrenceField);
registerSlot('task.row.badges', RecurrenceBadge);
