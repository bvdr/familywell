import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, FolderKanban, ListChecks, MoreHorizontal, NotebookPen, Pencil, Repeat2, Share2, Trash2, Users } from 'lucide-react';
import { useRef, useState } from 'react';

import { stripe } from '@/components/calendar/agenda';
import { EventSheet, type EventForm } from '@/components/calendar/event-sheet';
import { LucideIcon } from '@/components/lucide-icon';
import { MemberAvatar } from '@/components/member-avatar';
import { SettingsGroup, SettingsRow } from '@/components/settings-ui';
import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { TaskRows } from '@/components/tasks/task-rows';
import { type TaskMember, type TaskNode } from '@/components/tasks/task-tree';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { daysBetween } from '@/lib/calendar';
import { localToday } from '@/lib/date-groups';
import { useLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

type Payload = Omit<EventForm, 'project'> & {
    id: number;
    project: { id: number; name: string; url: string } | null;
    visibility: string;
    url: string;
};

export default function CalendarEvent({
    event,
    canEdit,
    canDelete,
    members,
    tasks,
    share,
    contacts,
    projects,
}: {
    event: Payload;
    canEdit: boolean;
    canDelete: boolean;
    members: TaskMember[];
    tasks: TaskNode[];
    share: ShareState;
    contacts: { id: number; name: string }[];
    projects: { id: number; name: string }[] | null;
}) {
    const t = useT();
    const locale = useLocale();
    // false | 'details' (⋯ › Edit) | 'dates' (the date line: the sheet opens on the start date's calendar).
    const [editing, setEditing] = useState<false | 'details' | 'dates'>(false);
    const [sharing, setSharing] = useState(false);
    // The nav's "+" adds a to-do here (like a task list), not a new event.
    const addInputRef = useRef<HTMLInputElement>(null);
    useAddAction(
        () => {
            addInputRef.current?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            addInputRef.current?.focus();
        },
        { label: 'add.page_todo', icon: ListChecks },
    );
    const fmt = (d: string) => new Date(d + 'T12:00').toLocaleDateString(locale, { weekday: 'short', month: 'short', day: 'numeric' });
    const time = (x: string | null) => (x ? x.replace(/^0(\d)/, '$1') : '');
    const days = daysBetween(event.start_date, event.end_date) + 1;
    const today = localToday();
    // "Sun, Oct 18 → Sun, Oct 25 · all day" / "Thu, Oct 22 · 3:00–4:00".
    const when = [
        event.start_date === event.end_date ? fmt(event.start_date) : `${fmt(event.start_date)} → ${fmt(event.end_date)}`,
        event.start_time ? `${time(event.start_time)}–${time(event.end_time)}` : t('calendar.all_day_short'),
    ].join(' · ');
    // Multi-day (C3): a segment per day, done ones in the event's colour, today inked; where you are in it.
    const nth = daysBetween(event.start_date, today) + 1;
    const progress =
        nth < 1
            ? t('calendar.starts_in', { count: 1 - nth })
            : nth > days
              ? t('calendar.done_label')
              : nth === days
                ? t('calendar.last_day_of', { total: days })
                : t('calendar.day_progress', { n: nth, total: days, left: days - nth });
    const destroy = () => confirm(t('calendar.delete_confirm')) && router.delete(`/m/calendar/events/${event.id}`);
    const users = event.people.filter((p) => p.type === 'user');
    const everyone = members.length > 1 && members.every((m) => users.some((u) => u.id === m.id));
    const whoText = everyone ? t('calendar.whole_family') : event.people.map((p) => p.name.split(' ')[0]).join(', ');
    const round = 'bg-surface text-primary flex size-10 items-center justify-center rounded-full';
    const edit = canEdit ? () => setEditing('details') : undefined;

    const actions = (
        <div className="flex gap-2">
            <button type="button" aria-label={t('calendar.share')} onClick={() => setSharing(true)} className={round}>
                <Share2 className="size-5" />
            </button>
            {(canEdit || canDelete) && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button type="button" aria-label={t('calendar.options')} className={round}>
                            <MoreHorizontal className="size-5" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        {canEdit && (
                            <DropdownMenuItem onSelect={() => setEditing('details')}>
                                <Pencil className="size-4" /> {t('calendar.edit')}
                            </DropdownMenuItem>
                        )}
                        {canDelete && (
                            <DropdownMenuItem className="text-destructive focus:text-destructive" onSelect={destroy}>
                                <Trash2 className="size-4" /> {t('calendar.delete')}
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
    // Phones (C3): the back pill and the event's actions instead of the app bar.
    const mobileHeader = (
        <div className="flex w-full items-center justify-between gap-2">
            <Link
                href="/m/calendar"
                prefetch
                className="bg-surface text-primary inline-flex min-h-10 items-center gap-1.5 rounded-full py-2 pr-4 pl-3 text-base font-semibold"
            >
                <ChevronLeft className="size-5" />
                {t('nav.calendar')}
            </Link>
            {actions}
        </div>
    );
    const open = tasks.filter((x) => !x.done).length;

    return (
        <AppLayout
            breadcrumbs={[
                { title: t('nav.calendar'), href: '/m/calendar' },
                { title: event.title, href: event.url },
            ]}
            actions={actions}
            mobileHeader={mobileHeader}
        >
            <Head title={event.title} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4 pb-28">
                <div className="flex flex-col gap-3">
                    <span className={`flex size-14 items-center justify-center rounded-2xl ${itemTone(event.color)}`}>
                        <LucideIcon name={event.icon ?? 'calendar'} className="size-7" />
                    </span>
                    <h1 className="font-heading text-primary text-4xl font-extrabold tracking-tight break-words">{event.title}</h1>
                    {/* Tapping the dates is how people try to change them (user, 2026-11-05): it opens the edit sheet on
                        the start date's calendar. */}
                    {canEdit ? (
                        <button
                            type="button"
                            onClick={() => setEditing('dates')}
                            className="text-muted-foreground self-start text-left underline-offset-4 hover:underline"
                        >
                            {when}
                        </button>
                    ) : (
                        <p className="text-muted-foreground">{when}</p>
                    )}
                    {days > 1 && (
                        <div className="flex flex-col gap-2">
                            <div className="flex gap-1" aria-hidden>
                                {Array.from({ length: days }, (_, k) => (
                                    <span
                                        key={k}
                                        className={`h-1.5 flex-1 rounded-full ${k + 1 < nth ? stripe(event.color) : k + 1 === nth ? 'bg-primary' : 'bg-border'}`}
                                    />
                                ))}
                            </div>
                            <p className="text-primary font-mono text-xs font-semibold tracking-[0.1em] uppercase">{progress}</p>
                        </div>
                    )}
                </div>

                {/* Who, which project, repeat, notes: one group (tapping a row edits the event). */}
                <SettingsGroup>
                    {/* Who: the faces and "Whole family" / the names; its own row so the words get the width. */}
                    <button
                        type="button"
                        onClick={edit}
                        disabled={!edit}
                        className="flex min-h-14 w-full items-center gap-3.5 border-b px-4 py-3 text-left"
                    >
                        <Users className="text-primary size-5 shrink-0" />
                        <span className="text-primary shrink-0 text-[17px]">{t('calendar.who')}</span>
                        <span className="flex min-w-0 flex-1 items-center justify-end gap-2">
                            <span className="flex shrink-0 -space-x-1.5">
                                {event.people.slice(0, 4).map((p) => (
                                    <MemberAvatar
                                        key={`${p.type}:${p.id}`}
                                        userId={p.type === 'user' ? p.id : null}
                                        name={p.name}
                                        ring
                                        className="size-6 text-[10px]"
                                    />
                                ))}
                            </span>
                            <span className="text-primary truncate font-semibold">{whoText || t('calendar.nobody')}</span>
                        </span>
                    </button>
                    {(event.project || projects) && (
                        <SettingsRow
                            icon={FolderKanban}
                            title={t('calendar.project')}
                            value={<span className="text-primary font-semibold">{event.project?.name ?? t('calendar.no_project')}</span>}
                            href={event.project?.url}
                            onClick={event.project ? undefined : edit}
                            right={<span />}
                        />
                    )}
                    <SettingsRow
                        icon={Repeat2}
                        title={t('calendar.repeat')}
                        value={<span className="text-primary font-semibold">{t(`calendar.repeat_${event.repeat}`)}</span>}
                        onClick={edit}
                        right={<span />}
                    />
                    {event.notes && (
                        <button type="button" onClick={edit} className="flex w-full items-start gap-3.5 px-4 py-3 text-left">
                            <NotebookPen className="text-primary mt-0.5 size-5 shrink-0" />
                            <span className="text-primary shrink-0 text-[17px]">{t('calendar.notes')}</span>
                            <span className="text-primary min-w-0 flex-1 text-right font-semibold break-words whitespace-pre-wrap">
                                {event.notes}
                            </span>
                        </button>
                    )}
                </SettingsGroup>

                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                        {t('calendar.todos')} <span className="text-primary">{t('calendar.left', { count: open })}</span>
                    </h2>
                    <TaskRows
                        tasks={tasks}
                        members={members}
                        family={members}
                        addUrl={`/m/calendar/events/${event.id}/tasks`}
                        taskUrl={(id) => `/m/calendar/tasks/${id}`}
                        addons={false}
                        canEdit={canEdit}
                        addInputRef={addInputRef}
                    />
                </section>
            </div>
            {canEdit && (
                <EventSheet
                    open={editing !== false}
                    onOpenChange={(v) => setEditing(v ? 'details' : false)}
                    pickDate={editing === 'dates'}
                    event={{ ...event, project: event.project ? { id: event.project.id } : null }}
                    defaultDate={event.start_date}
                    members={members}
                    contacts={contacts}
                    projects={projects}
                />
            )}
            {/* No read-only invite link: the server never issues one for events (shareState link: null). */}
            <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={event.title} withLink={false} />
        </AppLayout>
    );
}
