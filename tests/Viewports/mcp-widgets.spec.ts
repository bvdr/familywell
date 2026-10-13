import { expect, test } from '@playwright/test';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { findLayoutProblems } from './layout-check';

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
        await page.waitForTimeout(300); // let the size-changed round trip land
        await page.screenshot({ path: `tests/Viewports/screenshots/mcp-${name}-${info.project.name}.png`, fullPage: true });
        const problems = await page.frames()[1].evaluate(findLayoutProblems);
        expect(problems).toEqual([]);
    });
}
