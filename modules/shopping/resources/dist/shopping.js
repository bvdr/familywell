// Pre-built module frontend: shell runtime + fetch (CSRF via XSRF-TOKEN cookie), no bundler.
const { React, registerSlot } = window.Familywell;

function csrf() {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}
async function api(method, url, body) {
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    return res.ok ? res.json() : null;
}

function ShoppingWidget() {
    const [items, setItems] = React.useState([]);
    const [name, setName] = React.useState('');
    const load = React.useCallback(() => {
        api('GET', '/m/shopping/items').then((d) => d && setItems(d));
    }, []);
    React.useEffect(() => {
        load();
        const fid = window.__familywellFamilyId;
        if (window.Echo && fid) {
            window.Echo.private('family.' + fid).listen('.ShoppingChanged', load);
            return () => window.Echo.leave('family.' + fid);
        }
    }, [load]);
    async function add(e) {
        e.preventDefault();
        if (!name.trim()) return;
        await api('POST', '/m/shopping/items', { name, visibility: 'shared' });
        setName('');
        load();
    }
    async function toggle(id) {
        await api('PATCH', '/m/shopping/items/' + id);
        load();
    }
    return React.createElement(
        'div',
        { className: 'rounded-lg border p-4', 'data-testid': 'shopping-widget' },
        React.createElement('h3', { className: 'font-semibold mb-2' }, 'Shopping List'),
        React.createElement(
            'form',
            { onSubmit: add, className: 'flex gap-2 mb-3' },
            React.createElement('input', {
                value: name,
                onChange: (e) => setName(e.target.value),
                placeholder: 'Add an item',
                'data-testid': 'shopping-input',
                className: 'flex-1 rounded border px-2 py-1 text-sm',
            }),
            React.createElement('button', { type: 'submit', className: 'rounded bg-primary text-primary-foreground px-3 py-1 text-sm' }, 'Add'),
        ),
        items.length === 0
            ? React.createElement('p', { className: 'text-sm text-muted-foreground', 'data-testid': 'shopping-empty' }, 'Nothing on the list.')
            : React.createElement(
                  'ul',
                  { 'data-testid': 'shopping-list', className: 'space-y-1' },
                  items.map((it) =>
                      React.createElement(
                          'li',
                          { key: it.id, className: 'flex items-center gap-2 text-sm' },
                          React.createElement('input', { type: 'checkbox', checked: !!it.bought, onChange: () => toggle(it.id) }),
                          React.createElement('span', { className: it.bought ? 'line-through text-muted-foreground' : '' }, it.name),
                      ),
                  ),
              ),
    );
}
registerSlot('dashboard.widgets', ShoppingWidget);
