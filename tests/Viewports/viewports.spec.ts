import { test } from '@playwright/test';
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
    ['settings-profile', '/settings/profile'],
    ['settings-password', '/settings/password'],
    ['settings-appearance', '/settings/appearance'],
    ['settings-language', '/settings/language'],
    ['settings-notifications', '/settings/notifications'],
    ['settings-connected-apps', '/settings/connected-apps'],
    ['settings-media', '/settings/media'],
    ['settings-billing', '/settings/billing'],
    ['settings-recently-deleted', '/settings/recently-deleted'],
];

// Detail pages: opened from their index via the first matching link, so they follow the seeded data's ids.
const DETAILS: [string, string, RegExp][] = [
    ['calendar-event', '/m/calendar', /\/m\/calendar\/events\/\d+$/],
    ['note', '/m/notes', /\/m\/notes\/\d+$/],
    ['project', '/m/projects', /\/m\/projects\/\d+$/],
    ['recipe', '/m/recipes', /\/m\/recipes\/\d+$/],
    ['shopping-list', '/m/shopping', /\/m\/shopping\/lists\/\d+$/],
    ['spending-list', '/m/spending', /\/m\/spending\/events\/\d+$/],
    ['task-list', '/m/tasks', /\/m\/tasks\/lists\/\d+$/],
    ['vehicle', '/m/vehicles', /\/m\/vehicles\/\d+$/],
    ['inventory-item', '/m/inventory/items', /\/m\/inventory\/items\/\d+$/],
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

for (const [name, index, href] of DETAILS) {
    test(name, async ({ page }, info) => {
        await page.goto(index);
        await page.waitForLoadState('networkidle');
        const links = await page.locator('a[href]').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href));
        const url = links.find((h) => href.test(new URL(h).pathname));
        test.skip(!url, `no ${name} link on ${index} in the seeded data`);
        await page.goto(url!);
        await expectCleanLayout(page, () => shoot(page, name, info.project.name));
    });
}

test.describe('signed out', () => {
    test.use({ storageState: { cookies: [], origins: [] } });
    for (const [name, path] of [
        ['login', '/login'],
        ['register', '/register'],
        ['forgot-password', '/forgot-password'],
    ] as const) {
        test(name, async ({ page }, info) => {
            await page.goto(path);
            await expectCleanLayout(page, () => shoot(page, name, info.project.name));
        });
    }
});
