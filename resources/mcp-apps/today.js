// get_today: what's due (overdue first) and later this week. Each agenda item carries kind + id, so it
// is ticked off with that module's own update tool; recently opened items are plain links.
const TODAY_TOGGLE = {
    task: (id) => ['tasks_update_task', { task_id: id, done: true }, { task_id: id, done: false }],
    project_task: (id) => ['projects_update_task', { task_id: id, done: true }, { task_id: id, done: false }],
    shopping_item: (id) => ['shopping_update_item', { item_id: id, bought: true }, { item_id: id, bought: false }],
};

FW.start((d) => {
    const { h } = FW;

    const toggle = (i) => {
        const [tool, on, off] = TODAY_TOGGLE[i.kind](i.id);
        const done = !i.done;
        return FW.act({
            apply: () => {
                i.done = done;
            },
            revert: () => {
                i.done = !done;
            },
            tool,
            args: done ? on : off,
            tell: `${done ? 'ticked off' : 'un-ticked'} "${i.title}" (${i.where})`,
        });
    };

    const row = (i) =>
        h(
            'li',
            null,
            TODAY_TOGGLE[i.kind] && i.id
                ? h('input', { type: 'checkbox', checked: !!i.done, 'aria-label': i.title, onchange: () => toggle(i) })
                : null,
            h('div', { class: 'grow' }, h('div', { class: i.done ? 'strike' : null }, i.title), h('div', { class: 'meta' }, i.where)),
            h('span', { class: `end small ${i.overdue && !i.done ? 'danger' : 'muted'}` }, FW.day(i.due_date)),
        );

    const block = (title, items, empty) =>
        h(
            'div',
            { class: 'section' },
            h('h2', null, title),
            h('div', { class: 'card' }, items.length ? h('ul', { class: 'rows' }, items.map(row)) : h('div', { class: 'empty' }, empty)),
        );

    const left = d.due.filter((i) => !i.done).length;
    return h(
        'div',
        { class: 'stack' },
        FW.head('Today', `${new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })} · ${left} due`),
        block('Due now', d.due, 'Nothing due. Enjoy the day.'),
        d.later_this_week.length ? block('Later this week', d.later_this_week) : null,
        d.recently_opened?.length
            ? h(
                  'div',
                  { class: 'section' },
                  h('h2', null, 'Recently opened'),
                  h(
                      'div',
                      { class: 'card' },
                      h(
                          'ul',
                          { class: 'rows' },
                          d.recently_opened
                              .slice(0, 6)
                              .map((r) =>
                                  h(
                                      'li',
                                      null,
                                      h('div', { class: 'grow' }, r.title, r.subtitle ? h('div', { class: 'meta' }, r.subtitle) : null),
                                      h('button', { class: 'link', type: 'button', onclick: () => FW.open(r.url) }, 'Open ↗'),
                                  ),
                              ),
                      ),
                  ),
              )
            : null,
    );
});
