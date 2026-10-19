import { Head } from '@inertiajs/react';
import { CalendarDays, MapPin } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { daysBetween } from '@/lib/calendar';
import { useLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

type ExternalEvent = {
    title: string;
    location: string | null;
    notes: string | null;
    start_date: string;
    end_date: string;
    start_time: string | null;
    end_time: string | null;
};

// An event from a subscribed calendar (issue #222): read-only, so no edit/share/to-dos like calendar/event. It
// lives in the other calendar; this page only says when, where, and which calendar it came from.
export default function ExternalEvent({ event, calendar }: { event: ExternalEvent; calendar: { name: string; color: string | null } }) {
    const t = useT();
    const locale = useLocale();
    const fmt = (d: string) => new Date(d + 'T12:00').toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short' });
    const days = daysBetween(event.start_date, event.end_date) + 1;
    const when = [
        event.start_date === event.end_date ? fmt(event.start_date) : `${fmt(event.start_date)} – ${fmt(event.end_date)}`,
        days > 1 ? t('calendar.days', { count: days }) : null,
        event.start_time ? `${event.start_time}–${event.end_time}` : t('calendar.all_day'),
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <AppLayout
            breadcrumbs={[
                { title: t('nav.calendar'), href: '/m/calendar' },
                { title: event.title, href: '' },
            ]}
        >
            <Head title={event.title} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <div className="flex items-start gap-3">
                    <span className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${itemTone(calendar.color)}`}>
                        <CalendarDays className="size-6" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h1 className="font-heading text-primary text-2xl break-words">{event.title}</h1>
                        <p className="text-muted-foreground text-sm">{when}</p>
                    </div>
                </div>

                {event.location && (
                    <p className="text-foreground flex items-start gap-2 break-words">
                        <MapPin className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                        <span className="min-w-0">{event.location}</span>
                    </p>
                )}

                {event.notes && <p className="text-foreground break-words whitespace-pre-wrap">{event.notes}</p>}

                <p className="text-muted-foreground border-t pt-4 text-sm">{t('calendar.external_from', { name: calendar.name })}</p>
            </div>
        </AppLayout>
    );
}
