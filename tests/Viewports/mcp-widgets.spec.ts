import { expect, test } from '@playwright/test';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { expectCleanLayout } from './layout-check';

// Every MCP App widget view (resources/mcp-apps) at the widths the assistant hosts render them: ~360px
// (Claude/ChatGPT on a phone) and ~700px (desktop chat column). Fixtures are the real widget page + the real
// tool result for the seeded demo family, written by tests/Feature/Mcp/WidgetFixturesTest.php (Pest runs
// first in CI; locally run that test before this spec). Each view is mounted the way a host does it: a
// sandboxed iframe (allow-scripts only) answering ui/initialize and pushing the tool input + result.
const dir = 'tests/Viewports/mcp-fixtures';
const fixtures = existsSync(dir) ? readdirSync(dir).filter((f) => f.endsWith('.json')) : [];

test('MCP widget fixtures exist', () => {
    expect(fixtures.length, `no fixtures in ${dir}: run tests/Feature/Mcp/WidgetFixturesTest.php first`).toBeGreaterThan(0);
});

for (const file of fixtures) {
    const name = file.replace(/\.json$/, '');
    test(`mcp ${name}`, async ({ page }, info) => {
        const fx = JSON.parse(readFileSync(`${dir}/${file}`, 'utf8'));
        await page.setContent(
            '<!doctype html><body style="margin:0"><iframe sandbox="allow-scripts" style="border:0;width:100%;display:block;height:200px"></iframe></body>',
        );
        await page.evaluate((fx) => {
            const frame = document.querySelector('iframe')!;
            const post = (msg: object) => frame.contentWindow!.postMessage({ jsonrpc: '2.0', ...msg }, '*');
            window.addEventListener('message', (e) => {
                if (e.source !== frame.contentWindow) return;
                const m = e.data;
                if (m.method === 'ui/initialize') {
                    post({
                        id: m.id,
                        result: {
                            protocolVersion: m.params.protocolVersion,
                            hostInfo: { name: 'viewport-check', version: '1' },
                            hostCapabilities: {},
                            hostContext: { theme: 'light', locale: 'en-US', displayMode: 'inline', availableDisplayModes: ['inline', 'fullscreen'] },
                        },
                    });
                } else if (m.method === 'ui/notifications/initialized') {
                    post({ method: 'ui/notifications/tool-input', params: { arguments: fx.args } });
                    post({ method: 'ui/notifications/tool-result', params: fx.result });
                } else if (m.method === 'ui/notifications/size-changed' && m.params?.height) {
                    frame.style.height = `${Math.ceil(m.params.height)}px`; // hosts size the iframe to its content
                } else if (m.id !== undefined && m.method) {
                    post({ id: m.id, result: {} }); // any other request (tools/call, open-link): a quiet no-op
                }
            });
            frame.srcdoc = fx.html;
        }, fx);

        const frame = page.frameLocator('iframe');
        await expect(frame.locator('#app > *').first()).toBeVisible();
        await expect(frame.getByText('Loading…')).toHaveCount(0);

        // Check the rendered widget with the shared checker (kept identical to the admin's, so it takes a
        // Page): snapshot the iframe's final DOM, scripts stripped, and load it as the page itself at the
        // same width the iframe had.
        const snapshot = () =>
            page.frames()[1].evaluate(() => {
                const doc = document.documentElement.cloneNode(true) as HTMLElement;
                doc.querySelectorAll('script').forEach((s) => s.remove());
                return '<!doctype html>' + doc.outerHTML;
            });
        const views: [string, string][] = [[name, await snapshot()]];
        // Dropdowns (FW.picker) expand inline: check the view with the last one open too.
        const pickers = frame.locator('.picker-ctl');
        if (await pickers.count()) {
            await pickers.last().click();
            await expect(frame.locator('.picker-pop')).toBeVisible();
            views.push([`${name}-picker-open`, await snapshot()]);
        }
        for (const [shot, html] of views) {
            await page.setContent(html);
            await expectCleanLayout(page, () =>
                page.screenshot({ path: `tests/Viewports/screenshots/mcp-${shot}-${info.project.name}.png`, fullPage: true }),
            );
        }
    });
}

// FW.picker (the widgets' dropdown) behaves like a <select> for callers: one `change` per pick, never from
// typing in its search box; a tap outside or Escape closes it.
test('FW.picker searches, picks and closes', async ({ page }) => {
    await page.setContent('<div id="app"></div><p id="out">outside</p>');
    await page.addScriptTag({ content: `window.createMcpApp = () => {}; ${readFileSync('resources/mcp-apps/core.js', 'utf8')}; window.FWX = FW;` });
    await page.evaluate(() => {
        const w = window as unknown as { FWX: { picker: (o: object[], p: object) => HTMLElement }; fired: number };
        const el = w.FWX.picker(
            [
                { value: 1, label: 'Apples' },
                { value: 2, label: 'Pears' },
            ],
            { label: 'Fruit' },
        );
        el.id = 'p';
        w.fired = 0;
        el.addEventListener('change', () => w.fired++);
        document.body.prepend(el);
    });
    await page.click('.picker-ctl');
    await page.fill('.picker-search input', 'pea');
    await expect(page.locator('.picker-opt')).toHaveText(['Pears']);
    await page.click('.picker-opt');
    await expect(page.locator('.picker-pop')).toHaveCount(0);
    expect(
        await page.evaluate(() => [(document.getElementById('p') as HTMLInputElement).value, (window as unknown as { fired: number }).fired]),
    ).toEqual(['2', 1]);
    await page.click('.picker-ctl');
    await page.click('#out');
    await expect(page.locator('.picker-pop')).toHaveCount(0);
    await page.click('.picker-ctl');
    await page.keyboard.press('Escape');
    await expect(page.locator('.picker-pop')).toHaveCount(0);
});
