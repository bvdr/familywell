import { expect, test } from '@playwright/test';
import { expectCleanLayout } from './layout-check';

// Every page of the app. Adding a page? Add it here too, or it isn't covered.
const PAGES: [string, string][] = [
    ['dashboard', '/dashboard'],
    ['family-members', '/family/members'],
    ['family-modules', '/family/modules'],
    ['notifications', '/notifications'],
    ['birthdays', '/m/birthdays'],
    ['calendar', '/m/calendar'],
    ['contacts', '/m/contacts'],
    ['inventory', '/m/inventory'],
    ['inventory-items', '/m/inventory/items'],
    ['inventory-categories', '/m/inventory/categories'],
    ['inventory-locations', '/m/inventory/locations'],
    ['inventory-needs-attention', '/m/inventory/needs-attention'],
    ['inventory-replenishment', '/m/inventory/replenishment'],
    ['inventory-settings', '/m/inventory/settings'],
    ['notes', '/m/notes'],
    ['projects', '/m/projects'],
    ['recipes', '/m/recipes'],
    ['recipes-new', '/m/recipes/new'],
    ['shopping', '/m/shopping'],
    ['shopping-shops', '/m/shopping/shops'],
    ['shopping-settings', '/m/shopping/settings'],
    ['spending', '/m/spending'],
    ['spending-settings', '/m/spending/settings'],
    ['tasks', '/m/tasks'],
    ['vehicles', '/m/vehicles'],
    ['vehicles-new', '/m/vehicles/new'],
    ['vehicles-providers', '/m/vehicles/providers'],
    ['vehicles-settings', '/m/vehicles/settings'],
    ['expense-import', '/m/expense-import'],
    ['expense-import-settings', '/m/expense-import/settings'],
    ['settings', '/settings'],
    ['settings-profile', '/settings/profile'],
    ['settings-sign-in', '/settings/sign-in'],
    ['onboarding-about', '/onboarding/about'],
    // Onboarding steps as the demo owner: ?preview=1 renders a step for someone already onboarded (GET only).
    ['onboarding-welcome', '/onboarding?preview=1'],
    ['onboarding-family', '/onboarding/create?preview=1'],
    ['onboarding-invite', '/onboarding/invite?preview=1'],
    ['onboarding-modules', '/onboarding/modules?preview=1'],
    ['onboarding-install', '/onboarding/install?preview=1'],
    ['onboarding-notifications', '/onboarding/notifications?preview=1'],
    ['onboarding-ready', '/onboarding/ready?preview=1'],
    ['settings-appearance', '/settings/appearance'],
    ['settings-language', '/settings/language'],
    ['settings-notifications', '/settings/notifications'],
    ['settings-connected-apps', '/settings/connected-apps'],
    ['settings-media', '/settings/media'],
    ['settings-billing', '/settings/billing'],
    ['settings-recently-deleted', '/settings/recently-deleted'],
];

// Detail pages: opened from their index via the first matching link, so they follow the seeded data's ids.
// An optional 4th item is a sub-page path appended to that link (e.g. a car's /timeline).
const DETAILS: [string, string, RegExp, string?][] = [
    ['calendar-event', '/m/calendar', /\/m\/calendar\/events\/\d+$/],
    ['calendar-external-event', '/m/calendar', /\/m\/calendar\/external\/\d+$/],
    ['note', '/m/notes', /\/m\/notes\/\d+$/],
    ['project', '/m/projects', /\/m\/projects\/\d+$/],
    ['recipe', '/m/recipes', /\/m\/recipes\/\d+$/],
    ['shopping-list', '/m/shopping', /\/m\/shopping\/lists\/\d+$/],
    ['spending-list', '/m/spending', /\/m\/spending\/events\/\d+$/],
    ['task-list', '/m/tasks', /\/m\/tasks\/lists\/\d+$/],
    ['vehicle', '/m/vehicles', /\/m\/vehicles\/\d+$/],
    ['vehicle-history', '/m/vehicles', /\/m\/vehicles\/\d+$/, '/timeline'],
    ['inventory-item', '/m/inventory/items', /\/m\/inventory\/items\/\d+$/],
    ['expense-import-review', '/m/expense-import', /\/m\/expense-import\/batches\/\d+$/],
];

const shoot = async (page: import('@playwright/test').Page, name: string, project: string) => {
    // Full page plus the first screen: look at both widths before calling UI work done.
    await page.screenshot({ path: `tests/Viewports/screenshots/${name}-${project}.png`, fullPage: true });
    await page.screenshot({ path: `tests/Viewports/screenshots/${name}-${project}-fold.png` });
};

for (const [name, path] of PAGES) {
    test(name, async ({ page }, info) => {
        await page.goto(path);
        await expectCleanLayout(page, () => shoot(page, name, info.project.name));
    });
}

for (const [name, index, href, sub = ''] of DETAILS) {
    test(name, async ({ page }, info) => {
        await page.goto(index);
        await page.waitForLoadState('networkidle');
        const links = await page.locator('a[href]').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href));
        const url = links.find((h) => href.test(new URL(h).pathname));
        test.skip(!url, `no ${name} link on ${index} in the seeded data`);
        await page.goto(url! + sub);
        await expectCleanLayout(page, () => shoot(page, name, info.project.name));
    });
}

test.describe('signed out', () => {
    test.use({ storageState: { cookies: [], origins: [] } });
    for (const [name, path] of [
        ['login', '/login'],
        ['register', '/register'],
    ] as const) {
        test(name, async ({ page }, info) => {
            await page.goto(path);
            await expectCleanLayout(page, () => shoot(page, name, info.project.name));
        });
    }
});

// The phone navigation overlays: the sidebar drawer and the bottom bar's More sheet. They only exist below md,
// so desktop skips them. Besides the layout check, every row/card must be a comfortable tap target (>= 48px).
test.describe('phone navigation', () => {
    test.skip(({ isMobile }) => !isMobile, 'phone-only overlays');

    const minHeights = async (page: import('@playwright/test').Page, selector: string) =>
        page.locator(selector).evaluateAll((els) => els.map((e) => [e.textContent?.trim(), Math.round(e.getBoundingClientRect().height)] as const));

    test('nav-drawer', async ({ page }, info) => {
        await page.goto('/dashboard');
        await page.getByRole('button', { name: 'Toggle Sidebar' }).click();
        const drawer = page.locator('[data-mobile="true"]');
        await expect(drawer).toBeVisible();
        await expectCleanLayout(page, () => shoot(page, 'nav-drawer', info.project.name));
        for (const [label, h] of await minHeights(page, '[data-mobile="true"] [data-sidebar="menu-button"]')) {
            expect(h, `drawer row "${label}"`).toBeGreaterThanOrEqual(48);
        }
        // The "more below" fade: on while the list can scroll further, off once it reaches the bottom (and never
        // shown when everything fits).
        const list = drawer.locator('[data-sidebar="content"]');
        const scrolls = await list.evaluate((e) => e.scrollHeight > e.clientHeight + 1);
        if (scrolls) {
            await expect(list).toHaveAttribute('data-more', 'true');
            await list.evaluate((e) => e.scrollTo(0, e.scrollHeight));
        }
        await expect(list).not.toHaveAttribute('data-more');
    });

    test('nav-more', async ({ page }, info) => {
        await page.goto('/dashboard');
        await page.locator('nav').getByRole('button', { name: 'More' }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await expectCleanLayout(page, () => shoot(page, 'nav-more', info.project.name));
        for (const [label, h] of await minHeights(page, '[role="dialog"] .grid > div')) {
            expect(h, `More card "${label}"`).toBeGreaterThanOrEqual(48);
        }
        for (const [label, h] of await minHeights(page, 'nav a, nav button')) {
            expect(h, `bottom bar "${label}"`).toBeGreaterThanOrEqual(44); // the pill cells; the + is 56px
        }
    });
});

// Settings home > Modules: one link per enabled module that has a settings page (resources/js/layouts/settings/layout.tsx).
// Every link must open a real page, so a module that moves or drops its settings URL fails here.
test('settings-modules', async ({ page }) => {
    await page.goto('/settings');
    const group = page.getByRole('group', { name: 'Modules' });
    await expect(group).toBeVisible();
    const hrefs = await group.getByRole('link').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).getAttribute('href')!));
    expect(hrefs.length).toBeGreaterThan(0);
    for (const href of hrefs) {
        const res = await page.goto(href);
        expect(res?.status(), href).toBe(200);
    }
});
