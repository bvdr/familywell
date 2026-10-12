import { expect, test as setup } from '@playwright/test';

// /demo signs in as the seeded demo family's owner. Done once (it is throttled) and shared by every test.
setup('sign in as the demo family', async ({ page }) => {
    await page.goto('/demo');
    await expect(page).toHaveURL(/\/dashboard/);
    await page.context().storageState({ path: 'tests/Viewports/.auth/demo.json' });
});
