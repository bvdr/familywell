import { expect, type Page, test } from '@playwright/test';

// Edit-and-save flows, at phone (390) and desktop (1280) width like every viewport check. Each test opens
// a real editor, changes one field, saves the way a person does, and asserts the new value shows up
// BOTH right away (no reload) and after a reload (it reached the database).
//
// Why "right away" matters: the Tasks edit drawer once saved fine but the list kept showing the old
// title until a full reload (its local mirror only re-synced on id/done changes), so to a person on the
// installed PWA "nothing saved", and editing again sent the stale values back. A reload-only check
// would have passed.
//
// The mobile and desktop projects run in parallel against one database, so each picks a different
// record (`slot`) or creates its own, and writes a unique marker, so the two never race on a row.

const slot = (project: string) => (project === 'mobile' ? 0 : 1);
const marker = (project: string) => `Save check ${project} ${Date.now()}`;

// The href of the nth link on `path` matching `re` (detail pages follow the seeded data's ids).
async function nthHref(page: Page, path: string, re: RegExp, n: number) {
    await page.goto(path);
    const hrefs = await page.locator('a[href]').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href));
    const href = [...new Set(hrefs.filter((h) => re.test(h)))][n];
    expect(href, `link ${re} #${n} on ${path}`).toBeTruthy();
    return href!;
}

test('tasks: edit a task, the list shows the change', async ({ page }, info) => {
    const list = await nthHref(page, '/m/tasks', /\/m\/tasks\/lists\/\d+$/, 0);
    await page.goto(list);
    const original = marker(info.project.name);
    await page.getByPlaceholder('Add a task…').fill(original);
    await page.getByPlaceholder('Add a task…').press('Enter');
    await expect(page.getByRole('button', { name: original })).toBeVisible();

    // The task drawer has no Save button: it auto-saves and flushes on close.
    const edited = `${original} edited`;
    await page.getByRole('button', { name: original }).click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('input').first().fill(edited);
    await dialog.getByRole('button', { name: 'Close' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('button', { name: edited })).toBeVisible();

    // Re-opening shows the saved value, not the stale one (stale values would be sent back on the next edit).
    await page.getByRole('button', { name: edited }).click();
    await expect(dialog.locator('input').first()).toHaveValue(edited);
    await dialog.getByRole('button', { name: 'Close' }).click();

    await page.reload();
    await expect(page.getByRole('button', { name: edited })).toBeVisible();
});

test('spending: edit an expense note', async ({ page }, info) => {
    const event = await nthHref(page, '/m/spending', /\/m\/spending\/events\/\d+$/, 0);
    await page.goto(event);
    const note = marker(info.project.name);
    // Expense rows show the payer · category line; pick a different one per project.
    await page.getByRole('button', { name: /·/ }).nth(slot(info.project.name)).click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('#note').fill(note);
    await dialog.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('button', { name: new RegExp(note) })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('button', { name: new RegExp(note) })).toBeVisible();
});

test('contacts: edit a contact name', async ({ page }, info) => {
    await page.goto('/m/contacts');
    const name = marker(info.project.name);
    await page.locator('main').getByRole('button', { name: /🎂/ }).nth(slot(info.project.name)).click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('input').first().fill(name);
    await dialog.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('button', { name: new RegExp(name) })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('button', { name: new RegExp(name) })).toBeVisible();
});

test('inventory: edit an item name', async ({ page }, info) => {
    const item = await nthHref(page, '/m/inventory/items', /\/m\/inventory\/items\/\d+$/, slot(info.project.name));
    await page.goto(item);
    const name = marker(info.project.name);
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('input').first().fill(name);
    await dialog.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText(name).first()).toBeVisible();
    await page.reload();
    await expect(page.getByText(name).first()).toBeVisible();
});

test('vehicles: edit a car', async ({ page }, info) => {
    const car = await nthHref(page, '/m/vehicles', /\/m\/vehicles\/\d+$/, slot(info.project.name));
    await page.goto(`${car}/edit`);
    const notes = marker(info.project.name);
    await page.locator('textarea').first().fill(notes);
    // The car form is a page, not a drawer; it saves and stays put (`back()`), so wait for the save itself.
    const saved = page.waitForResponse((r) => r.request().method() === 'PATCH' && /\/m\/vehicles\/\d+$/.test(r.url()));
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    expect((await saved).status()).toBe(303); // 302/422 = validation error, not a save
    await page.reload();
    await expect(page.locator('textarea').first()).toHaveValue(notes);
});

test('expense import: upload a statement, review, import, undo', async ({ page }, info) => {
    // A tiny Wise statement with rows unique to this run (the phone and desktop projects run in parallel).
    const shop = marker(info.project.name);
    const day = new Date().toISOString().slice(0, 10).split('-').reverse().join('-'); // dd-mm-yyyy, like Wise
    // A unique amount too: an earlier run's expense with the same amount, day and a similar name would (rightly)
    // be flagged as a possible duplicate and come in unticked.
    const amount = (10 + 5000 * slot(info.project.name) + (Date.now() % 400000) / 100).toFixed(2); // per-project range
    const csv = [
        '"TransferWise ID",Date,Amount,Currency,Description,Merchant',
        `CARD-${Date.now()},${day},-${amount},USD,"Card transaction issued by ${shop}","${shop}"`,
        `IN-${Date.now()},${day},100.00,USD,"Received money from ${shop}",`,
    ].join('\n');

    await page.goto('/m/expense-import');
    await page.locator('input[type=file]').setInputFiles({ name: 'wise.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    await page.waitForURL(/\/m\/expense-import\/batches\/\d+$/);
    const review = page.url();

    // The card payment comes in ticked, the incoming money unticked.
    await expect(page.getByRole('checkbox', { name: shop, exact: true })).toBeChecked();
    await expect(page.getByRole('checkbox', { name: `Received money from ${shop}` })).not.toBeChecked();
    await page.getByRole('button', { name: 'Import 1 expenses' }).click();
    await expect(page.getByText('1 expenses were added to Spending.')).toBeVisible();

    // It's a real Spending expense now...
    await page.getByRole('link', { name: 'Open the list' }).click();
    await expect(page.getByText(shop).first()).toBeVisible();

    // ...and Undo (on the import's own page) takes it back out.
    await page.goto(review);
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect(page.getByText('This import was undone.')).toBeVisible();
});
