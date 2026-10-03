// spending_summary (totals by category / shop for a period, with period switching) and
// spending_list_expenses (a page of expenses). Category keys get their family names from
// spending_list_categories, fetched once; until then (or if it fails) the key is prettified.
let SPEND_NAMES = null;
const spendName = (key) =>
    SPEND_NAMES?.[key] ||
    String(key)
        .replace(/[_-]+/g, ' ')
        .replace(/^\w/, (c) => c.toUpperCase());

// Period presets for the summary, as from/to dates (local calendar, ISO).
function spendPeriods() {
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const now = new Date();
    const monday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7));
    return [
        ['This week', iso(monday), iso(now)],
        ['This month', iso(new Date(now.getFullYear(), now.getMonth(), 1)), iso(now)],
        ['Last month', iso(new Date(now.getFullYear(), now.getMonth() - 1, 1)), iso(new Date(now.getFullYear(), now.getMonth(), 0))],
        ['This year', iso(new Date(now.getFullYear(), 0, 1)), iso(now)],
    ];
}

FW.start((d) => {
    const { h } = FW;
    if (SPEND_NAMES === null) {
        SPEND_NAMES = {};
        FW.call('spending_list_categories')
            .then((c) => {
                for (const x of c.categories || []) SPEND_NAMES[x.key] = x.name;
                FW.draw();
            })
            .catch(() => {});
    }

    if (d.expenses) {
        return h(
            'div',
            { class: 'stack' },
            FW.head('Expenses', `${d.expenses.length} shown`),
            h(
                'div',
                { class: 'card' },
                d.expenses.length
                    ? h(
                          'ul',
                          { class: 'rows' },
                          d.expenses.map((e) =>
                              h(
                                  'li',
                                  null,
                                  h(
                                      'div',
                                      { class: 'grow' },
                                      h('div', null, e.title || e.shop || spendName(e.category)),
                                      h(
                                          'div',
                                          { class: 'meta' },
                                          [FW.day(e.date), spendName(e.category), e.title ? e.shop : null, e.paid_by?.name, e.list?.name]
                                              .filter(Boolean)
                                              .join(' · '),
                                      ),
                                  ),
                                  h('span', { class: 'end' }, FW.money(e.amount, e.currency)),
                              ),
                          ),
                      )
                    : h('div', { class: 'empty' }, 'No expenses for this filter.'),
            ),
        );
    }

    const bars = (title, rows, label) => {
        const max = Math.max(...rows.map((r) => Number(r.amount)), 0) || 1;
        return h(
            'div',
            { class: 'section' },
            h('h2', null, title),
            h(
                'div',
                { class: 'card' },
                h(
                    'ul',
                    { class: 'rows' },
                    rows
                        .slice(0, 8)
                        .map((r) =>
                            h(
                                'li',
                                { style: 'flex-direction:column;gap:5px' },
                                h(
                                    'div',
                                    { style: 'display:flex;width:100%;gap:8px' },
                                    h('span', { class: 'grow' }, label(r.name)),
                                    h('span', { class: 'end' }, FW.money(r.amount, d.currency)),
                                ),
                                h(
                                    'div',
                                    { class: 'bar', style: 'width:100%' },
                                    h('i', { style: `width:${Math.max(2, (Number(r.amount) / max) * 100)}%` }),
                                ),
                            ),
                        ),
                ),
            ),
        );
    };

    const period = async (from, to, label) => {
        try {
            FW.data = await FW.call('spending_summary', { from, to });
            FW.draw();
            FW.note(`switched the spending summary to ${label} (${from} to ${to})`);
        } catch (e) {
            FW.toast(FW.friendly(e));
        }
    };

    return h(
        'div',
        { class: 'stack' },
        FW.head('Spending', `${FW.day(d.from)} – ${FW.day(d.to)}`),
        h(
            'div',
            { class: 'tabs' },
            spendPeriods().map(([label, from, to]) =>
                h(
                    'button',
                    {
                        class: 'btn sm',
                        type: 'button',
                        'aria-pressed': String(from === d.from && to === d.to),
                        onclick: () => period(from, to, label),
                    },
                    label,
                ),
            ),
        ),
        h(
            'div',
            { class: 'card', style: 'padding:12px' },
            h('div', { class: 'stat' }, FW.money(d.total, d.currency)),
            h('div', { class: 'meta small' }, `${d.expense_count} expense${d.expense_count === 1 ? '' : 's'}`),
        ),
        d.by_category.length ? bars('By category', d.by_category, spendName) : h('div', { class: 'card empty' }, 'Nothing spent in this period.'),
        d.by_shop.length ? bars('By shop', d.by_shop, (s) => s) : null,
    );
});
