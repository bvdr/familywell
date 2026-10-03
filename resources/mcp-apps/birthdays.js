// birthdays_list: soonest first, with a countdown and the age they turn. Display only.
FW.start((d) => {
    const { h } = FW;
    const list = d.birthdays || [];
    const when = (b) => (b.days_until === 0 ? 'Today 🎉' : b.days_until === 1 ? 'Tomorrow' : `in ${b.days_until} days`);

    return h(
        'div',
        { class: 'stack' },
        FW.head('Birthdays', list.length ? `${list.length} coming up` : null, list[0]?.url),
        h(
            'div',
            { class: 'card' },
            list.length
                ? h(
                      'ul',
                      { class: 'rows' },
                      list.map((b) =>
                          h(
                              'li',
                              { style: 'align-items:center' },
                              h(
                                  'div',
                                  { class: 'grow' },
                                  h('div', { style: b.days_until === 0 ? 'font-weight:650' : null }, b.name),
                                  h(
                                      'div',
                                      { class: 'meta' },
                                      [FW.day(b.next_birthday), b.turning ? `turns ${b.turning}` : null].filter(Boolean).join(' · '),
                                  ),
                              ),
                              h('span', { class: `pill ${b.days_until <= 7 ? 'good' : ''}` }, when(b)),
                          ),
                      ),
                  )
                : h('div', { class: 'empty' }, 'No birthdays in this range.'),
        ),
    );
});
