// vehicles_get (one car: odometer, maintenance with "Mark done", documents, recent work),
// vehicles_list (`vehicles` cards) and vehicles_history (`history` timeline).
// Distances arrive in km; shown in the family's display unit when the payload says miles.
const MAINT_PILL = { overdue: ['bad', 'Overdue'], due: ['bad', 'Due'], due_soon: ['warn', 'Due soon'], good: ['good', 'OK'] };

FW.start((d) => {
    const { h } = FW;
    const miles = d.display_unit === 'miles';
    const dist = (km) => (km === null || km === undefined ? '' : `${FW.num(Math.round(miles ? km / 1.609344 : km))} ${miles ? 'mi' : 'km'}`);
    const typeLabel = (t) =>
        String(t || '')
            .replace(/_/g, ' ')
            .replace(/^\w/, (c) => c.toUpperCase());

    const timeline = (entries) =>
        h(
            'div',
            { class: 'card' },
            entries.length
                ? h(
                      'ul',
                      { class: 'rows' },
                      entries.map((e) =>
                          h(
                              'li',
                              null,
                              h(
                                  'div',
                                  { class: 'grow' },
                                  h('div', null, e.title || (e.type === 'mileage' ? dist(e.mileage) : typeLabel(e.type))),
                                  h(
                                      'div',
                                      { class: 'meta' },
                                      [typeLabel(e.type), e.subtitle, e.type !== 'mileage' && e.mileage ? dist(e.mileage) : null]
                                          .filter(Boolean)
                                          .join(' · '),
                                  ),
                              ),
                              h(
                                  'div',
                                  { class: 'end' },
                                  h('div', { class: 'small muted' }, FW.day(e.date)),
                                  e.cost
                                      ? h('div', { class: 'small' }, FW.num(e.cost, { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
                                      : null,
                              ),
                          ),
                      ),
                  )
                : h('div', { class: 'empty' }, 'Nothing recorded yet.'),
        );

    if (d.history) return h('div', { class: 'stack' }, FW.head('Car history', `${d.history.length} entries`), timeline(d.history));

    const car = (v) =>
        h(
            'div',
            { class: 'card', style: 'padding:12px;display:flex;justify-content:space-between;gap:12px;align-items:center' },
            h(
                'div',
                null,
                h('div', { style: 'font-weight:650' }, v.name),
                h('div', { class: 'meta small' }, [v.license_plate, dist(v.current_mileage_km)].filter(Boolean).join(' · ')),
                v.status === 'attention'
                    ? h(
                          'div',
                          { class: 'small warn' },
                          [
                              v.maintenance_needing_attention ? `${v.maintenance_needing_attention} service due` : null,
                              v.documents_expiring ? `${v.documents_expiring} document expiring` : null,
                          ]
                              .filter(Boolean)
                              .join(' · ') || 'Needs attention',
                      )
                    : h('div', { class: 'small', style: 'color:var(--fw-ink)' }, 'All good'),
            ),
            h('button', { class: 'link', type: 'button', onclick: () => FW.open(v.url) }, 'Open ↗'),
        );

    if (d.vehicles) {
        return h(
            'div',
            { class: 'stack' },
            FW.head('Cars', `${d.vehicles.length}`),
            d.vehicles.length ? d.vehicles.map(car) : h('div', { class: 'card empty' }, 'No cars yet.'),
        );
    }

    // One car.
    const markDone = async (s, btn) => {
        btn.disabled = true;
        const out = await FW.act({
            tool: 'vehicles_complete_maintenance',
            args: { vehicle_id: d.id, schedule_id: s.schedule_id },
            tell: `marked "${s.name}" done on ${d.name} (today, current odometer)`,
        });
        if (out) await FW.reload('vehicles_get');
        else btn.disabled = false;
    };
    const due = (s) =>
        [
            s.one_off ? 'one-off' : null,
            s.next_due_date ? `due ${FW.day(s.next_due_date)}` : null,
            s.next_due_km ? `at ${dist(s.next_due_km)}` : null,
            s.last_done_on ? `last ${FW.day(s.last_done_on)}` : null,
        ]
            .filter(Boolean)
            .join(' · ');

    return h(
        'div',
        { class: 'stack' },
        FW.head(d.name, [d.license_plate, d.fuel_type].filter(Boolean).join(' · '), d.url),
        h(
            'div',
            { class: 'card', style: 'padding:12px' },
            h('div', { class: 'meta small' }, 'Odometer'),
            h('div', { class: 'stat' }, dist(d.current_mileage_km)),
            d.fuel
                ? h(
                      'div',
                      { class: 'meta small' },
                      `${FW.num(d.fuel.avg_consumption_l_per_100km ?? 0, { maximumFractionDigits: 1 })} L/100km average · ${d.fuel.fills} fill-ups`,
                  )
                : null,
        ),
        d.maintenance?.length
            ? h(
                  'div',
                  { class: 'section' },
                  h('h2', null, 'Maintenance'),
                  h(
                      'div',
                      { class: 'card' },
                      h(
                          'ul',
                          { class: 'rows' },
                          d.maintenance.map((s) => {
                              const [cls, label] = MAINT_PILL[s.status] || ['', s.status];
                              return h(
                                  'li',
                                  { style: 'align-items:center' },
                                  h(
                                      'div',
                                      { class: 'grow' },
                                      h('div', null, s.name, ' ', h('span', { class: `pill ${cls}` }, label)),
                                      h('div', { class: 'meta' }, due(s)),
                                  ),
                                  s.status === 'good'
                                      ? null
                                      : h('button', { class: 'btn sm', type: 'button', onclick: (e) => markDone(s, e.currentTarget) }, 'Mark done'),
                              );
                          }),
                      ),
                  ),
              )
            : null,
        d.documents?.length
            ? h(
                  'div',
                  { class: 'section' },
                  h('h2', null, 'Documents'),
                  h(
                      'div',
                      { class: 'card' },
                      h(
                          'ul',
                          { class: 'rows' },
                          d.documents.map((doc) =>
                              h(
                                  'li',
                                  null,
                                  h('div', { class: 'grow' }, doc.title || typeLabel(doc.type), h('div', { class: 'meta' }, typeLabel(doc.type))),
                                  doc.expires_on
                                      ? h(
                                            'span',
                                            { class: `end small ${doc.expiring_soon || FW.overdue(doc.expires_on) ? 'danger' : 'muted'}` },
                                            `expires ${FW.day(doc.expires_on)}`,
                                        )
                                      : null,
                              ),
                          ),
                      ),
                  ),
              )
            : null,
        d.recent?.length ? h('div', { class: 'section' }, h('h2', null, 'Recent work'), timeline(d.recent)) : null,
    );
});
