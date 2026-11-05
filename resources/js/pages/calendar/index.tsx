import { Head, router } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight, Share, SlidersHorizontal } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { AgendaView } from '@/components/calendar/agenda';
import { EventSheet } from '@/components/calendar/event-sheet';
import { FeedSheet, type Subscription } from '@/components/calendar/feed-sheet';
import { FilterSheet } from '@/components/calendar/filter-sheet';
import { MonthView } from '@/components/calendar/month-view';
import { TimelineView, type TimelineHandle } from '@/components/calendar/timeline-view';
import { HomeTopBar } from '@/components/home-top-bar';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { addDays, CATEGORIES, CATEGORY_DOT, filterItems, parseDay, shiftDate, type CalFilter, type CalItem } from '@/lib/calendar';
import { openNewOnArrival } from '@/lib/create-intent';
import { localToday } from '@/lib/date-groups';
import { useLocale, useT } from '@/lib/i18n';
import { save } from '@/lib/save';

const FILTER_KEY = 'familywell:calendarFilter';
const readFilter = (): CalFilter => {
    try {
        const v = JSON.parse(localStorage.getItem(FILTER_KEY) ?? '');
        return {
            people: Array.isArray(v?.people) ? v.people : [],
            sources: Array.isArray(v?.sources) ? v.sources : [],
            // Only a category we still know (a stale slug would quietly hide every event).
            category: typeof v?.category === 'string' && v.category in CATEGORIES ? v.category : null,
        };
    } catch {
        return { people: [], sources: [], category: null };
    }
};

type View = 'agenda' | 'month' | 'timeline';
type Zoom = 'week' | 'twoweeks' | 'month';
type Named = { id: number; name: string };
export type CalendarProps = {
    view: View;
    zoom: Zoom;
    date: string;
    range: { from: string; to: string };
    items: CalItem[];
    ahead: CalItem[];
    sources: string[];
    members: Named[];
    contacts: Named[];
    projects: Named[] | null;
    feedUrl: string | null;
    subscriptions: Subscription[];
};

// ISO week number (the agenda's "Week 43").
function isoWeek(d: string): number {
    const x = parseDay(d);
    x.setDate(x.getDate() + 3 - ((x.getDay() + 6) % 7));
    const jan4 = new Date(x.getFullYear(), 0, 4);
    return 1 + Math.round(((x.getTime() - jan4.getTime()) / 864e5 - 3 + ((jan4.getDay() + 6) % 7)) / 7);
}

// The calendar (design j6QDy4 04 / 04b): Agenda first (a week strip, then the days), Month (the regular grid with
// bars), Timeline (the big stuff, one row each). View, zoom and date live in the query string (back / forward,
// shareable); the server answers with the window and its items, and moving is a partial reload of only those.
export default function CalendarIndex({
    view,
    zoom,
    date,
    range,
    items,
    ahead,
    sources,
    members,
    contacts,
    projects,
    feedUrl,
    subscriptions,
}: CalendarProps) {
    const t = useT();
    const locale = useLocale();
    const today = localToday(); // the family's day, same as Home and the server
    const inRange = (d: string) => d >= range.from && d <= range.to;
    const [selected, setSelected] = useState(() =>
        view === 'month' ? (date.slice(0, 7) === today.slice(0, 7) ? today : date) : inRange(today) ? today : range.from,
    );
    // A new window: keep the picked day if it's still on screen, else today (when shown) or its first day.
    useEffect(() => {
        if (view === 'month') return;
        setSelected((s) => (inRange(s) ? s : inRange(today) ? today : range.from));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [range.from, range.to]);
    const [sheet, setSheet] = useState<{ open: boolean; day: string }>(() => ({ open: openNewOnArrival(), day: selected }));
    useAddAction(() => setSheet({ open: true, day: selected }), { label: 'create.calendar', icon: CalendarDays, replaces: 'calendar' });

    // Drop any remembered source/person no longer valid for this family (a module disabled since it was saved, or a
    // removed member/contact) so a stale filter never silently hides everything.
    const validSources = new Set(sources);
    const validPeople = new Set([...members.map((m) => `user:${m.id}`), ...contacts.map((c) => `person:${c.id}`)]);
    const [filter, setFilterState] = useState<CalFilter>(() => {
        const f = readFilter();
        return {
            people: f.people.filter((p) => validPeople.has(p)),
            sources: f.sources.filter((s) => validSources.has(s)),
            // Categories belong to calendar events: with that source turned off, a category would leave nothing.
            category: validSources.has('calendar') ? f.category : null,
        };
    });
    const [filtering, setFiltering] = useState(false);
    const [feedOpen, setFeedOpen] = useState(false);
    const setFilter = (f: CalFilter) => {
        setFilterState(f);
        try {
            localStorage.setItem(FILTER_KEY, JSON.stringify(f));
        } catch {
            /* private mode: not remembered */
        }
    };
    // A to-do ticked from the calendar: the circle fills at once; lib/save reloads the items after.
    const doneOpt = useOptimistic<boolean>();
    const toggle = (i: CalItem) => {
        const next = !doneOpt.get(i.id, i.done);
        doneOpt.set(i.id, next);
        void save('patch', route('tasks.tasks.update', i.id), { done: next }).then(() => doneOpt.clear(i.id));
    };
    const shown = filterItems(items, filter).map((i) => (i.kind === 'task' ? { ...i, done: doneOpt.get(i.id, i.done) } : i));
    const active = filter.people.length + filter.sources.length;

    const go = (q: Partial<{ view: View; zoom: Zoom; date: string }>) => {
        const nextView = q.view ?? view;
        const nextZoom = q.zoom ?? zoom;
        // Remembered per device; the server reads it when the calendar opens without a view.
        document.cookie = `fw_calendar_view=${nextView === 'timeline' ? `timeline:${nextZoom}` : nextView}; path=/; max-age=31536000; samesite=lax`;
        if (q.date && nextView === 'month') setSelected(q.date);
        router.get(
            '/m/calendar',
            { view: nextView, zoom: nextZoom, date: q.date ?? date },
            { preserveState: true, preserveScroll: true, only: ['view', 'zoom', 'date', 'range', 'items', 'ahead'] },
        );
    };
    const fmt = (d: string, o: Intl.DateTimeFormatOptions) => parseDay(d).toLocaleDateString(locale, o);
    // The timeline scrolls freely: its title follows the days on screen, and the arrows scroll it a screen.
    const strip = useRef<TimelineHandle>(null);
    const [visible, setVisible] = useState(range);

    // Header: the month (agenda: of the week shown; "Week 43 · 6 things" under it), or the timeline's days.
    const title =
        view === 'timeline'
            ? (visible.from.slice(0, 4) !== today.slice(0, 4) ? `${visible.from.slice(0, 4)} · ` : '') +
              (visible.from.slice(0, 7) === visible.to.slice(0, 7)
                  ? `${fmt(visible.from, { month: 'short', day: 'numeric' })} – ${Number(visible.to.slice(8))}`
                  : `${fmt(visible.from, { month: 'short', day: 'numeric' })} – ${fmt(visible.to, { month: 'short', day: 'numeric' })}`)
            : fmt(view === 'agenda' ? addDays(range.from, 3) : date, { month: 'long' });
    const step = (dir: 1 | -1) =>
        view === 'timeline'
            ? strip.current?.scrollPage(dir)
            : go({ date: view === 'month' ? shiftDate('month', 'month', date, dir) : addDays(range.from, 7 * dir) });
    const round = 'bg-surface text-primary flex size-10 shrink-0 items-center justify-center rounded-full disabled:opacity-40';
    const seg = (on: boolean) =>
        `rounded-full px-3 py-1.5 text-sm font-semibold ${on ? 'bg-surface-card text-primary shadow-sm' : 'text-muted-foreground'}`;

    return (
        <AppLayout breadcrumbs={[{ title: t('nav.calendar'), href: '/m/calendar' }]} mobileHeader={<HomeTopBar />}>
            <Head title={t('nav.calendar')} />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <div className="mr-auto min-w-0">
                        <h1 className="font-heading text-primary truncate text-4xl font-extrabold tracking-tight first-letter:uppercase">{title}</h1>
                        {view === 'agenda' && (
                            <p className="text-muted-foreground text-sm">
                                {t('calendar.week_n', { n: isoWeek(range.from) })} · {t('calendar.things', { count: shown.length })}
                            </p>
                        )}
                    </div>
                    {view === 'timeline' && (
                        <div className="bg-surface flex shrink-0 rounded-full border p-0.5">
                            {(['week', 'twoweeks', 'month'] as const).map((z) => (
                                <button
                                    key={z}
                                    type="button"
                                    aria-pressed={zoom === z}
                                    onClick={() => go({ zoom: z })}
                                    // The 5-week zoom is desktop's (D2); a phone has 1w and 2w.
                                    className={`${seg(zoom === z)} ${z === 'month' ? 'hidden md:block' : ''}`}
                                >
                                    {t(`calendar.zoom_${z}`)}
                                </button>
                            ))}
                        </div>
                    )}
                    {/* Filters on every view: they apply to all three, and a filter set under Agenda used to hide
                        everything on Timeline/Month with no sign of it there (desktop, 2026-11-05). */}
                    <>
                        <button type="button" onClick={() => setFiltering(true)} aria-label={t('calendar.filters')} className={`${round} relative`}>
                            <SlidersHorizontal className="size-5" />
                            {active > 0 && (
                                <span className="bg-accent-strong text-accent-strong-foreground absolute -top-1 -right-1 flex size-5 items-center justify-center rounded-full text-[11px] font-bold">
                                    {active}
                                </span>
                            )}
                        </button>
                        {view === 'agenda' && (
                            <button type="button" onClick={() => setFeedOpen(true)} aria-label={t('calendar.calendars_title')} className={round}>
                                <Share className="size-5" />
                            </button>
                        )}
                    </>
                    {/* Month steps with arrows everywhere; the other views on desktop (phones swipe; the timeline scrolls freely). */}
                    <div className={`gap-2 ${view === 'month' ? 'flex' : 'hidden md:flex'}`}>
                        <button type="button" onClick={() => step(-1)} aria-label={t('calendar.previous')} className={round}>
                            <ChevronLeft className="size-5" />
                        </button>
                        <button type="button" onClick={() => step(1)} aria-label={t('calendar.next')} className={round}>
                            <ChevronRight className="size-5" />
                        </button>
                        {/* The timeline has its own Today pill. */}
                        {view !== 'timeline' && !inRange(today) && (
                            <button
                                type="button"
                                onClick={() => go({ date: today })}
                                className="bg-surface text-primary hidden rounded-full px-4 text-sm font-semibold md:block"
                            >
                                {t('calendar.today')}
                            </button>
                        )}
                    </div>
                </div>

                {/* Agenda / Month / Timeline */}
                <div className="bg-surface grid grid-cols-3 rounded-full border p-1">
                    {(['agenda', 'month', 'timeline'] as const).map((v) => (
                        <button
                            key={v}
                            type="button"
                            aria-pressed={view === v}
                            onClick={() => go({ view: v, date: v === view ? date : today })}
                            className={`rounded-full py-2 text-base font-semibold ${view === v ? 'bg-surface-card text-primary shadow-sm' : 'text-muted-foreground'}`}
                        >
                            {t(`calendar.view_${v}`)}
                        </button>
                    ))}
                </div>

                {/* Category chips (04b C9/C10/D2): All first, one choice at a time, on every view. Only when the calendar
                    source is on (categories are on calendar events). The badge on the filter button doesn't count it:
                    the chip row already shows it. */}
                {sources.includes('calendar') && (
                    <div data-scroll-x className="-mx-4 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none]">
                        {[null, ...Object.keys(CATEGORIES)].map((c) => {
                            const on = filter.category === c;
                            return (
                                <button
                                    key={c ?? 'all'}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => setFilter({ ...filter, category: c })}
                                    className={`flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-semibold ${
                                        on ? 'bg-primary text-primary-foreground border-transparent' : 'bg-surface-card text-primary border-border'
                                    }`}
                                >
                                    {c && <span className={`size-[7px] rounded-full ${CATEGORY_DOT[c]}`} />}
                                    {t(c ? `calendar.category_${c}` : 'calendar.all')}
                                </button>
                            );
                        })}
                    </div>
                )}

                {view === 'agenda' ? (
                    <AgendaView
                        items={shown}
                        range={range}
                        today={today}
                        selected={selected}
                        onSelect={setSelected}
                        onWeek={(dir) => step(dir)}
                        onToggle={toggle}
                    />
                ) : view === 'month' ? (
                    <MonthView items={shown} range={range} date={date} today={today} selected={selected} onSelect={setSelected} onToggle={toggle} />
                ) : (
                    <TimelineView
                        ref={strip}
                        items={shown}
                        ahead={filterItems(ahead, filter)}
                        filter={filter}
                        range={range}
                        zoom={zoom}
                        today={today}
                        selected={selected}
                        onSelect={setSelected}
                        onVisible={(from, to) => setVisible({ from, to })}
                        onToggle={toggle}
                    />
                )}
            </div>
            <EventSheet
                open={sheet.open}
                onOpenChange={(open) => setSheet((s) => ({ ...s, open }))}
                defaultDate={sheet.day}
                members={members}
                contacts={contacts}
                projects={projects}
            />
            <FilterSheet
                open={filtering}
                onOpenChange={setFiltering}
                value={filter}
                onChange={setFilter}
                sources={sources}
                members={members}
                contacts={contacts}
            />
            <FeedSheet open={feedOpen} onOpenChange={setFeedOpen} url={feedUrl} subscriptions={subscriptions} />
        </AppLayout>
    );
}
