// Pre-built module frontend (shell runtime, no bundler, no second React). Extends the Contacts
// `person` primitive's slots and adds a home-screen widget:
//   - person.editor.fields : a birthdate picker in the contact editor
//   - person.row.badges    : a 🎂 badge (age + how soon) on the contact row
//   - dashboard.widgets    : an "Upcoming birthdays" card that fetches /m/birthdays/upcoming
const { React, registerSlot } = window.Familywell;
const h = React.createElement;

// Editor field: a birthdate. Persists via onPatch (the contacts endpoint); the backend person.saved
// hook stores it. Age is shown once known (it comes back on the next payload).
function BirthdateField(props) {
    const person = props.person || {};
    const onPatch = props.onPatch;
    const [val, setVal] = React.useState(person.birthdate || '');

    function change(e) {
        const v = e.target.value;
        setVal(v);
        if (onPatch) onPatch({ birthdate: v || '' }); // empty clears it
    }

    return h(
        'div',
        { className: 'grid gap-2', 'data-testid': 'birthdate-field' },
        h('label', { className: 'text-muted-foreground text-xs font-semibold tracking-widest uppercase' }, 'Birthday'),
        h('input', {
            type: 'date',
            value: val,
            onChange: change,
            className: 'border-border text-primary h-9 rounded-lg border bg-transparent px-3 text-sm',
        }),
        person.age != null ? h('span', { className: 'text-muted-foreground text-xs' }, 'Age ' + person.age) : null,
    );
}

// Row badge: 🎂 with current age and how soon the next birthday is.
function BirthdayBadge(props) {
    const person = props.person || {};
    if (!person.birthdate) return null;
    const days = person.daysUntilBirthday;
    const soon = days === 0 ? 'today' : days != null ? 'in ' + days + 'd' : '';
    return h(
        'span',
        {
            'data-testid': 'birthday-badge',
            className:
                'shrink-0 inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-[11px] text-muted-foreground',
            title: 'Next birthday ' + (person.nextBirthday || ''),
        },
        h('span', null, '🎂'),
        person.age != null ? h('span', null, person.age) : null,
        soon ? h('span', { className: 'opacity-70' }, soon) : null,
    );
}

function whenLabel(b) {
    if (b.daysUntil === 0) return 'Today!';
    if (b.daysUntil === 1) return 'Tomorrow';
    return 'in ' + b.daysUntil + ' days · ' + b.date;
}

// Home widget: upcoming birthdays for the family. Fetches its own data (like the hello widget),
// renders nothing while loading or when there is nothing coming up.
function BirthdaysWidget() {
    const [items, setItems] = React.useState(null);
    React.useEffect(function () {
        fetch('/m/birthdays/upcoming', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { birthdays: [] }))
            .then((d) => setItems(d.birthdays || []))
            .catch(() => setItems([]));
    }, []);

    if (items === null || items.length === 0) return null;

    return h(
        'section',
        { className: 'flex flex-col gap-3', 'data-testid': 'birthdays-widget' },
        h('h2', { className: 'font-heading text-primary px-1 text-lg' }, 'Upcoming birthdays'),
        h(
            'div',
            { className: 'bg-surface-card flex flex-col divide-y rounded-xl border' },
            items.map((b) =>
                h(
                    'div',
                    { key: b.personId, className: 'flex items-center gap-3 px-3 py-2.5' },
                    h('span', { className: 'text-lg' }, '🎂'),
                    h(
                        'span',
                        { className: 'min-w-0 flex-1' },
                        h('span', { className: 'text-primary block truncate text-[15px]' }, b.name),
                        h('span', { className: 'text-muted-foreground block text-xs' }, whenLabel(b)),
                    ),
                    b.turning != null ? h('span', { className: 'text-muted-foreground shrink-0 text-xs' }, 'turns ' + b.turning) : null,
                ),
            ),
        ),
    );
}

registerSlot('person.editor.fields', BirthdateField);
registerSlot('person.row.badges', BirthdayBadge);
registerSlot('dashboard.widgets', BirthdaysWidget);
