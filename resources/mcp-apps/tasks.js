// tasks_get_list (a list + its tasks with sub-tasks) and tasks_search (a flat `tasks` page across lists).
FW.start((data) => {
    const { h } = FW;
    const isList = data.tasks && data.id !== undefined;

    const toggle = (t) => {
        const done = !t.done;
        return FW.act({
            apply: () => {
                t.done = done;
            },
            revert: () => {
                t.done = !done;
            },
            tool: 'tasks_update_task',
            args: { task_id: t.id, done },
            tell: `${done ? 'ticked off' : 'un-ticked'} task "${t.title}"`,
        });
    };

    const meta = (t) => {
        const bits = [];
        if (t.due_date) bits.push(h('span', { class: !t.done && FW.overdue(t.due_date) ? 'danger' : null }, FW.day(t.due_date)));
        if (t.assigned_to) bits.push(t.assigned_to.name);
        if (!isList && t.list_name) bits.push(t.list_name);
        if (t.note) bits.push(t.note);
        return bits.length
            ? h(
                  'div',
                  { class: 'meta' },
                  bits.map((b, i) => [i ? ' · ' : '', b]),
              )
            : null;
    };

    const row = (t) =>
        h(
            'li',
            null,
            h('input', { type: 'checkbox', checked: t.done, 'aria-label': t.title, onchange: () => toggle(t) }),
            h(
                'div',
                { class: 'grow' },
                h('div', { class: t.done ? 'strike' : null }, t.important ? '★ ' : '', t.title),
                meta(t),
                t.subtasks?.length
                    ? h(
                          'ul',
                          { class: 'sub-rows' },
                          t.subtasks.map((s) =>
                              h(
                                  'li',
                                  null,
                                  h('input', { type: 'checkbox', checked: s.done, 'aria-label': s.title, onchange: () => toggle(s) }),
                                  h('span', { class: s.done ? 'strike' : null }, s.title),
                              ),
                          ),
                      )
                    : null,
            ),
        );

    const tasks = data.tasks || [];
    const open = tasks.filter((t) => !t.done);
    const done = tasks.filter((t) => t.done);

    return h(
        'div',
        { class: 'stack' },
        isList
            ? FW.head(data.name, `${open.length} to do${done.length ? ` · ${done.length} done` : ''}`, data.url)
            : FW.head('Tasks', `${tasks.length} found`),
        h(
            'div',
            { class: 'card' },
            tasks.length ? h('ul', { class: 'rows' }, open.map(row), done.map(row)) : h('div', { class: 'empty' }, 'Nothing here yet.'),
        ),
        isList && !data.archived
            ? FW.addForm('Add a task', async (title) => {
                  const t = await FW.act({ tool: 'tasks_create_task', args: { list_id: data.id, title }, tell: `added task "${title}"` });
                  if (t) await FW.reload('tasks_get_list');
              })
            : null,
    );
});
