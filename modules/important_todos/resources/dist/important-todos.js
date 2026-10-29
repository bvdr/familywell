// Pre-built module frontend: uses the shell runtime (window.Familywell), no bundler, no second
// React. It registers into two slots the task edit
// screen exposes (resources/js/components/tasks/task-tree.tsx):
//   - task.editor.fields : an "Important" toggle in the edit drawer
//   - task.row.badges    : a star on important rows
// Both receive a `ctx` the primitive spreads as props (task, and for the editor an onPatch fn).
const { React, registerSlot, useT } = window.Familywell;
const h = React.createElement;

// Editor field: a toggle bound to the task's `important` flag. It persists through `onPatch`, which
// the tasks module wires to its own task endpoint — the primitive keeps ownership of the router, the
// module only says "patch this field". The backend `task.saved` hook stores it. Up to 3 open important
// to-dos per person (Home's "Your important" card): the server refuses a 4th, so the optimistic star
// is undone and the rule is shown under the toggle.
function ImportantField(props) {
    const t = useT();
    const task = props.task || {};
    const onPatch = props.onPatch;
    const [on, setOn] = React.useState(!!task.important);
    const [refused, setRefused] = React.useState(false);

    function toggle() {
        const next = !on;
        setOn(next); // optimistic
        setRefused(false);
        if (!onPatch) return;
        Promise.resolve(onPatch({ important: next })).then((result) => {
            if (next && result === 'rejected') {
                setOn(false);
                setRefused(true);
            }
        });
    }

    // variant 'row' (the Add sheet's details form, design H2f): a settings row with a switch. Nothing is saved there
    // until Add, so a 4th star is refused by the server on Add and its message shows on the sheet.
    if (props.variant === 'row') {
        return h(
            'div',
            { className: 'flex min-h-14 w-full items-center gap-3.5 border-b px-4 py-3 last:border-b-0' },
            h(
                'svg',
                { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', className: 'text-primary size-5 shrink-0', 'aria-hidden': true },
                h('path', { d: 'M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z' }),
            ),
            h('span', { className: 'text-primary min-w-0 flex-1 truncate text-[17px]' }, t('important_todos.important')),
            h(
                'button',
                {
                    type: 'button',
                    role: 'switch',
                    'aria-checked': on,
                    'aria-label': t('important_todos.important'),
                    'data-testid': 'important-toggle',
                    onClick: toggle,
                    className: 'relative h-8 w-14 shrink-0 rounded-full transition-colors ' + (on ? 'bg-primary' : 'bg-border'),
                },
                h('span', {
                    className:
                        'absolute top-1 size-6 rounded-full shadow-sm transition-all ' + (on ? 'bg-accent-strong left-7' : 'left-1 bg-white'),
                }),
            ),
        );
    }

    return h(
        'div',
        { className: 'flex flex-col gap-1.5' },
        h(
            'button',
            {
                type: 'button',
                onClick: toggle,
                'data-testid': 'important-toggle',
                'aria-pressed': on,
                className:
                    'flex items-center justify-between rounded-lg border px-3 py-2 text-sm transition-colors ' +
                    (on ? 'border-primary text-primary' : 'border-border text-muted-foreground'),
            },
            h('span', { className: 'font-medium' }, t('important_todos.important')),
            h('span', { className: 'text-xs' }, on ? '★ ' + t('important_todos.on') : t('important_todos.off')),
        ),
        refused && h('p', { role: 'alert', className: 'text-destructive text-sm' }, t('important_todos.limit_ui', { limit: 3 })),
    );
}

// Row badge: a star when the task is important. Read-only display.
function ImportantBadge(props) {
    const t = useT(); // before the early return: hooks run on every render
    const task = props.task || {};
    if (!task.important) return null;
    return h('span', { 'data-testid': 'important-badge', className: 'shrink-0 text-amber-500', title: t('important_todos.important') }, '★');
}

registerSlot('task.editor.fields', ImportantField);
registerSlot('task.row.badges', ImportantBadge);
