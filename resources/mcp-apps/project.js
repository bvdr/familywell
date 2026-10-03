// projects_get: status + task progress, tickable tasks/sub-tasks, quick-add, dependencies, linked lists.
FW.start((p) => {
    const { h } = FW;
    const tasks = p.tasks || [];
    const done = () => tasks.filter((t) => t.done).length;

    const toggle = (t) => {
        const done = !t.done;
        return FW.act({
            apply: () => {
                t.done = done;
            },
            revert: () => {
                t.done = !done;
            },
            tool: 'projects_update_task',
            args: { task_id: t.id, done },
            tell: `${done ? 'ticked off' : 'un-ticked'} project task "${t.title}" in "${p.name}"`,
        });
    };

    const check = (t) => h('input', { type: 'checkbox', checked: t.done, 'aria-label': t.title, onchange: () => toggle(t) });
    const row = (t) =>
        h(
            'li',
            null,
            check(t),
            h(
                'div',
                { class: 'grow' },
                h('div', { class: t.done ? 'strike' : null }, t.title),
                t.due_date || t.assigned_to
                    ? h(
                          'div',
                          { class: 'meta' },
                          t.due_date ? h('span', { class: !t.done && FW.overdue(t.due_date) ? 'danger' : null }, FW.day(t.due_date)) : null,
                          t.due_date && t.assigned_to ? ' · ' : '',
                          t.assigned_to?.name,
                      )
                    : null,
                t.subtasks?.length
                    ? h(
                          'ul',
                          { class: 'sub-rows' },
                          t.subtasks.map((s) => h('li', null, check(s), h('span', { class: s.done ? 'strike' : null }, s.title))),
                      )
                    : null,
            ),
        );

    const statusPill = { blocked: 'bad', done: 'good', in_progress: 'warn' }[p.status] || '';
    const pct = tasks.length ? Math.round((done() / tasks.length) * 100) : 0;

    return h(
        'div',
        { class: 'stack' },
        FW.head(p.name, p.owner ? `Owner: ${p.owner.name}` : null, p.url),
        h(
            'div',
            { class: 'card', style: 'padding:12px' },
            h(
                'div',
                { style: 'display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px' },
                h('span', { class: `pill ${statusPill}` }, String(p.status || '').replace(/_/g, ' ')),
                h('span', { class: 'meta small' }, `${done()} of ${tasks.length} tasks done`),
            ),
            h(
                'div',
                { class: 'bar', role: 'progressbar', 'aria-valuenow': pct, 'aria-valuemin': 0, 'aria-valuemax': 100 },
                h('i', { style: `width:${pct}%` }),
            ),
            p.waiting_on ? h('p', { class: 'small warn', style: 'margin:8px 0 0' }, 'Waiting on: ', p.waiting_on) : null,
            p.description ? h('p', { class: 'small muted', style: 'margin:8px 0 0;white-space:pre-line' }, p.description) : null,
        ),
        h(
            'div',
            { class: 'section' },
            h('h2', null, 'Tasks'),
            h(
                'div',
                { class: 'card' },
                tasks.length
                    ? h('ul', { class: 'rows' }, tasks.filter((t) => !t.done).map(row), tasks.filter((t) => t.done).map(row))
                    : h('div', { class: 'empty' }, 'No tasks yet.'),
            ),
        ),
        p.archived
            ? null
            : FW.addForm('Add a task', async (title) => {
                  const t = await FW.act({
                      tool: 'projects_add_task',
                      args: { project_id: p.id, title },
                      tell: `added task "${title}" to project "${p.name}"`,
                  });
                  if (t) await FW.reload('projects_get');
              }),
        p.dependencies?.length
            ? h(
                  'div',
                  { class: 'section' },
                  h('h2', null, 'Dependencies'),
                  h(
                      'div',
                      { class: 'card' },
                      h(
                          'ul',
                          { class: 'rows' },
                          p.dependencies.map((d) =>
                              h(
                                  'li',
                                  null,
                                  h('div', { class: 'grow' }, d.name, d.note ? h('div', { class: 'meta' }, d.note) : null),
                                  h(
                                      'span',
                                      { class: `pill ${d.status === 'done' ? 'good' : d.status === 'blocked' ? 'bad' : ''}` },
                                      String(d.status || '').replace(/_/g, ' '),
                                  ),
                              ),
                          ),
                      ),
                  ),
              )
            : null,
        p.shopping_lists?.length
            ? h(
                  'div',
                  { class: 'section' },
                  h('h2', null, 'Shopping lists'),
                  h(
                      'div',
                      { class: 'card' },
                      h(
                          'ul',
                          { class: 'rows' },
                          p.shopping_lists.map((l) =>
                              h(
                                  'li',
                                  null,
                                  h(
                                      'div',
                                      { class: 'grow' },
                                      l.name,
                                      h(
                                          'div',
                                          { class: 'bar', style: 'margin-top:6px' },
                                          h('i', { style: `width:${l.total ? Math.round((l.bought / l.total) * 100) : 0}%` }),
                                      ),
                                  ),
                                  h('span', { class: 'end meta' }, `${l.bought}/${l.total}`),
                              ),
                          ),
                      ),
                  ),
              )
            : null,
    );
});
