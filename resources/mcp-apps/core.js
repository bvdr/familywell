// Shared runtime for the MCP Apps widgets (App\Mcp\Apps\Widget). Sits on laravel/mcp's inlined SDK
// (window.createMcpApp), which speaks the host's postMessage JSON-RPC. A widget file only calls
// FW.start(render): render(data, args) returns a DOM node for the tool result `data` (the same JSON the
// model sees) and the tool call's `args`. Plain DOM, no framework: each widget is a few dozen lines and
// a bundler would be the bigger thing to maintain.
//
// Changes go through our own write tools (FW.call), so the server enforces scopes and sharing exactly
// as for the assistant. The UI updates optimistically and reverts with a toast if the call fails.
// eslint-disable-next-line no-redeclare, @typescript-eslint/no-unused-vars -- the global every widget file uses
const FW = (() => {
    const root = document.getElementById('app');
    let app = null;
    let args = {};
    let data = null;
    let render = null;
    let toastTimer = null;

    // h('li', {class: 'x', onclick: fn}, child, 'text', [more]) -> Element. Text is always a text node,
    // never HTML, so nothing from the server can inject markup.
    function h(tag, props, ...kids) {
        const el = document.createElement(tag);
        for (const [k, v] of Object.entries(props || {})) {
            if (v === null || v === undefined || v === false) continue;
            if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
            else if (k === 'checked' || k === 'disabled' || k === 'value') el[k] = v;
            else el.setAttribute(k, v === true ? '' : v);
        }
        for (const kid of kids.flat(Infinity)) {
            if (kid === null || kid === undefined || kid === false) continue;
            el.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
        }
        return el;
    }

    // A tools/call result -> its JSON payload. Our tools answer with one JSON text block (Response::json).
    function payload(result) {
        if (!result) throw new Error('No response.');
        const text = (result.content || [])
            .filter((c) => c.type === 'text')
            .map((c) => c.text)
            .join('\n');
        if (result.isError) throw new Error(text || 'Something went wrong.');
        if (result.structuredContent) return result.structuredContent;
        try {
            return JSON.parse(text);
        } catch {
            throw new Error(text || 'Unexpected response.');
        }
    }

    function draw() {
        if (!render || data === null) return;
        // A full redraw replaces the quick-add field; keep typing flow by refocusing it if it had focus.
        const refocus = !!document.activeElement?.closest?.('.add');
        try {
            root.replaceChildren(render(data, args));
            if (refocus) root.querySelector('.add input')?.focus();
        } catch (e) {
            root.replaceChildren(h('p', { class: 'danger pad' }, 'Could not show this: ' + e.message));
        }
    }

    function toast(msg) {
        document.querySelector('.toast')?.remove();
        document.body.append(h('div', { class: 'toast', role: 'status' }, msg));
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => document.querySelector('.toast')?.remove(), 4000);
    }

    // A write tool that isn't registered for this token means the connection is read-only for that module
    // (App\Mcp\Tool::shouldRegister hides it): say so in words people can act on.
    function friendly(err) {
        const msg = err?.message || String(err);
        return /tool .*not found/i.test(msg) ? 'This connection can only read here. Reconnect Familywell with "Read & write" to make changes.' : msg;
    }

    async function call(name, a) {
        return payload(await app.callServerTool(name, a || {}));
    }

    // Optimistic change: apply() mutates state and we redraw now; the tool call runs; on failure revert()
    // undoes it, we redraw and explain. Returns the tool's payload (or null on failure).
    async function act({ apply, revert, tool, args: a, tell }) {
        // Only redraw for an optimistic change: a redraw also replaces the quick-add field mid-save.
        if (apply) {
            apply();
            draw();
        }
        try {
            const out = await call(tool, a);
            if (tell) note(tell);
            return out;
        } catch (e) {
            if (revert) {
                revert();
                draw();
            }
            toast(friendly(e));
            return null;
        }
    }

    // Re-run the tool that produced this widget (same args) and redraw, e.g. after adding something.
    async function reload(tool) {
        try {
            data = await call(tool, args);
            draw();
        } catch (e) {
            toast(friendly(e));
        }
    }

    // Tell the model what the person did in the widget, so the next turn knows ("ticked Milk").
    // Each update replaces the previous one (spec), so keep a short running log. Optional on hosts.
    const log = [];
    function note(text) {
        log.push(text);
        app.updateModelContext({
            content: [{ type: 'text', text: 'In the Familywell widget the person: ' + log.slice(-20).join('; ') + '.' }],
        }).catch(() => {});
    }

    function open(url) {
        app.openLink(url).catch(() => window.open(url, '_blank', 'noopener'));
    }

    // Formatting in the host's locale/timezone when given.
    let locale;
    const dayMs = 86400000;
    function today() {
        const d = new Date();
        return new Date(d.getFullYear(), d.getMonth(), d.getDate());
    }
    function parseDay(iso) {
        const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number);
        return new Date(y, m - 1, d);
    }
    // "Today", "Tomorrow", "Yesterday", "Mon 6 Oct", "6 Oct 2027".
    function day(iso) {
        if (!iso) return '';
        const d = parseDay(iso);
        const diff = Math.round((d - today()) / dayMs);
        if (diff === 0) return 'Today';
        if (diff === 1) return 'Tomorrow';
        if (diff === -1) return 'Yesterday';
        const sameYear = d.getFullYear() === today().getFullYear();
        return d.toLocaleDateString(
            locale,
            sameYear ? { weekday: 'short', day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' },
        );
    }
    function overdue(iso) {
        return !!iso && parseDay(iso) < today();
    }
    function money(amount, currency) {
        const n = Number(amount);
        try {
            return n.toLocaleString(locale, { style: 'currency', currency: currency || 'EUR' });
        } catch {
            return n.toFixed(2) + (currency ? ' ' + currency : '');
        }
    }
    function num(n, opts) {
        return Number(n).toLocaleString(locale, opts);
    }

    // Header row: title, optional subtitle, "Open" link to the page in Familywell.
    function head(title, sub, url) {
        return h(
            'div',
            { class: 'head' },
            h('div', null, h('h1', null, title), sub ? h('div', { class: 'sub' }, sub) : null),
            url ? h('button', { class: 'link', type: 'button', onclick: () => open(url) }, 'Open in Familywell ↗') : null,
        );
    }

    // Quick-add field; onAdd(text) resolves when saved. Deliberately NOT a <form>: hosts sandbox the
    // iframe and may omit allow-forms, which blocks the submit event outright. Enter or the button adds.
    function addForm(placeholder, onAdd) {
        const input = h('input', { type: 'text', placeholder, 'aria-label': placeholder, maxlength: 255 });
        const btn = h('button', { class: 'btn', type: 'button' }, 'Add');
        const submit = async () => {
            const text = input.value.trim();
            if (!text || btn.disabled) return;
            btn.disabled = true;
            try {
                await onAdd(text);
                input.value = '';
            } finally {
                btn.disabled = false;
            }
        };
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.isComposing) {
                e.preventDefault();
                submit();
            }
        });
        btn.addEventListener('click', submit);
        return h('div', { class: 'add' }, input, btn);
    }

    function start(fn) {
        render = fn;
        window.createMcpApp(async (a) => {
            app = a;
            locale = a.getHostContext()?.locale;
            a.onToolInput((p) => {
                args = p?.arguments || {};
            });
            a.onToolResult((result) => {
                try {
                    data = payload(result);
                    draw();
                } catch (e) {
                    root.replaceChildren(h('p', { class: 'danger pad' }, friendly(e)));
                }
            });
            a.onToolCancelled(() => root.replaceChildren(h('p', { class: 'muted pad' }, 'Cancelled.')));
            a.autoResize();
        });
    }

    return {
        h,
        start,
        draw,
        call,
        act,
        reload,
        note,
        toast,
        friendly,
        open,
        day,
        overdue,
        money,
        num,
        head,
        addForm,
        get data() {
            return data;
        },
        set data(v) {
            data = v;
        },
    };
})();
