// calendar_list: agenda grouped by day; a multi-day item shows once per day it covers inside the range, as
// "day N of M". calendar_get_event: the event card with people and to-dos. Display only; links open the app.
FW.start((d) => {
    const { h } = FW;
    const DAY = 86400000;
    // core.js's FW.day parses 'Y-m-d' as local (not `new Date('Y-m-d')`, which is UTC and shifts a day in
    // negative-UTC-offset zones), but it doesn't expose that parser itself, so we keep a local copy here.
    const parseDay = (s) => {
        const [y, m, dd] = String(s).slice(0, 10).split('-').map(Number);
        return new Date(y, m - 1, dd);
    };
    const ymd = (t) => {
        const x = new Date(t);
        return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
    };
    const between = (a, b) => Math.round((parseDay(b) - parseDay(a)) / DAY);

    if (d.items === undefined && d.id) {
        // calendar_get_event
        const when = d.start_date === d.end_date ? FW.day(d.start_date) : `${FW.day(d.start_date)} – ${FW.day(d.end_date)}`;
        return h(
            'div',
            { class: 'stack' },
            FW.head(d.title, [when, d.start_time ? `${d.start_time}–${d.end_time}` : null].filter(Boolean).join(' · '), d.url),
            d.people?.length ? h('div', { class: 'card' }, h('div', { class: 'meta' }, d.people.map((p) => p.name).join(', '))) : null,
            d.todos?.length
                ? h(
                      'div',
                      { class: 'card' },
                      h(
                          'ul',
                          { class: 'rows' },
                          d.todos.map((t) => h('li', null, h('span', { class: t.done ? 'strike' : null }, t.title))),
                      ),
                  )
                : null,
        );
    }

    // calendar_list: bucket items into each day of the range they cover (clamped to the requested window).
    const days = new Map();
    for (const i of d.items || []) {
        const start = i.start < d.from ? d.from : i.start;
        const end = i.end > d.to ? d.to : i.end;
        for (let t = parseDay(start).getTime(); t <= parseDay(end).getTime(); t += DAY) {
            const k = ymd(t);
            if (!days.has(k)) days.set(k, []);
            days.get(k).push(i);
        }
    }
    const keys = [...days.keys()].sort();
    return h(
        'div',
        { class: 'stack' },
        FW.head('Calendar', `${FW.day(d.from)} – ${FW.day(d.to)}`, null),
        keys.length === 0
            ? h('div', { class: 'card' }, h('div', { class: 'empty' }, 'Nothing on the calendar in this range.'))
            : keys.map((k) =>
                  h(
                      'div',
                      { class: 'card' },
                      h('div', { class: 'meta', style: 'font-weight:650;margin-bottom:4px' }, FW.day(k)),
                      h(
                          'ul',
                          { class: 'rows' },
                          days.get(k).map((i) => {
                              const total = between(i.start, i.end) + 1;
                              const sub = i.startTime ? `${i.startTime}–${i.endTime}` : total > 1 ? `day ${between(i.start, k) + 1} of ${total}` : null;
                              return h(
                                  'li',
                                  { style: 'align-items:center;cursor:pointer', onclick: () => FW.open(i.url) },
                                  h(
                                      'div',
                                      { class: 'grow' },
                                      h('div', { class: i.done ? 'strike' : null }, i.title),
                                      h('div', { class: 'meta' }, [sub, (i.people || []).map((p) => p.name).join(', ')].filter(Boolean).join(' · ')),
                                  ),
                              );
                          }),
                      ),
                  ),
              ),
    );
});
