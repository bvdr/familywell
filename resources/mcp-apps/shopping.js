// shopping_get_list (a list + items) and shopping_search (a flat `items` page across lists).
// Open items are grouped by aisle like the store walk in the app; bought ones sink to the bottom.
FW.start(
    (data) => {
        const { h } = FW;
        const isList = data.id !== undefined;
        const items = data.items || [];

        const toggle = (i) => {
            const bought = !i.bought;
            return FW.act({
                apply: () => {
                    i.bought = bought;
                },
                revert: () => {
                    i.bought = !bought;
                },
                tool: 'shopping_update_item',
                args: { item_id: i.id, bought },
                tell: `${bought ? 'ticked off as bought' : 'put back on the list'} "${i.name}"`,
            });
        };

        const row = (i) =>
            h(
                'li',
                null,
                h('input', { type: 'checkbox', checked: i.bought, 'aria-label': i.name, onchange: () => toggle(i) }),
                h(
                    'div',
                    { class: 'grow' },
                    h('div', { class: i.bought ? 'strike' : null }, i.name),
                    i.note || (!isList && i.list_name) || i.due_date
                        ? h(
                              'div',
                              { class: 'meta' },
                              [i.due_date ? FW.day(i.due_date) : null, i.note, !isList ? i.list_name : null].filter(Boolean).join(' · '),
                          )
                        : null,
                ),
                i.quantity ? h('span', { class: 'end meta' }, i.quantity) : null,
            );

        const open = items.filter((i) => !i.bought);
        const bought = items.filter((i) => i.bought);
        const aisles = new Map();
        for (const i of open) {
            const k = i.aisle || 'Other';
            if (!aisles.has(k)) aisles.set(k, []);
            aisles.get(k).push(i);
        }
        const group = (title, rows) =>
            h(
                'div',
                { class: 'section' },
                h('h2', null, title, ' · ', rows.length),
                h('div', { class: 'card' }, h('ul', { class: 'rows' }, rows.map(row))),
            );

        return h(
            'div',
            { class: 'stack' },
            FW.head(
                isList ? data.name : 'Shopping',
                isList ? [data.store, `${open.length} to get`].filter(Boolean).join(' · ') : `${items.length} found`,
                data.url,
            ),
            isList && !data.archived
                ? FW.addForm('Add an item', async (name) => {
                      const out = await FW.act({ tool: 'shopping_add_item', args: { list_id: data.id, name }, tell: `added "${name}" to the list` });
                      if (out?.warning) FW.toast(out.warning);
                      if (out) await FW.reload('shopping_get_list');
                  })
                : null,
            items.length ? null : h('div', { class: 'card empty' }, 'Nothing on this list.'),
            [...aisles].map(([aisle, rows]) => group(aisle, rows)),
            bought.length ? group('Bought', bought) : null,
        );
    },
    { fullscreen: true },
);
