// Calendar date helpers. All dates are local wall-clock 'Y-m-d' strings (the server stores and sends them
// as typed; there is no timezone), so math goes through local-midnight Dates and back, never Date.parse
// (which reads 'Y-m-d' as UTC and shifts the day). The server picks the visible range; this file only lays it out.

export type CalPerson = { type: 'user' | 'person'; id: number; name: string };
export type CalItem = {
    source: string;
    kind: string;
    id: number;
    title: string;
    start: string;
    end: string;
    startTime: string | null;
    endTime: string | null;
    href: string;
    icon: string;
    color: string | null;
    people: CalPerson[];
    done: boolean;
    editable: boolean;
};
export type CalFilter = { people: string[]; sources: string[] };

export function parseDay(s: string): Date {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
}

export function ymd(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function addDays(s: string, n: number): string {
    const d = parseDay(s);
    d.setDate(d.getDate() + n);
    return ymd(d);
}

// Whole days from a to b. Math.round absorbs the 23/25-hour DST days.
export function daysBetween(a: string, b: string): number {
    return Math.round((parseDay(b).getTime() - parseDay(a).getTime()) / 86400000);
}

export function daysOf(from: string, to: string): string[] {
    const out: string[] = [];
    for (let d = from; d <= to; d = addDays(d, 1)) out.push(d);
    return out;
}

export function weeksOf(from: string, to: string): string[][] {
    const days = daysOf(from, to);
    const out: string[][] = [];
    for (let i = 0; i < days.length; i += 7) out.push(days.slice(i, i + 7));
    return out;
}

export const itemsOn = (items: CalItem[], day: string) => items.filter((i) => i.start <= day && i.end >= day);

// Greedy lane packing for one week row of the month grid: longer items first so trips sit on top lanes,
// each item takes the first lane free across all its columns. Items are clipped to the week.
export function layoutWeek(items: CalItem[], week: string[]) {
    const first = week[0];
    const last = week[week.length - 1];
    const inWeek = items
        .filter((i) => i.start <= last && i.end >= first)
        .sort(
            (a, b) =>
                daysBetween(b.start, b.end) - daysBetween(a.start, a.end) ||
                a.start.localeCompare(b.start) ||
                (a.startTime ?? '').localeCompare(b.startTime ?? ''),
        );
    const lanes: boolean[][] = [];
    return inWeek.map((item) => {
        const col = Math.max(0, daysBetween(first, item.start));
        const span = Math.min(week.length - 1, daysBetween(first, item.end)) - col + 1;
        let lane = 0;
        while (lanes[lane]?.slice(col, col + span).some(Boolean)) lane++;
        lanes[lane] ??= Array(week.length).fill(false);
        for (let c = col; c < col + span; c++) lanes[lane][c] = true;
        return { item, col, span, lane };
    });
}

// Timeline bar geometry in day columns, clipped to [from, to]; null when entirely outside.
export function clip(item: CalItem, from: string, to: string): { offset: number; span: number } | null {
    if (item.end < from || item.start > to) return null;
    const s = item.start < from ? from : item.start;
    const e = item.end > to ? to : item.end;
    return { offset: daysBetween(from, s), span: daysBetween(s, e) + 1 };
}

// Stable React key for an item occurrence (a repeating event yields one item per start date).
export const itemKey = (i: CalItem) => `${i.source}-${i.kind}-${i.id}-${i.start}`;

export const personKey = (p: { type: string; id: number }) => `${p.type}:${p.id}`;

export function filterItems(items: CalItem[], f: CalFilter): CalItem[] {
    return items.filter(
        (i) =>
            (f.sources.length === 0 || f.sources.includes(i.source)) &&
            (f.people.length === 0 || i.people.some((p) => f.people.includes(personKey(p)))),
    );
}

// Prev/next: a month view or month-zoom timeline steps to the 1st of the next/previous month (avoids
// Jan 31 + 1 month overflowing); the week view and a week-zoom timeline step 7 days; a year-zoom timeline a year.
export function shiftDate(view: string, zoom: string, date: string, dir: 1 | -1): string {
    if (view === 'week' || (view === 'timeline' && zoom === 'week')) return addDays(date, 7 * dir);
    const d = parseDay(date);
    if (view === 'timeline' && zoom === 'year') return ymd(new Date(d.getFullYear() + dir, 0, 1));
    return ymd(new Date(d.getFullYear(), d.getMonth() + dir, 1));
}

// Timeline rows: one per item (a repeating series is one item, so its occurrences share a row as several bars),
// keeping only occurrences inside the window (Notion's "off-screen arrow" rows are deliberately not copied).
// Rows are ordered by their earliest in-window start, then title; `item` is that earliest occurrence.
export type TimelineBar = { item: CalItem; offset: number; span: number };
export function timelineRows(items: CalItem[], from: string, to: string): { key: string; item: CalItem; bars: TimelineBar[] }[] {
    const rows = new Map<string, TimelineBar[]>();
    for (const item of items) {
        const geo = clip(item, from, to);
        if (!geo) continue;
        const key = `${item.source}-${item.kind}-${item.id}`;
        rows.set(key, [...(rows.get(key) ?? []), { item, ...geo }]);
    }
    return [...rows]
        .map(([key, bars]) => {
            bars.sort((a, b) => a.offset - b.offset);
            return { key, item: bars[0].item, bars };
        })
        .sort((a, b) => a.bars[0].offset - b.bars[0].offset || a.item.title.localeCompare(b.item.title));
}

// Kinds that are something to get done by a date, as opposed to something that happens on it (an event, a birthday):
// past their date and still open, they're overdue. A car document past its expiry date has expired, which counts.
const ACTIONABLE = new Set(['task', 'vehicle_maintenance', 'vehicle_document']);
export const isOverdue = (i: CalItem, today: string) => ACTIONABLE.has(i.kind) && !i.done && i.end < today;
