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
