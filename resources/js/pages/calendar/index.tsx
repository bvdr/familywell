import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { useState } from 'react';

import { EventSheet } from '@/components/calendar/event-sheet';
import { MonthView } from '@/components/calendar/month-view';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { shiftDate, ymd, type CalItem } from '@/lib/calendar';
import { openNewOnArrival } from '@/lib/create-intent';
import { useLocale, useT } from '@/lib/i18n';

type Named = { id: number; name: string };
export type CalendarProps = {
    view: 'month' | 'timeline';
    zoom: 'week' | 'month';
    date: string;
    range: { from: string; to: string };
    items: CalItem[];
    sources: string[];
    members: Named[];
    contacts: Named[];
    projects: Named[] | null;
    feedUrl: string | null;
};

// View, zoom and date live in the query string (back/forward + shareable); the server answers with the range
// and its items. Navigation is a partial reload of only those props: members/contacts/projects stay put.
export default function CalendarIndex({ view, zoom, date, range, items, members, contacts, projects }: CalendarProps) {
    const t = useT();
    const locale = useLocale();
    const todayYmd = ymd(new Date());
    // Phones list the selected day under the grid: today when it's in the shown month, else the date asked for.
    const [selected, setSelected] = useState(date.slice(0, 7) === todayYmd.slice(0, 7) ? todayYmd : date);
    const [sheet, setSheet] = useState<{ open: boolean; day: string }>(() => ({ open: openNewOnArrival(), day: selected }));
    const add = (day = selected) => setSheet({ open: true, day });
    useAddAction(() => add());

    const go = (q: Partial<Pick<CalendarProps, 'view' | 'zoom' | 'date'>>) => {
        if (q.date) setSelected(q.date);
        router.get(
            '/m/calendar',
            { view, zoom, date, ...q },
            { preserveState: true, preserveScroll: true, only: ['view', 'zoom', 'date', 'range', 'items'] },
        );
    };
    const label = new Date(date + 'T12:00').toLocaleDateString(locale, { month: 'long', year: 'numeric' });

    return (
        <AppLayout breadcrumbs={[{ title: t('nav.calendar'), href: '/m/calendar' }]}>
            <Head title={t('nav.calendar')} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4">
                <div className="flex items-center gap-1">
                    <h1 className="font-heading text-primary mr-auto truncate text-2xl first-letter:uppercase">{label}</h1>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('calendar.previous')}
                        onClick={() => go({ date: shiftDate(view, zoom, date, -1) })}
                    >
                        <ChevronLeft className="size-5" />
                    </Button>
                    <Button variant="outline" size="sm" className="rounded-full" onClick={() => go({ date: todayYmd })}>
                        {t('calendar.today')}
                    </Button>
                    <Button variant="ghost" size="icon" aria-label={t('calendar.next')} onClick={() => go({ date: shiftDate(view, zoom, date, 1) })}>
                        <ChevronRight className="size-5" />
                    </Button>
                    {/* Hidden on mobile: the nav's context-aware "+" adds an event there. */}
                    <Button size="icon" className="ml-2 hidden rounded-full md:inline-flex" onClick={() => add()} aria-label={t('calendar.new')}>
                        <Plus className="size-5" />
                    </Button>
                </div>

                {/* Month | Timeline switch, filters and subscribe arrive with Tasks 7, 8 and 10; month is the only view so far. */}
                <MonthView items={items} range={range} date={date} selected={selected} onSelect={setSelected} onAdd={add} />
            </div>
            <EventSheet
                open={sheet.open}
                onOpenChange={(open) => setSheet((s) => ({ ...s, open }))}
                defaultDate={sheet.day}
                members={members}
                contacts={contacts}
                projects={projects}
            />
        </AppLayout>
    );
}
