// Relative date buckets for newest-first feeds (Spending > Expenses). Pure functions on 'YYYY-MM-DD'
// strings so the boundary maths is unit-testable without a DOM (see date-groups.test.mjs, run with
// `npm run test:js`). `today` is the VIEWER'S local calendar day — the feed reads in the viewer's time,
// same as the event page's Today/Yesterday day labels. Weeks start on Monday (ISO; Carbon's
// startOfWeek assumes the same server-side).
//
// Buckets, newest first: today | yesterday | this_week (earlier this week, before yesterday) |
// last_week | this_month (earlier this month, before last week) | 'YYYY-MM' (older, by month).
// Every bucket is a contiguous date range and they are ordered, so a sequential pass over a
// newest-first feed yields the groups already in display order.

// UTC midnight for day arithmetic: local midnights differ by an hour across DST changes.
const day = (s: string) => new Date(s + 'T00:00:00Z');

export function dateBucket(date: string, today: string): string {
    const d = day(date);
    const now = day(today);
    const diff = Math.round((now.getTime() - d.getTime()) / 86_400_000);
    // ponytail: a future-dated spend (a typo'd date) sits under Today so the buckets stay monotonic;
    // give it its own "Upcoming" bucket if people start planning spends ahead.
    if (diff <= 0) return 'today';
    if (diff === 1) return 'yesterday';
    const sinceMonday = (now.getUTCDay() + 6) % 7;
    if (diff <= sinceMonday) return 'this_week';
    if (diff <= sinceMonday + 7) return 'last_week';
    if (date.slice(0, 7) === today.slice(0, 7)) return 'this_month';
    return date.slice(0, 7);
}

// Groups a newest-first list into contiguous buckets, preserving order within each group.
export function groupByDateBucket<T>(items: T[], dateOf: (item: T) => string, today: string): { key: string; items: T[] }[] {
    return items.reduce<{ key: string; items: T[] }[]>((groups, item) => {
        const key = dateBucket(dateOf(item), today);
        const last = groups[groups.length - 1];
        if (last && last.key === key) last.items.push(item);
        else groups.push({ key, items: [item] });
        return groups;
    }, []);
}

// The family's IANA zone (the shared `timezone` prop, App\Support\Tenancy\FamilyClock), set by app.tsx on every page.
// Undefined (no family yet) = the browser's own zone.
let familyZone: string | undefined;
export function setFamilyTimezone(zone: string | null | undefined): void {
    familyZone = zone || undefined;
}
export const familyTimezone = (): string => familyZone ?? Intl.DateTimeFormat().resolvedOptions().timeZone;

// en-CA formats a date as YYYY-MM-DD. One formatter per zone, not per call (Home asks for today on every row).
const ymdFormatters = new Map<string, Intl.DateTimeFormat>();
function ymdIn(date: Date, zone: string): string {
    let f = ymdFormatters.get(zone);
    if (!f) ymdFormatters.set(zone, (f = new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' })));
    return f.format(date);
}

// The family's calendar day as 'YYYY-MM-DD': the same day the server buckets Today / overdue by (FamilyClock), so a
// member travelling, or the browser's own clock, never disagrees with Home. `plusDays` steps whole days from it.
export const localToday = (plusDays = 0): string => {
    const today = ymdIn(new Date(), familyTimezone());
    if (!plusDays) return today;
    const [y, m, d] = today.split('-').map(Number);
    // Date.UTC: pure calendar arithmetic, no DST hour in the way.
    return new Date(Date.UTC(y, m - 1, d + plusDays)).toISOString().slice(0, 10);
};
