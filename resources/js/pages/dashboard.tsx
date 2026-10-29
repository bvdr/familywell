import { Confetti } from '@/components/confetti';
import { FamilyMark } from '@/components/family-mark';
import { LucideIcon as DynamicLucideIcon } from '@/components/lucide-icon';
import { MemberAvatar } from '@/components/member-avatar';
import { NotificationsBell } from '@/components/notifications-bell';
import { MODULES, YourModules, type ModuleSummary } from '@/components/your-modules';
import AppLayout from '@/layouts/app-layout';
import { localToday } from '@/lib/date-groups';
import { useLocale, useT, type TFn } from '@/lib/i18n';
import { money } from '@/lib/money';
import { save } from '@/lib/save';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Blocks, Cake, Check, ChevronDown, ChevronRight, Heart, Inbox, Phone, Plus, Search, Star } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

// Home (design node XMkKm, "H1 · Today"): Today first. One agenda of to-dos and events for today and tomorrow,
// the rest of the week folded into a day strip, then money and modules. Members and Settings moved into the
// avatar menu. Everything here comes from DashboardData (module providers); a module that is off simply
// contributes nothing, and an empty section hides itself.

// A recently-opened entity (Jump back in). visitedAt drives the order server-side.
type DashItem = { title: string; subtitle: string; href: string; icon: string; category?: string; color?: string | null };
type Person = { id: number; name: string };
// A dated row from DashboardAgenda. toggleUrl + toggleBody: PATCH toggleBody marks it done/bought (it drops off on
// the reload); absent for events (they aren't "done"). Events carry time/people/colour; an event's prep to-dos
// carry the same eventId, which is how they nest under it.
type AgendaItem = DashItem & {
    dueDate: string;
    kind?: string;
    id?: number;
    toggleUrl?: string;
    toggleBody?: Record<string, boolean>;
    assignee?: Person | null;
    time?: string | null;
    endTime?: string | null;
    people?: string[];
    eventId?: number;
};
type Birthday = { personId: number; name: string; turning: number | null; date: string; inDays: number; born: string | null; phone: string | null };
type Family = { name: string; color: string; symbol?: string | null; emoji?: string | null };

// Unique per row: a repeating event recurs under one href+title, and two to-dos of one event share href and maybe
// title, so the date and the per-task toggle URL go into the key too.
const agendaKey = (it: AgendaItem) => `${it.kind}-${it.toggleUrl ?? it.href}-${it.title}-${it.dueDate}`;
const isEvent = (it: AgendaItem) => it.kind === 'event';

// Local midnight for a Y-m-d string (avoids the UTC day-shift of new Date('2026-08-03')).
function parseDay(ymd: string) {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y, m - 1, d);
}
const ymdOf = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
// The FAMILY's today (its zone, Settings › Region & units), the day the server bucketed Today / Tomorrow by.
const todayYmd = () => localToday();

// "16:30" on today's date, for "in 2 h" and the clock formatting below.
function atTime(ymd: string, hm: string) {
    const d = parseDay(ymd);
    const [h, m] = hm.split(':').map(Number);
    d.setHours(h, m);
    return d;
}

// A clock time split into the number and the day period the locale uses ("4:30" + "PM" in English, "16:30" + ""
// in German), so the rail can stack them like the design without hard-coding a 12-hour clock.
function clock(hm: string, locale: string): { time: string; period: string } {
    const parts = new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' }).formatToParts(atTime('2000-01-01', hm));
    return {
        time: parts
            .filter((p) => p.type !== 'dayPeriod')
            .map((p) => p.value)
            .join('')
            .trim(),
        period: parts.find((p) => p.type === 'dayPeriod')?.value ?? '',
    };
}
const clockText = (hm: string, locale: string) => {
    const c = clock(hm, locale);
    return c.period ? `${c.time} ${c.period.toLowerCase()}` : c.time;
};

// Time-of-day greeting key, resolved on the client so it matches the viewer's clock.
function greetingKey() {
    const h = new Date().getHours();
    if (h < 12) return 'dashboard.good_morning';
    if (h < 18) return 'dashboard.good_afternoon';
    return 'dashboard.good_evening';
}

// The small mono uppercase section label with an optional count and a right-hand link ("TODAY 5 ........ Tue 20").
function SectionLabel({ label, count, right, href }: { label: string; count?: number | string; right?: string; href?: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3 px-1">
            <h2 className="text-muted-foreground font-mono text-xs font-semibold tracking-[0.14em] uppercase">
                {label}
                {count !== undefined && <span className="text-primary ml-2">{count}</span>}
            </h2>
            {right &&
                (href ? (
                    <Link href={href} prefetch className="text-primary shrink-0 text-sm font-medium">
                        {right}
                    </Link>
                ) : (
                    <span className="text-muted-foreground shrink-0 text-sm">{right}</span>
                ))}
        </div>
    );
}

const card = 'bg-surface-card overflow-hidden rounded-2xl border';

// Home's own top bar on phones (the design's Top Bar): the family, then search, alerts and the avatar, which opens
// Me (your settings, the family's settings, Log out). Desktop keeps the sidebar + breadcrumb header.
function HomeTopBar({ family }: { family: Family | null }) {
    const { auth } = usePage<SharedData>().props;
    const t = useT();
    return (
        <div className="flex w-full items-center gap-2">
            <Link href="/family/members" className="flex min-w-0 flex-1 items-center gap-2.5">
                {family && <FamilyMark {...family} className="size-9 rounded-xl text-lg" />}
                <span className="text-primary truncate text-lg font-bold">{family?.name}</span>
                <ChevronDown className="text-muted-foreground size-4 shrink-0" />
            </Link>
            <Link
                href="/search"
                aria-label={t('common.search')}
                className="bg-surface text-primary flex size-10 shrink-0 items-center justify-center rounded-full"
            >
                <Search className="size-5" />
            </Link>
            <NotificationsBell />
            {/* Tap your face (design board 12): the avatar opens Me, where log out and the family's settings live too. */}
            <Link
                href="/settings"
                aria-label={auth.user.name}
                className="bg-primary text-primary-foreground flex size-10 shrink-0 items-center justify-center rounded-full text-base font-bold"
            >
                {auth.user.name.charAt(0).toUpperCase()}
            </Link>
        </div>
    );
}

// Birthdays in the next 14 days: today's in a sun card with Call / Send a wish (when the contact has a phone),
// the coming ones listed below. Nobody today: only the list.
function Birthdays({ people }: { people: Birthday[] }) {
    const t = useT();
    const locale = useLocale();
    const today = people.filter((b) => b.inDays === 0);
    // A big family (or the demo's 366 contacts) can have a dozen in two weeks: list the next three, the header
    // links to the full list.
    const coming = people.filter((b) => b.inDays > 0).slice(0, 3);
    return (
        <section className="flex flex-col gap-2.5">
            <SectionLabel label={t('dashboard.birthdays')} count={people.length} right={t('dashboard.next_two_weeks')} href="/m/birthdays" />
            <div className={card}>
                {today.map((b) => (
                    <div key={b.personId} className="bg-accent-strong text-accent-strong-foreground flex flex-col gap-4 p-4">
                        <div className="flex items-center gap-3">
                            <MemberAvatar name={b.name} className="ring-surface-card size-14 text-xl ring-2" />
                            <div className="min-w-0 flex-1">
                                <span className="flex items-center gap-1.5 font-mono text-[11px] font-semibold tracking-[0.14em] uppercase">
                                    <Cake className="size-3.5" />
                                    {t('dashboard.birthday_today')}
                                </span>
                                <span className="font-heading block truncate text-2xl leading-tight font-bold">{b.name}</span>
                                {b.born && (
                                    <span className="block text-sm">
                                        {t('dashboard.born', { date: parseDay(b.born).toLocaleDateString(locale, { dateStyle: 'medium' }) })}
                                    </span>
                                )}
                            </div>
                            {b.turning !== null && (
                                <span className="flex shrink-0 flex-col items-end gap-1.5">
                                    <span className="font-heading text-5xl leading-none font-extrabold tabular-nums">{b.turning}</span>
                                    <span className="font-mono text-[10px] font-semibold tracking-[0.14em] uppercase">{t('dashboard.years')}</span>
                                </span>
                            )}
                        </div>
                        {b.phone && (
                            <div className="grid grid-cols-2 gap-2.5">
                                <a
                                    href={`tel:${b.phone}`}
                                    className="bg-primary text-primary-foreground flex items-center justify-center gap-2 rounded-full py-2.5 text-[15px] font-semibold"
                                >
                                    <Phone className="size-4" />
                                    {t('dashboard.call')}
                                </a>
                                <a
                                    href={`sms:${b.phone}`}
                                    className="bg-accent-subtle text-primary flex items-center justify-center gap-2 rounded-full py-2.5 text-[15px] font-semibold"
                                >
                                    <Heart className="size-4" />
                                    {t('dashboard.send_wish')}
                                </a>
                            </div>
                        )}
                    </div>
                ))}
                {coming.map((b) => {
                    const day = parseDay(b.date);
                    return (
                        <Link key={b.personId} href="/m/birthdays" prefetch className="flex items-center gap-3 border-t px-4 py-3 first:border-t-0">
                            <MemberAvatar name={b.name} className="size-10 text-sm" />
                            <span className="min-w-0 flex-1">
                                <span className="text-primary block truncate font-semibold">{b.name}</span>
                                {b.turning !== null && (
                                    <span className="text-muted-foreground block text-sm">{t('dashboard.turns', { age: b.turning })}</span>
                                )}
                            </span>
                            <span className="flex shrink-0 flex-col items-end">
                                <span className="text-primary font-mono text-xs font-semibold uppercase">
                                    {day.toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: b.inDays > 6 ? 'short' : undefined })}
                                </span>
                                <span className="text-muted-foreground text-sm">
                                    {b.inDays === 1 ? t('dashboard.tomorrow') : t('dashboard.in_days', { count: b.inDays })}
                                </span>
                            </span>
                        </Link>
                    );
                })}
            </div>
            {today.length > 0 && <Confetti onceKey={`${todayYmd()}:${today.map((b) => b.personId).join(',')}`} />}
        </section>
    );
}

// The tick circle shared by to-dos and prep to-dos. One-way: tapping marks it done (optimistically) and the row
// drops off on the next dashboard load; if the save fails the circle unticks again (lib/save).
function useToggle(item: { toggleUrl?: string; toggleBody?: Record<string, boolean> }) {
    const [done, setDone] = useState(false);
    const toggle = () => {
        if (!item.toggleUrl || done) return;
        setDone(true);
        void save('patch', item.toggleUrl, item.toggleBody ?? { done: true }).then((result) => result !== 'ok' && setDone(false));
    };
    return [done, toggle] as const;
}

function Tick({ done, overdue, onClick, label }: { done: boolean; overdue?: boolean; onClick: () => void; label: string }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            className={`flex size-7 shrink-0 items-center justify-center rounded-full border-2 transition-colors ${
                done ? 'border-primary bg-accent-strong text-accent-strong-foreground' : overdue ? 'border-destructive' : 'border-primary'
            }`}
        >
            {done && <Check className="size-4" />}
        </button>
    );
}

// A to-do / shopping item row: tick, title, meta ("Overdue since Mon · Household"), who it's assigned to.
function TodoRow({ item, overdueFrom }: { item: AgendaItem; overdueFrom?: string }) {
    const t = useT();
    const [done, toggle] = useToggle(item);
    return (
        <div className="flex items-center gap-3 px-4 py-3">
            <span className="flex w-11 shrink-0 justify-center">
                <Tick done={done} overdue={!!overdueFrom} onClick={toggle} label={done ? t('dashboard.is_done') : t('dashboard.mark_done')} />
            </span>
            <Link href={item.href} prefetch className="flex min-w-0 flex-1 items-center gap-3">
                <span className="min-w-0 flex-1">
                    <span className={`block truncate text-[15px] font-semibold ${done ? 'text-muted-foreground line-through' : 'text-primary'}`}>
                        {item.title}
                    </span>
                    <span className={`block truncate text-sm ${overdueFrom ? 'text-destructive' : 'text-muted-foreground'}`}>
                        {overdueFrom ? `${t('dashboard.overdue_since', { day: overdueFrom })} · ${item.subtitle}` : item.subtitle}
                    </span>
                </span>
                {item.assignee && <MemberAvatar userId={item.assignee.id} name={item.assignee.name} className="size-7 text-xs" />}
            </Link>
        </div>
    );
}

// An event's prep to-do, nested under it on a pale inset ("Pack Lucia's swim bag · Before 4 pm · PREP").
function PrepRow({ item, before }: { item: AgendaItem; before?: string }) {
    const t = useT();
    const [done, toggle] = useToggle(item);
    return (
        <div className="bg-surface mx-4 mb-3 ml-[4.25rem] flex items-center gap-3 rounded-xl px-3 py-2.5">
            <Tick done={done} onClick={toggle} label={done ? t('dashboard.is_done') : t('dashboard.mark_done')} />
            <Link href={item.href} prefetch className="flex min-w-0 flex-1 items-center gap-2">
                <span className="min-w-0 flex-1">
                    <span className={`block truncate text-[15px] font-semibold ${done ? 'text-muted-foreground line-through' : 'text-primary'}`}>
                        {item.title}
                    </span>
                    {before && <span className="text-muted-foreground block truncate text-sm">{t('dashboard.before', { time: before })}</span>}
                </span>
                <span className="text-muted-foreground shrink-0 font-mono text-[10px] font-semibold tracking-[0.14em] uppercase">
                    {t('dashboard.prep')}
                </span>
            </Link>
        </div>
    );
}

// "in 2 h" / "in 40 min" for an event later today, so the next thing reads at a glance. null once it has started.
function startsIn(item: AgendaItem, t: TFn): string | null {
    if (!item.time || item.dueDate !== todayYmd()) return null;
    const mins = Math.round((atTime(item.dueDate, item.time).getTime() - Date.now()) / 60000);
    if (mins <= 0) return null;
    return mins < 60 ? t('dashboard.in_minutes', { count: mins }) : t('dashboard.in_hours', { count: Math.round(mins / 60) });
}

// An event row: its start time in the rail (or nothing for all-day), its colour as the bar, "Until 5:30 · Lucia".
function EventRow({ item }: { item: AgendaItem }) {
    const t = useT();
    const locale = useLocale();
    const c = item.time ? clock(item.time, locale) : null;
    const soon = startsIn(item, t);
    const meta = [item.endTime ? t('dashboard.until', { time: clockText(item.endTime, locale) }) : item.subtitle, ...(item.people ?? [])]
        .filter(Boolean)
        .join(' · ');
    return (
        <Link href={item.href} prefetch className="flex items-center gap-3 px-4 py-3">
            <span className="flex w-11 shrink-0 flex-col items-center">
                {c ? (
                    <>
                        <span className="text-primary font-mono text-sm font-semibold tabular-nums">{c.time}</span>
                        {c.period && <span className="text-muted-foreground font-mono text-[10px] uppercase">{c.period}</span>}
                    </>
                ) : (
                    <DynamicLucideIcon name={item.icon} className="text-primary size-5" />
                )}
            </span>
            <span className="h-10 w-1 shrink-0 rounded-full" style={{ background: item.color || 'var(--accent-strong)' }} aria-hidden />
            <span className="min-w-0 flex-1">
                <span className="text-primary block truncate text-[15px] font-semibold">{item.title}</span>
                <span className="text-muted-foreground block truncate text-sm">{meta}</span>
            </span>
            {soon ? (
                <span className="bg-accent-strong text-accent-strong-foreground shrink-0 rounded-full px-2.5 py-1 text-xs font-bold">{soon}</span>
            ) : (
                <ChevronRight className="text-muted-foreground size-5 shrink-0" />
            )}
        </Link>
    );
}

// One day's agenda as a list card: events with their prep to-dos tucked underneath, then the other to-dos, in
// server order (by due date). A prep to-do whose event isn't on this day stands alone as a normal to-do.
function AgendaList({ items }: { items: AgendaItem[] }) {
    const locale = useLocale();
    const today = todayYmd();
    const eventIds = new Set(items.filter(isEvent).map((e) => e.eventId));
    const nested = (it: AgendaItem) => !isEvent(it) && it.eventId !== undefined && eventIds.has(it.eventId);
    return (
        <div className={`${card} divide-y`}>
            {items
                .filter((it) => !nested(it))
                .map((it) =>
                    isEvent(it) ? (
                        <div key={agendaKey(it)}>
                            <EventRow item={it} />
                            {items
                                .filter((p) => nested(p) && p.eventId === it.eventId)
                                .map((p) => (
                                    <PrepRow key={agendaKey(p)} item={p} before={it.time ? clockText(it.time, locale) : undefined} />
                                ))}
                        </div>
                    ) : (
                        <TodoRow
                            key={agendaKey(it)}
                            item={it}
                            overdueFrom={it.dueDate < today ? parseDay(it.dueDate).toLocaleDateString(locale, { weekday: 'short' }) : undefined}
                        />
                    ),
                )}
        </div>
    );
}

// The strip's days: from the day after tomorrow up to Sunday (the providers' window), stretched if an item ever
// falls later. Empty on Saturday and Sunday, when Tomorrow already covers what's left.
function stripDays(items: AgendaItem[]): string[] {
    const last = items.reduce((max, it) => (it.dueDate > max ? it.dueDate : max), '');
    const days: string[] = [];
    const d = parseDay(localToday(2));
    for (let ymd = ymdOf(d); d.getDay() !== 1 || ymd <= last; d.setDate(d.getDate() + 1), ymd = ymdOf(d)) days.push(ymd);
    return days;
}

// The rest of the week folded into a strip: one column per day with a mark per to-do (ring) and event (bar, in its
// colour) and the first thing on it; "Show Thu – Sun" unfolds the full rows. Days run to the end of the week.
function RestOfWeek({ items }: { items: AgendaItem[] }) {
    const t = useT();
    const locale = useLocale();
    const [open, setOpen] = useState(false);
    const days = stripDays(items);
    if (days.length === 0) return null;
    const byDay = (ymd: string) => items.filter((it) => it.dueDate === ymd);
    const todos = items.filter((it) => !isEvent(it)).length;
    const events = items.length - todos;
    const fmt = (ymd: string) => parseDay(ymd).toLocaleDateString(locale, { weekday: 'short' });

    return (
        <section className="flex flex-col gap-2.5">
            <SectionLabel label={t('dashboard.rest_of_week')} count={items.length} right={t('nav.calendar')} href="/m/calendar" />
            <div className={card}>
                <div className="grid px-2 pt-4 pb-3" style={{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }}>
                    {days.map((ymd) => {
                        const list = byDay(ymd);
                        const day = parseDay(ymd);
                        return (
                            <div key={ymd} className="flex min-w-0 flex-col items-center gap-1 px-1">
                                <span className="text-muted-foreground font-mono text-[11px] uppercase">
                                    {day.toLocaleDateString(locale, { weekday: 'short' })}
                                </span>
                                <span className="text-primary text-2xl font-bold tabular-nums">{day.getDate()}</span>
                                <span className="flex h-2.5 items-center gap-1">
                                    {list.length === 0 ? (
                                        <span className="bg-border h-0.5 w-4 rounded-full" />
                                    ) : (
                                        list
                                            .slice(0, 3)
                                            .map((it) =>
                                                isEvent(it) ? (
                                                    <span
                                                        key={agendaKey(it)}
                                                        className="h-1 w-3.5 rounded-full"
                                                        style={{ background: it.color || 'var(--accent-strong)' }}
                                                    />
                                                ) : (
                                                    <span key={agendaKey(it)} className="border-primary size-2 rounded-full border-[1.5px]" />
                                                ),
                                            )
                                    )}
                                </span>
                                <span className="text-muted-foreground w-full truncate text-center text-xs">
                                    {list.length === 0 ? t('dashboard.free') : list[0].title}
                                </span>
                            </div>
                        );
                    })}
                </div>
                {items.length > 0 && (
                    <>
                        <button
                            type="button"
                            onClick={() => setOpen((o) => !o)}
                            aria-expanded={open}
                            className="text-primary flex w-full items-center justify-center gap-2 border-t px-4 py-3 text-sm font-medium"
                        >
                            {open
                                ? t('dashboard.show_less')
                                : t('dashboard.show_days', {
                                      from: fmt(days[0]),
                                      to: fmt(days[days.length - 1]),
                                      what: [
                                          todos ? t('dashboard.todos_count', { count: todos }) : '',
                                          events ? t('dashboard.events_count', { count: events }) : '',
                                      ]
                                          .filter(Boolean)
                                          .join(', '),
                                  })}
                            <ChevronDown className={`size-4 transition-transform ${open ? 'rotate-180' : ''}`} />
                        </button>
                        {open && (
                            <div className="flex flex-col gap-3 border-t p-3">
                                {days
                                    .filter((ymd) => byDay(ymd).length)
                                    .map((ymd) => (
                                        <div key={ymd} className="flex flex-col gap-2">
                                            <span className="text-muted-foreground px-1 font-mono text-xs uppercase">
                                                {parseDay(ymd).toLocaleDateString(locale, { weekday: 'long', day: 'numeric', month: 'short' })}
                                            </span>
                                            <AgendaList items={byDay(ymd)} />
                                        </div>
                                    ))}
                            </div>
                        )}
                    </>
                )}
            </div>
        </section>
    );
}

// "Your important" (design H1 / H1a / H1b, data from the important_todos module's ImportantCard): your starred
// to-dos pinned on top until done, the family's folded into one row under them. Yours are lifted out of the agenda
// server-side (dashboard.lifted), others' stay there too.
type ImportantRow = { id: number; title: string; list: string; href: string; dueDate: string | null; toggleUrl: string; owner: Person };
type ImportantData = { mine: ImportantRow[]; others: { owner: Person; items: ImportantRow[] }[] };
const IMPORTANT_LIMIT = 3; // ImportantCard::LIMIT

// "Overdue since Sat · Health", "Due today · Household", "Due Fri, Oct 23 · Documents", or just the list.
function importantMeta(row: ImportantRow, t: TFn, locale: string): { text: string; overdue: boolean; today: boolean } {
    const today = todayYmd();
    if (!row.dueDate) return { text: row.list, overdue: false, today: false };
    const day = parseDay(row.dueDate);
    const due =
        row.dueDate < today
            ? t('dashboard.overdue_since', { day: day.toLocaleDateString(locale, { weekday: 'short' }) })
            : row.dueDate === today
              ? t('important_todos.due_today')
              : t('important_todos.due_on', { date: day.toLocaleDateString(locale, { weekday: 'short', month: 'short', day: 'numeric' }) });
    return { text: `${due} · ${row.list}`, overdue: row.dueDate < today, today: row.dueDate === today };
}

function ImportantItem({ row, family, onUnstar }: { row: ImportantRow; family?: boolean; onUnstar?: () => void }) {
    const t = useT();
    const locale = useLocale();
    const [done, toggle] = useToggle({ toggleUrl: row.toggleUrl, toggleBody: { done: true } });
    const meta = importantMeta(row, t, locale);
    return (
        <div className={`flex items-center gap-3 px-4 ${family ? 'py-2.5' : 'py-3'}`}>
            <Tick done={done} overdue={meta.overdue} onClick={toggle} label={done ? t('dashboard.is_done') : t('dashboard.mark_done')} />
            <Link href={row.href} prefetch className="min-w-0 flex-1">
                <span
                    className={`block truncate font-semibold ${family ? 'text-sm' : 'text-[15px]'} ${done ? 'text-muted-foreground line-through' : 'text-primary'}`}
                >
                    {row.title}
                </span>
                <span
                    className={`block truncate ${family ? 'text-xs' : 'text-sm'} ${meta.overdue ? 'text-destructive' : meta.today ? 'text-primary font-medium' : 'text-muted-foreground'}`}
                >
                    {meta.text}
                </span>
            </Link>
            {onUnstar ? (
                // Tap the star to unstar: the to-do goes back to the agenda on the reload.
                <button
                    type="button"
                    onClick={onUnstar}
                    aria-label={t('important_todos.unstar', { title: row.title })}
                    className="-mr-2 flex size-11 shrink-0 items-center justify-center"
                >
                    <Star className="fill-accent-strong text-primary size-5" strokeWidth={1.5} />
                </button>
            ) : (
                <>
                    <Star className="fill-accent-strong text-primary size-3.5 shrink-0" strokeWidth={1.5} aria-hidden />
                    <MemberAvatar userId={row.owner.id} name={row.owner.name} className="size-7 text-xs" />
                </>
            )}
        </div>
    );
}

// Show/Hide of the family's rows, remembered per person in this browser (a view preference, like a folded section;
// not worth a server column). Only while you have important ones yourself: with none, theirs open by default
// (H1b) and fold back as soon as you star one.
function useFamilyOpen(userId: number, hasMine: boolean) {
    const key = `fw:important-family-open:${userId}`;
    const [stored, setStored] = useState<boolean | null>(null);
    useEffect(() => {
        try {
            const v = localStorage.getItem(key);
            setStored(v === null ? null : v === '1');
        } catch {
            // private mode / blocked storage: just use the default
        }
    }, [key]);
    const [openNoMine, setOpenNoMine] = useState(true);
    const open = hasMine ? (stored ?? false) : openNoMine;
    const setOpen = (next: boolean) => {
        if (!hasMine) return setOpenNoMine(next);
        setStored(next);
        try {
            localStorage.setItem(key, next ? '1' : '0');
        } catch {
            // not remembered, still toggles
        }
    };
    return [open, setOpen] as const;
}

function ImportantCard({ data }: { data: ImportantData }) {
    const t = useT();
    const locale = useLocale();
    const { auth } = usePage<SharedData>().props;
    // Unstarred here: hidden at once (optimistic), back if the save fails; the reload then drops it for real.
    const [unstarred, setUnstarred] = useState<number[]>([]);
    const unstar = (id: number) => {
        setUnstarred((u) => [...u, id]);
        void save('patch', `/m/tasks/tasks/${id}`, { important: false }).then(
            (result) => result !== 'ok' && setUnstarred((u) => u.filter((x) => x !== id)),
        );
    };
    const mine = data.mine.filter((r) => !unstarred.includes(r.id));
    const others = data.others;
    const [open, setOpen] = useFamilyOpen(auth.user.id, mine.length > 0);

    const otherRows = others.flatMap((g) => g.items);
    const nextDue = otherRows
        .map((r) => r.dueDate)
        // From today on: an overdue one is already red in its row, "next due Mon" (last Monday) would read wrong.
        .filter((d): d is string => !!d && d >= todayYmd())
        .sort()[0];
    const nextDueText = nextDue
        ? parseDay(nextDue).toLocaleDateString(locale, nextDue <= localToday(6) ? { weekday: 'short' } : { month: 'short', day: 'numeric' })
        : null;

    return (
        <section className="flex flex-col gap-2.5">
            <SectionLabel
                label={t('important_todos.your_important')}
                count={`${mine.length}/${IMPORTANT_LIMIT}`}
                right={t('important_todos.pinned')}
            />
            <div className="bg-surface-card border-primary divide-y overflow-hidden rounded-2xl border-2">
                {mine.length > 0 ? (
                    mine.map((r) => <ImportantItem key={r.id} row={r} onUnstar={() => unstar(r.id)} />)
                ) : (
                    // H1b: nothing on you, one quiet line so the family's still say what matters.
                    <div className="flex items-center gap-3 px-4 py-3">
                        <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                            <Star className="size-4" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="text-primary block truncate text-[15px] font-semibold">{t('important_todos.none_title')}</span>
                            <span className="text-muted-foreground block text-sm">{t('important_todos.none_sub', { limit: IMPORTANT_LIMIT })}</span>
                        </span>
                    </div>
                )}
                {others.length > 0 && (
                    // On paper (bg-surface) so yours stay the loudest.
                    <div className="bg-surface divide-y">
                        <button
                            type="button"
                            onClick={() => setOpen(!open)}
                            aria-expanded={open}
                            data-testid="important-family-toggle"
                            className="flex w-full items-center gap-3 px-4 py-3 text-left"
                        >
                            <span className="flex shrink-0 -space-x-2">
                                {others.slice(0, 3).map((g) => (
                                    <MemberAvatar key={g.owner.id} userId={g.owner.id} name={g.owner.name} ring className="size-7 text-xs" />
                                ))}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="text-primary block truncate text-[15px] font-semibold">
                                    {others.map((g) => `${g.owner.name.split(' ')[0]} ${g.items.length}`).join(' · ')}
                                </span>
                                <span className="text-muted-foreground block truncate text-[13px]">
                                    {[
                                        t('important_todos.family_important'),
                                        !open && nextDueText ? t('important_todos.next_due', { day: nextDueText }) : '',
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </span>
                            <span className="text-primary flex shrink-0 items-center gap-1 text-sm font-semibold">
                                {open ? t('important_todos.hide') : t('important_todos.show')}
                                <ChevronDown className={`size-4 transition-transform ${open ? 'rotate-180' : ''}`} />
                            </span>
                        </button>
                        {open && otherRows.map((r) => <ImportantItem key={r.id} row={r} family />)}
                    </div>
                )}
            </div>
        </section>
    );
}

// The money card: month-to-date total, how many expenses, a bar per week of the month (this week in sun), and a
// "+" straight into the add-expense form (?new=1). Same figure as the Spending overview (shared MonthToDate).
function MoneyCard({ spending }: { spending: { total: number; currency: string; count: number; weeks: number[] } }) {
    const t = useT();
    const locale = useLocale();
    const max = Math.max(1, ...spending.weeks);
    const month = new Date().toLocaleDateString(locale, { month: 'long' });
    return (
        <section className="flex flex-col gap-2.5">
            <SectionLabel label={month} right={t('nav.spending')} href="/m/spending" />
            <div className="bg-surface-dark text-text-inverse flex flex-col gap-4 rounded-2xl p-5">
                <div className="flex items-start gap-3">
                    <Link href="/m/spending" prefetch className="min-w-0 flex-1">
                        <span className="block font-mono text-3xl font-semibold tabular-nums">{money(spending.total, spending.currency)}</span>
                        <span className="block text-sm opacity-75">{t('dashboard.spent_month', { count: spending.count })}</span>
                    </Link>
                    <Link
                        href="/m/spending?new=1"
                        aria-label={t('spending.add_expense')}
                        className="bg-accent-strong text-accent-strong-foreground flex size-12 shrink-0 items-center justify-center rounded-full"
                    >
                        <Plus className="size-6" />
                    </Link>
                </div>
                <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(${spending.weeks.length - 1}, minmax(0, 1fr)) minmax(0, 1.8fr)` }}>
                    {spending.weeks.map((cents, i) => {
                        const current = i === spending.weeks.length - 1;
                        return (
                            <div key={i} className="flex min-w-0 flex-col gap-1.5">
                                <span className="flex h-12 items-end">
                                    <span
                                        className={`w-full rounded-md ${current ? 'bg-accent-strong' : 'bg-text-inverse/15'}`}
                                        style={{ height: `${Math.max(6, (cents / max) * 100)}%` }}
                                    />
                                </span>
                                <span className={`truncate font-mono text-[11px] ${current ? 'text-accent-strong' : 'opacity-60'}`}>
                                    {current ? t('dashboard.this_week') : t('dashboard.week_n', { n: i + 1 })}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

export default function Dashboard({
    family = null,
    recent = [],
    today = [],
    tomorrow = [],
    later = [],
    widgets = {},
    summaries = {},
    // The person's Home sections in their order, hidden ones left out (HomeLayout on the server).
    sections = ['important', 'birthdays', 'today', 'week', 'anytime', 'money', 'modules', 'recent'],
}: {
    sections?: string[];
    family?: Family | null;
    // One live line per enabled module for the "Your modules" grid (DashboardSummaries).
    summaries?: Record<string, ModuleSummary>;
    recent?: DashItem[];
    today?: AgendaItem[];
    tomorrow?: AgendaItem[];
    later?: AgendaItem[];
    // Per-module data (DashboardWidgets): only present when that module is on and has something to show.
    widgets?: {
        spending?: { total: number; currency: string; count: number; weeks: number[] };
        birthdays?: Birthday[];
        tasks?: { undated: number; titles: string[] };
        important_todos?: ImportantData;
    };
}) {
    const { auth, modules } = usePage<SharedData & { modules?: { enabled?: string[] } }>().props;
    const t = useT();
    const locale = useLocale();
    const firstName = auth.user.name.split(' ')[0];
    const now = parseDay(localToday());
    const tomorrowDate = parseDay(localToday(1));
    const dayLine = [
        now.toLocaleDateString(locale, { weekday: 'long', month: 'short', day: 'numeric' }),
        today.length ? t('dashboard.count_today', { count: today.length }) : '',
        tomorrow.length ? t('dashboard.count_tomorrow', { count: tomorrow.length }) : '',
    ]
        .filter(Boolean)
        .join(' · ');
    const shortDay = (d: Date) => d.toLocaleDateString(locale, { weekday: 'short', day: 'numeric' });

    // Each section by its HomeLayout id (Settings > Home dashboard). The person's order comes from the server
    // (`sections`, hidden ones already left out); a section with nothing to show still renders nothing.
    const render: Record<string, () => ReactNode> = {
        important: () => widgets.important_todos && <ImportantCard data={widgets.important_todos} />,
        birthdays: () => widgets.birthdays && <Birthdays people={widgets.birthdays} />,
        today: () => (
            <>
                {today.length > 0 && (
                    <section className="flex flex-col gap-2.5">
                        <SectionLabel label={t('dashboard.today')} count={today.length} right={shortDay(now)} />
                        <AgendaList items={today} />
                    </section>
                )}
                {tomorrow.length > 0 && (
                    <section className="flex flex-col gap-2.5">
                        <SectionLabel label={t('dashboard.tomorrow')} count={tomorrow.length} right={shortDay(tomorrowDate)} />
                        <AgendaList items={tomorrow} />
                    </section>
                )}
            </>
        ),
        week: () => <RestOfWeek items={later} />,
        anytime: () =>
            widgets.tasks && (
                <Link href="/m/tasks" prefetch className={`${card} flex items-center gap-3 px-4 py-3.5`}>
                    <Inbox className="text-primary size-5 shrink-0" />
                    <span className="min-w-0 flex-1">
                        <span className="text-primary block truncate font-semibold">{t('dashboard.anytime', { count: widgets.tasks.undated })}</span>
                        <span className="text-muted-foreground block truncate text-sm">{widgets.tasks.titles.join(', ')}</span>
                    </span>
                    <ChevronRight className="text-muted-foreground size-5 shrink-0" />
                </Link>
            ),
        money: () => widgets.spending && <MoneyCard spending={widgets.spending} />,
        modules: () => <YourModules enabled={modules?.enabled ?? []} summaries={summaries} />,
        recent: () =>
            recent.length > 0 && (
                <section className="flex flex-col gap-2.5">
                    <SectionLabel label={t('dashboard.jump_back_in')} />
                    {/* Swipes sideways on phones (data-scroll-x tells the viewport check it's meant to). */}
                    <div data-scroll-x className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 lg:mx-0 lg:flex-wrap lg:px-0">
                        {recent.map((it) => {
                            const Icon = MODULES[it.category ?? '']?.icon ?? Blocks;
                            return (
                                <Link
                                    key={it.href}
                                    href={it.href}
                                    prefetch
                                    className="bg-surface-card text-primary flex max-w-[16rem] shrink-0 items-center gap-2 rounded-full border px-4 py-2.5 font-semibold"
                                >
                                    <Icon className="size-4 shrink-0" />
                                    <span className="truncate">{it.title}</span>
                                </Link>
                            );
                        })}
                    </div>
                </section>
            ),
    };
    // Desktop keeps its two columns (the day left; money, modules, recents right), each in the person's order. On
    // phones the columns are `display: contents`, so every section is an item of the one outer column and the CSS
    // `order` puts them in the saved order across both. `empty:hidden` drops a wrapper whose section had nothing,
    // so it doesn't leave a double gap.
    const column = (ids: string[]) =>
        sections
            .filter((id) => ids.includes(id))
            .map((id) => (
                <div key={id} style={{ order: sections.indexOf(id) }} className="flex min-w-0 flex-col gap-7 empty:hidden">
                    {render[id]()}
                </div>
            ));

    return (
        <AppLayout breadcrumbs={[{ title: t('nav.home'), href: '/dashboard' }]} mobileHeader={<HomeTopBar family={family} />}>
            <Head title={t('nav.home')} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-7 p-4 md:p-8 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)] lg:gap-10">
                {/* Left: the day. Right (below on phones): money, modules, recents. */}
                <div className="contents lg:flex lg:min-w-0 lg:flex-col lg:gap-7">
                    <div style={{ order: -1 }}>
                        <h1 className="font-heading text-primary text-[28px] leading-tight font-bold tracking-tight sm:text-4xl">
                            {t(greetingKey())}, {firstName}.
                        </h1>
                        <p className="text-muted-foreground mt-1">{dayLine}</p>
                    </div>
                    {column(['important', 'birthdays', 'today', 'week', 'anytime'])}
                </div>

                <div className="contents lg:flex lg:min-w-0 lg:flex-col lg:gap-7">{column(['money', 'modules', 'recent'])}</div>
            </div>
        </AppLayout>
    );
}
