// App screenshots for the README (docs/screenshots) and the familywell.org design (design-site-assets/), from
// a freshly seeded demo family. Same file names and sizes as the originals, so a design that places
// phone-vehicle.png can swap in design-site-assets/de/phone-vehicle.png untouched.
//
//   php artisan migrate:fresh --force && php artisan modules:migrate
//   DEMO_LOCALE=de php artisan db:seed --class=DemoSeeder --force    # or leave DEMO_LOCALE unset for English
//   php artisan serve --port 8131
//   node scripts/screenshots.mjs http://127.0.0.1:8131 ../familywell-private/design/design-site-assets/de
//
// Records are opened by id, which is only right on a fresh database (the seeder creates them in a fixed
// order): car 1 is the CR-V, project 1 the kitchen, recipe 1 the pancakes, list 1 the weekly groceries,
// spending event 4 the road trip. The browser clock is pinned to 09:30 UTC today so the dashboard says
// "good morning" and "today" matches the server's dates.
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const [base = 'http://127.0.0.1:8131', out = 'docs/screenshots'] = process.argv.slice(2);
mkdirSync(out, { recursive: true });

const desktop = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 };
const phone = { viewport: { width: 400, height: 860 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
const shots = [
    ['desk-dashboard', desktop, '/dashboard'],
    ['desk-inventory', desktop, '/m/inventory'],
    ['desk-modules', desktop, '/family/modules'],
    ['desk-notes', desktop, '/m/notes'],
    ['desk-projects', desktop, '/m/projects'],
    ['desk-shopping', desktop, '/m/shopping'],
    ['car-overview', desktop, '/m/vehicles/1'],
    ['car-fuel', desktop, '/m/vehicles/1/fuel'],
    ['car-maintenance', desktop, '/m/vehicles/1/maintenance'],
    ['car-timeline', desktop, '/m/vehicles/1/timeline'],
    ['fall-road-trip', desktop, '/m/spending/events/4'],
    ['desktop-calendar-month', { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 }, '/m/calendar'],
    ['phone-dashboard', phone, '/dashboard'],
    ['phone-project', phone, '/m/projects/1'],
    ['phone-recipe', phone, '/m/recipes/1'],
    ['phone-shopping', phone, '/m/shopping/lists/1'],
    ['phone-spending', phone, '/m/spending'],
    ['phone-vehicle', phone, '/m/vehicles/1'],
];

const browser = await chromium.launch();
const morning = new Date();
morning.setUTCHours(9, 30, 0, 0);

for (const [name, device, path] of shots) {
    const context = await browser.newContext({ ...device, timezoneId: 'UTC' });
    const page = await context.newPage();
    await page.clock.setFixedTime(morning);
    await page.goto(`${base}/demo`); // signs in as the demo owner (each context is a fresh session)
    const res = await page.goto(base + path, { waitUntil: 'networkidle' });
    if (!res?.ok()) throw new Error(`${path}: HTTP ${res?.status()}`);
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: `${out}/${name}.png` });
    console.log(name);
    await context.close();
}
await browser.close();
