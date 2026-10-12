# FamilyWell

## UI: definition of done

Every page must be flawless at phone AND desktop width. UI work is not done until all of this holds:

- **Use the shadcn components** in `resources/js/components/ui` (and the app's shared components) instead of hand-rolled markup. Don't hand-roll a `<table>`; use `components/ui/table`, and on phones give long cells `w-full max-w-0` (+ `truncate`) so they shrink instead of widening the table.
- **New page?** Add it to `PAGES` (or `DETAILS` for an `/{id}` page) in `tests/Viewports/viewports.spec.ts`, and make sure the demo seeder (`DemoSeeder`) gives it data.
- **Run the viewport checks**: `npm run build`, then `npm run test:viewports` against a seeded app (`php artisan migrate && php artisan modules:migrate && php artisan db:seed --class=DemoSeeder`; set `VIEWPORTS_URL` to point at an app you already run). Every page at 390x844 and 1280x800 fails on sideways page scroll, a cut-off or sideways-scrolling box, overlapping text, or a wrapping table header. CI runs it after Pest.
- **Look at both screenshots** in `tests/Viewports/screenshots/` (`<page>-mobile*.png`, `<page>-desktop*.png`) before calling it done. Passing checks don't prove it looks right.
- A row that is meant to swipe sideways (filter chips, tabs) says so with `data-scroll-x`. Never hide overflow with `overflow-x: hidden`; fix the element that is too wide.
