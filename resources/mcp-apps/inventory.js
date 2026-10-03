// inventory_needs_attention (empty / low / expiring / stale buckets) and inventory_list (`items`).
// Each item gets -/+ steppers (inventory_adjust_quantity, logged in its history like the app's quick
// update) and "Add to shopping" when it isn't on a list yet.
const INV_STATE = { expired: 'bad', empty: 'bad', expiring: 'warn', low: 'warn', unknown: '', good: 'good' };
// ponytail: step of 1 for counted units, a sensible chunk for weights/volumes. A per-item step setting
// would be the upgrade if people want finer control.
const INV_STEP = { g: 100, ml: 100, kg: 0.5, L: 0.5 };

FW.start((d) => {
    const { h } = FW;

    const adjust = async (i, sign) => {
        const step = INV_STEP[i.unit] || 1;
        const before = i.quantity;
        const out = await FW.act({
            apply: () => {
                i.quantity = Math.max(0, Number(i.quantity || 0) + sign * step);
            },
            revert: () => {
                i.quantity = before;
            },
            tool: 'inventory_adjust_quantity',
            args: { item_id: i.id, change: sign * step },
            tell: `${sign > 0 ? 'restocked' : 'used'} ${step} ${i.unit || ''} of "${i.name}"`,
        });
        if (out) {
            Object.assign(i, out);
            FW.draw();
        } // server state: new state pill, quantity
    };

    const toShopping = async (i) => {
        const out = await FW.act({
            apply: () => {
                i.on_shopping_list = true;
            },
            revert: () => {
                i.on_shopping_list = false;
            },
            tool: 'inventory_add_to_shopping',
            args: { item_id: i.id },
            tell: `added "${i.name}" to the shopping list`,
        });
        if (out) FW.toast(out.already_on_list ? `${i.name} was already on the shopping list.` : `Added ${i.name} to the shopping list.`);
    };

    const qty = (i) =>
        i.quantity === null || i.quantity === undefined ? '?' : `${FW.num(i.quantity, { maximumFractionDigits: 2 })}${i.unit ? ' ' + i.unit : ''}`;

    const row = (i) =>
        h(
            'li',
            { style: 'align-items:center' },
            h(
                'div',
                { class: 'grow' },
                h('div', null, i.name, ' ', h('span', { class: `pill ${INV_STATE[i.state] ?? ''}` }, i.state)),
                h(
                    'div',
                    { class: 'meta' },
                    [
                        i.expires_on ? `expires ${FW.day(i.expires_on)}` : null,
                        i.min_quantity ? `low at ${FW.num(i.min_quantity)}` : null,
                        i.on_shopping_list ? 'on shopping list' : null,
                    ]
                        .filter(Boolean)
                        .join(' · '),
                ),
            ),
            h(
                'div',
                { style: 'display:flex;align-items:center;gap:6px;flex:none' },
                h(
                    'button',
                    { class: 'btn ghost sm', type: 'button', 'aria-label': `Use one ${i.name}`, disabled: !i.quantity, onclick: () => adjust(i, -1) },
                    '−',
                ),
                h('span', { style: 'min-width:56px;text-align:center;font-variant-numeric:tabular-nums' }, qty(i)),
                h('button', { class: 'btn ghost sm', type: 'button', 'aria-label': `Restock ${i.name}`, onclick: () => adjust(i, 1) }, '+'),
                i.on_shopping_list
                    ? null
                    : h('button', { class: 'btn sm', type: 'button', title: 'Add to shopping list', onclick: () => toShopping(i) }, '🛒'),
            ),
        );

    const block = (title, items) =>
        h(
            'div',
            { class: 'section' },
            h('h2', null, title, ' · ', items.length),
            h('div', { class: 'card' }, h('ul', { class: 'rows' }, items.map(row))),
        );

    if (d.items) {
        return h(
            'div',
            { class: 'stack' },
            FW.head('Inventory', `${d.items.length} item${d.items.length === 1 ? '' : 's'}`),
            d.items.length
                ? h('div', { class: 'card' }, h('ul', { class: 'rows' }, d.items.map(row)))
                : h('div', { class: 'card empty' }, 'No items match.'),
        );
    }

    const buckets = [
        ['empty', 'Empty'],
        ['low', 'Running low'],
        ['expiring', 'Expiring'],
        ['stale', 'Not checked in a while'],
    ].filter(([k]) => d[k]?.length);
    return h(
        'div',
        { class: 'stack' },
        FW.head('Needs attention', `${d.total} item${d.total === 1 ? '' : 's'}`, d.url),
        buckets.length ? buckets.map(([k, title]) => block(title, d[k])) : h('div', { class: 'card empty' }, 'All stocked up.'),
    );
});
