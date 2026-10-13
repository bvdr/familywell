// recipes_list (cards with photos; tap one to open it here), recipes_get / recipes_scale / recipes_create /
// recipes_update (one recipe: photo, live calculator, ingredients, tickable steps, add to shopping).
// Scaling runs right here with recipe-scale.js (inlined before this file), the same code as the web app,
// so the calculator reacts per keystroke without a round trip. Full screen = cooking mode.

// Calculator + cooking state survive redraws (FW.draw rebuilds the DOM).
let REC_list = null; // the list we came from, for "All recipes"
let REC_target = null; // null = as written; {type: 'servings'|'yield'|'ingredient', ...}
let REC_mode = 'servings';
let REC_done = new Set(); // ticked steps (local only: a cooking aid, not saved)
let REC_forId = null;

const recScaleInput = (r) => ({ servings: r.servings, yieldAmount: r.yield_amount, yieldUnit: r.yield_unit, ingredients: r.ingredients || [] });

// The same target as recipes_scale / recipes_add_to_shopping take it.
function recTargetArgs(r, t) {
    if (!t) return {};
    if (t.type === 'servings') return { servings: t.value };
    if (t.type === 'yield') return { yield_amount: t.amount, yield_unit: t.unit };
    return { have_ingredient: r.ingredients[t.index].name, have_amount: t.amount, have_unit: t.unit };
}

function recTargetLabel(r, t) {
    if (!t) return 'as written';
    if (t.type === 'servings') return `${t.value} servings`;
    if (t.type === 'yield') return `${t.amount} ${t.unit || ''}`.trim();
    return `${t.amount} ${t.unit || ''} of ${r.ingredients[t.index].name}`.replace(/\s+/g, ' ');
}

function recMinutes(m) {
    if (!m) return null;
    return m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}` : `${m} min`;
}

async function recOpen(id) {
    try {
        REC_list = FW.data;
        FW.data = await FW.call('recipes_get', { recipe_id: id });
        FW.draw();
        window.scrollTo(0, 0);
    } catch (e) {
        FW.toast(FW.friendly(e));
    }
}

function recList(d) {
    const { h } = FW;
    const items = d.recipes || [];
    return h(
        'div',
        { class: 'stack' },
        FW.head('Recipes', `${items.length} recipe${items.length === 1 ? '' : 's'}`),
        items.length
            ? h(
                  'div',
                  { class: 'rec-grid' },
                  items.map((r) =>
                      h(
                          'button',
                          { class: 'rec-card card', type: 'button', onclick: () => recOpen(r.id) },
                          r.thumb_url
                              ? h('img', { src: r.thumb_url, alt: '', loading: 'lazy' })
                              : h('div', { class: 'rec-noimg', 'aria-hidden': 'true' }, r.title.slice(0, 1).toUpperCase()),
                          h(
                              'div',
                              { class: 'rec-card-body' },
                              h('div', { class: 'rec-card-title' }, r.title),
                              h(
                                  'div',
                                  { class: 'meta small' },
                                  [recMinutes(r.total_minutes), r.servings ? `${r.servings} servings` : null, ...(r.tags || []).slice(0, 2)]
                                      .filter(Boolean)
                                      .join(' · '),
                              ),
                          ),
                      ),
                  ),
              )
            : h('div', { class: 'card empty' }, 'No recipes found.'),
    );
}

function recDetail(r) {
    const { h } = FW;
    if (REC_forId !== r.id) {
        // A new recipe: start from the scale the tool call asked for (recipes_scale), else as written.
        REC_forId = r.id;
        REC_done = new Set();
        REC_target = r.scale?.target || null;
        REC_mode = REC_target?.type || (r.servings ? 'servings' : r.yield_amount ? 'yield' : 'ingredient');
    }
    const input = recScaleInput(r);
    const factor = () => (REC_target ? factorFor(input, REC_target) : 1);

    // Ingredients re-render on their own so typing in the calculator keeps focus.
    const ingBox = h('div', { class: 'card' });
    const factorBadge = h('span', { class: 'pill good' });
    const renderIngredients = () => {
        const f = factor();
        factorBadge.textContent = f === null ? 'can’t scale' : f === 1 ? 'as written' : `×${Math.round(f * 100) / 100}`;
        factorBadge.className = `pill ${f === null ? 'bad' : 'good'}`;
        let group = null;
        const rows = [];
        (r.ingredients || []).forEach((ing) => {
            if ((ing.group || null) !== group) {
                group = ing.group || null;
                if (group) rows.push(h('li', { class: 'rec-group' }, group));
            }
            const s = scaleIngredient(ing, f || 1);
            rows.push(
                h(
                    'li',
                    null,
                    h('span', { class: 'rec-qty' }, s.qty === null ? '' : `${formatQty(s.qty, s.unit)} ${s.unit || ''}`.trim()),
                    h(
                        'span',
                        { class: 'grow' },
                        ing.name,
                        ing.note ? h('span', { class: 'meta' }, ` · ${ing.note}`) : null,
                        s.qty === null ? h('span', { class: 'meta' }, ' · to taste') : null,
                    ),
                ),
            );
        });
        ingBox.replaceChildren(rows.length ? h('ul', { class: 'rows' }, rows) : h('div', { class: 'empty' }, 'No ingredients yet.'));
    };

    const setTarget = (t, tell) => {
        REC_target = t;
        renderIngredients();
        if (tell) FW.note(`scaled "${r.title}" to ${recTargetLabel(r, t)}`);
    };

    // Calculator: one of three ways to set the scale.
    const scalable = (r.ingredients || []).map((ing, i) => ({ ing, i })).filter(({ ing }) => ing.qty);
    const modes = [
        r.servings ? ['servings', 'Servings'] : null,
        r.yield_amount ? ['yield', 'Total'] : null,
        scalable.length ? ['ingredient', 'I have…'] : null,
    ].filter(Boolean);
    const num = (v) => {
        const n = parseFloat(String(v).replace(',', '.'));
        return Number.isFinite(n) && n > 0 ? n : null;
    };

    let control = null;
    if (REC_mode === 'servings' && r.servings) {
        const cur = REC_target?.type === 'servings' ? REC_target.value : r.servings;
        const out = h('span', { class: 'stat', style: 'font-size:22px;min-width:40px;text-align:center' }, String(cur));
        const step = (d) => {
            const v = Math.max(1, (REC_target?.type === 'servings' ? REC_target.value : r.servings) + d);
            out.textContent = String(v);
            setTarget(v === r.servings ? null : { type: 'servings', value: v }, true);
        };
        control = h(
            'div',
            { class: 'rec-ctl' },
            h('button', { class: 'btn ghost', type: 'button', 'aria-label': 'Fewer servings', onclick: () => step(-1) }, '−'),
            out,
            h('button', { class: 'btn ghost', type: 'button', 'aria-label': 'More servings', onclick: () => step(1) }, '+'),
            h('span', { class: 'meta' }, 'servings'),
        );
    } else if (REC_mode === 'yield' && r.yield_amount) {
        const cur = REC_target?.type === 'yield' ? REC_target : { amount: r.yield_amount, unit: r.yield_unit };
        const amount = h('input', { type: 'text', inputmode: 'decimal', value: String(cur.amount), 'aria-label': 'Total to make', class: 'rec-num' });
        const unit = h('input', { type: 'text', value: cur.unit || '', 'aria-label': 'Unit', class: 'rec-unit' });
        const update = (tell) => {
            const a = num(amount.value);
            if (a) setTarget({ type: 'yield', amount: a, unit: unit.value.trim() || r.yield_unit }, tell);
        };
        amount.addEventListener('input', () => update(false));
        unit.addEventListener('input', () => update(false));
        amount.addEventListener('change', () => update(true));
        control = h(
            'div',
            { class: 'rec-ctl' },
            h('span', { class: 'meta' }, 'Make'),
            amount,
            unit,
            h('span', { class: 'meta' }, `(recipe: ${r.yield_amount} ${r.yield_unit || ''})`),
        );
    } else if (scalable.length) {
        const cur = REC_target?.type === 'ingredient' ? REC_target : { index: scalable[0].i, amount: '', unit: scalable[0].ing.unit || '' };
        const pick = FW.picker(
            scalable.map(({ ing, i }) => ({ value: i, label: ing.name })),
            { value: cur.index, label: 'Ingredient' },
        );
        const amount = h('input', {
            type: 'text',
            inputmode: 'decimal',
            value: String(cur.amount),
            placeholder: 'how much',
            'aria-label': 'How much you have',
            class: 'rec-num',
        });
        const unit = h('input', { type: 'text', value: cur.unit || '', 'aria-label': 'Unit', class: 'rec-unit' });
        const update = (tell) => {
            const a = num(amount.value);
            const i = Number(pick.value);
            setTarget(a ? { type: 'ingredient', index: i, amount: a, unit: unit.value.trim() || r.ingredients[i].unit } : null, tell);
        };
        pick.addEventListener('change', () => {
            unit.value = r.ingredients[Number(pick.value)].unit || '';
            update(true);
        });
        amount.addEventListener('input', () => update(false));
        unit.addEventListener('input', () => update(false));
        amount.addEventListener('change', () => update(true));
        control = h('div', { class: 'rec-ctl' }, h('span', { class: 'meta' }, 'I have'), amount, unit, h('span', { class: 'meta' }, 'of'), pick);
    }

    const steps = (r.steps || []).map((text, i) =>
        h(
            'li',
            null,
            h('input', {
                type: 'checkbox',
                checked: REC_done.has(i),
                'aria-label': `Step ${i + 1} done`,
                onchange: (e) => {
                    if (e.currentTarget.checked) REC_done.add(i);
                    else REC_done.delete(i);
                    e.currentTarget.nextSibling.classList.toggle('strike', e.currentTarget.checked);
                },
            }),
            h('div', { class: `grow ${REC_done.has(i) ? 'strike' : ''}` }, h('strong', null, `${i + 1}. `), text),
        ),
    );

    // Add to shopping (only when this connection may write to Shopping: the tool sends the lists).
    let shop = null;
    if (r.shopping_lists?.length) {
        const list = FW.picker(
            r.shopping_lists.map((l) => ({ value: l.id, label: l.name })),
            { label: 'Shopping list' },
        );
        const btn = h('button', { class: 'btn', type: 'button' }, 'Add to list');
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            const out = await FW.act({
                tool: 'recipes_add_to_shopping',
                args: { recipe_id: r.id, list_id: Number(list.value), ...recTargetArgs(r, REC_target) },
                tell: `added the ingredients of "${r.title}" (${recTargetLabel(r, REC_target)}) to a shopping list`,
            });
            btn.disabled = false;
            if (out) FW.toast(`Added ${out.added.length} item${out.added.length === 1 ? '' : 's'} to ${out.list.name}.`);
        });
        shop = h('div', { class: 'add' }, list, btn);
    }

    const meta = [
        r.prep_minutes ? `Prep ${recMinutes(r.prep_minutes)}` : null,
        r.cook_minutes ? `Cook ${recMinutes(r.cook_minutes)}` : null,
        r.servings ? `${r.servings} servings` : null,
        r.yield_amount ? `makes ${r.yield_amount} ${r.yield_unit || ''}`.trim() : null,
    ].filter(Boolean);

    renderIngredients();
    return h(
        'div',
        { class: 'stack' },
        REC_list
            ? h(
                  'button',
                  {
                      class: 'link',
                      type: 'button',
                      style: 'align-self:flex-start',
                      onclick: () => {
                          FW.data = REC_list;
                          REC_list = null;
                          FW.draw();
                      },
                  },
                  '← All recipes',
              )
            : null,
        // The photo sits under the actions row, above the title (a hero, like the app's recipe page).
        FW.head(r.title, meta.join(' · '), r.url, { hero: r.photo_url ? h('img', { class: 'rec-hero', src: r.photo_url, alt: r.title }) : null }),
        r.description ? h('p', { class: 'muted', style: 'margin:0;white-space:pre-line' }, r.description) : null,
        r.tags?.length
            ? h(
                  'div',
                  { class: 'tabs' },
                  r.tags.map((t) => h('span', { class: 'pill' }, t)),
              )
            : null,
        modes.length
            ? h(
                  'div',
                  { class: 'card', style: 'padding:12px;display:flex;flex-direction:column;gap:10px' },
                  h(
                      'div',
                      { style: 'display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap' },
                      h(
                          'div',
                          { class: 'tabs' },
                          modes.map(([k, label]) =>
                              h(
                                  'button',
                                  {
                                      class: 'btn sm',
                                      type: 'button',
                                      'aria-pressed': String(REC_mode === k),
                                      onclick: () => {
                                          REC_mode = k;
                                          FW.draw();
                                      },
                                  },
                                  label,
                              ),
                          ),
                      ),
                      h(
                          'div',
                          { style: 'display:flex;gap:8px;align-items:center' },
                          factorBadge,
                          REC_target
                              ? h(
                                    'button',
                                    {
                                        class: 'link',
                                        type: 'button',
                                        onclick: () => {
                                            setTarget(null, true);
                                            FW.draw();
                                        },
                                    },
                                    'Reset',
                                )
                              : null,
                      ),
                  ),
                  control,
              )
            : null,
        h('div', { class: 'section' }, h('h2', null, 'Ingredients'), ingBox),
        shop,
        steps.length ? h('div', { class: 'section' }, h('h2', null, 'Method'), h('div', { class: 'card' }, h('ul', { class: 'rows' }, steps))) : null,
        r.notes
            ? h('div', { class: 'section' }, h('h2', null, 'Notes'), h('div', { class: 'card', style: 'padding:12px;white-space:pre-line' }, r.notes))
            : null,
        r.source_url
            ? h(
                  'button',
                  { class: 'link', type: 'button', style: 'align-self:flex-start', onclick: () => FW.open(r.source_url) },
                  'Original recipe ↗',
              )
            : null,
    );
}

FW.start((d) => (d.recipes ? recList(d) : recDetail(d)), { fullscreen: true });
