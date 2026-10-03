// contacts_get: name, contact methods as call / email / map / open links, plus the birthday fields the
// Birthdays module adds to the payload (camelCase, via person.serialize) when it's on.
const CONTACT_HREF = {
    phone: (v) => 'tel:' + v.replace(/[^\d+]/g, ''),
    email: (v) => 'mailto:' + v,
    url: (v) => (/^https?:\/\//i.test(v) ? v : 'https://' + v),
    address: (v) => 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(v),
};

FW.start((p) => {
    const { h } = FW;
    const methods = p.contact_methods || [];
    const extra = [p.nextBirthday ? `Birthday ${FW.day(p.nextBirthday)}` : null, p.age !== undefined && p.age !== null ? `age ${p.age}` : null]
        .filter(Boolean)
        .join(' · ');

    return h(
        'div',
        { class: 'stack' },
        FW.head(p.name, extra || null, p.url),
        h(
            'div',
            { class: 'card' },
            methods.length
                ? h(
                      'ul',
                      { class: 'rows' },
                      methods.map((m) => {
                          const href = CONTACT_HREF[m.type]?.(m.value);
                          return h(
                              'li',
                              { style: 'align-items:center' },
                              h(
                                  'div',
                                  { class: 'grow' },
                                  h('div', null, m.value),
                                  h('div', { class: 'meta' }, [m.type, m.label].filter(Boolean).join(' · ')),
                              ),
                              href
                                  ? h(
                                        'button',
                                        { class: 'btn sm ghost', type: 'button', onclick: () => FW.open(href) },
                                        { phone: 'Call', email: 'Email', address: 'Map' }[m.type] || 'Open',
                                    )
                                  : null,
                          );
                      }),
                  )
                : h('div', { class: 'empty' }, 'No contact details yet.'),
        ),
    );
});
