import { defineConfig } from '@playwright/test';

// UI guardrail (tests/Viewports): every app page at phone and desktop width must have no sideways scroll,
// no cut-off content and no overlapping text. Runs against a seeded app signed in as the demo family:
//   php artisan migrate && php artisan modules:migrate && php artisan db:seed --class=DemoSeeder
// Locally, point it at a running app with VIEWPORTS_URL (e.g. http://localhost:8131); in CI it starts
// `php artisan serve` itself.
const baseURL = process.env.VIEWPORTS_URL ?? 'http://127.0.0.1:8123';

export default defineConfig({
    testDir: 'tests/Viewports',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    // php artisan serve is single-threaded: keep the load modest so pages don't time out.
    workers: process.env.CI ? 2 : 4,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never', outputFolder: 'tests/Viewports/report' }]] : 'list',
    outputDir: 'tests/Viewports/results',
    use: { baseURL, trace: 'retain-on-failure' },
    projects: [
        { name: 'auth', testMatch: /auth\.setup\.ts/ },
        {
            name: 'mobile',
            dependencies: ['auth'],
            testIgnore: /mcp-widgets/,
            use: {
                browserName: 'chromium',
                viewport: { width: 390, height: 844 },
                isMobile: true,
                hasTouch: true,
                deviceScaleFactor: 2,
                storageState: 'tests/Viewports/.auth/demo.json',
            },
        },
        // iPad portrait (the onboarding design's tablet set, 834 wide): only the onboarding pages, which have their own
        // tablet layout; the rest of the app is covered by phone + desktop.
        {
            name: 'tablet',
            dependencies: ['auth'],
            testMatch: /viewports\.spec\.ts/,
            grep: /onboarding/,
            use: {
                browserName: 'chromium',
                viewport: { width: 834, height: 1194 },
                isMobile: true,
                hasTouch: true,
                deviceScaleFactor: 2,
                storageState: 'tests/Viewports/.auth/demo.json',
            },
        },
        {
            name: 'desktop',
            dependencies: ['auth'],
            testIgnore: /mcp-widgets/,
            use: { browserName: 'chromium', viewport: { width: 1280, height: 800 }, storageState: 'tests/Viewports/.auth/demo.json' },
        },
        // MCP App widgets at the widths assistant hosts render them (phone chat ~360px, desktop column ~700px).
        { name: 'mcp-360', testMatch: /mcp-widgets\.spec\.ts/, use: { browserName: 'chromium', viewport: { width: 360, height: 800 } } },
        { name: 'mcp-700', testMatch: /mcp-widgets\.spec\.ts/, use: { browserName: 'chromium', viewport: { width: 700, height: 800 } } },
    ],
    webServer: process.env.VIEWPORTS_URL
        ? undefined
        : { command: 'php artisan serve --port 8123', url: `${baseURL}/login`, reuseExistingServer: !process.env.CI, timeout: 120_000 },
});
