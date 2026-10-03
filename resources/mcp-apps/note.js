// notes_get: the markdown-lite body rendered like the app's read view (resources/js/lib/notes.tsx:
// # headings, - [ ] checklists, - bullets, **bold**), with tickable checklists saved via notes_update.
const NOTE_CHECK = /^- \[( |x|X)\] (.*)$/;

FW.start((n) => {
    const { h } = FW;
    const bold = (text) =>
        text.split(/(\*\*[^*]+\*\*)/g).map((p) => (p.length > 4 && p.startsWith('**') && p.endsWith('**') ? h('strong', null, p.slice(2, -2)) : p));

    // Re-read the note first and toggle THAT body (matching the line by index + text), so a tick never
    // overwrites edits someone made since the widget was drawn.
    const toggle = async (index, text, box) => {
        box.disabled = true;
        try {
            const fresh = await FW.call('notes_get', { note_id: n.id });
            const lines = fresh.body.split('\n');
            const m = lines[index]?.match(NOTE_CHECK);
            if (!m || m[2] !== text) throw new Error('This note changed meanwhile. Ask to show it again.');
            const done = m[1].toLowerCase() !== 'x';
            lines[index] = `- [${done ? 'x' : ' '}] ${m[2]}`;
            const saved = await FW.call('notes_update', { note_id: n.id, body: lines.join('\n') });
            FW.data = saved;
            FW.note(`${done ? 'ticked' : 'un-ticked'} "${text.replace(/\*\*/g, '')}" in note "${n.title}"`);
        } catch (e) {
            FW.toast(FW.friendly(e));
        }
        FW.draw();
    };

    const body = (n.body || '').split('\n').map((raw, i) => {
        if (raw.trim() === '') return h('div', { style: 'height:6px' });
        const hd = raw.match(/^(#{1,3}) (.*)$/);
        if (hd) return h(`h${hd[1].length + 1}`, { style: 'margin:6px 0 2px;font-size:' + [17, 15, 14][hd[1].length - 1] + 'px' }, bold(hd[2]));
        const c = raw.match(NOTE_CHECK);
        if (c) {
            const done = c[1].toLowerCase() === 'x';
            const box = h('input', { type: 'checkbox', checked: done, 'aria-label': c[2], onchange: () => toggle(i, c[2], box) });
            return h(
                'label',
                { style: 'display:flex;gap:8px;align-items:flex-start;cursor:pointer' },
                box,
                h('span', { class: done ? 'strike' : null }, bold(c[2])),
            );
        }
        const b = raw.match(/^[-*] (.*)$/);
        if (b) return h('div', { style: 'display:flex;gap:8px' }, h('span', { class: 'muted' }, '•'), h('span', null, bold(b[1])));
        return h('p', { style: 'margin:0' }, bold(raw));
    });

    return h(
        'div',
        { class: 'stack' },
        FW.head(n.title || 'Untitled note', [n.folder, n.updated_at ? `edited ${FW.day(n.updated_at)}` : null].filter(Boolean).join(' · '), n.url),
        h(
            'div',
            { class: 'card', style: 'padding:12px 14px;display:flex;flex-direction:column;gap:4px;overflow-wrap:anywhere' },
            (n.body || '').trim() ? body : h('div', { class: 'muted' }, 'Empty note.'),
        ),
    );
});
