import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, FolderKanban, MoreHorizontal, Pencil, Share2, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

import { EventSheet, type EventForm } from '@/components/calendar/event-sheet';
import { LucideIcon } from '@/components/lucide-icon';
import { MemberAvatar } from '@/components/member-avatar';
import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { TaskTree, type TaskMember, type TaskNode } from '@/components/tasks/task-tree';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { daysBetween } from '@/lib/calendar';
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
    const [editing, setEditing] = useState(false);
    const [sharing, setSharing] = useState(false);
    // The nav's "+" adds a to-do here (like a task list), not a new event.
    const addInputRef = useRef<HTMLInputElement>(null);
    useAddAction(() => {
        addInputRef.current?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        addInputRef.current?.focus();
    });
    const fmt = (d: string) => new Date(d + 'T12:00').toLocaleDateString(locale, { day: 'numeric', month: 'short' });
    const days = daysBetween(event.start_date, event.end_date) + 1;
    // "12 Jul – 21 Jul · 10 days · 09:00–10:00 · Every year" (spec hero line).
    const when = [
        event.start_date === event.end_date ? fmt(event.start_date) : `${fmt(event.start_date)} – ${fmt(event.end_date)}`,
        days > 1 ? t('calendar.days', { count: days }) : null,
        event.start_time ? `${event.start_time}–${event.end_time}` : null,
        event.repeat !== 'none' ? t(`calendar.repeat_${event.repeat}`) : null,
    ]
        .filter(Boolean)
        .join(' · ');
    const destroy = () => confirm(t('calendar.delete_confirm')) && router.delete(`/m/calendar/events/${event.id}`);

    return (
        <AppLayout
            breadcrumbs={[
                { title: t('nav.calendar'), href: '/m/calendar' },
                { title: event.title, href: event.url },
            ]}
        >
            <Head title={event.title} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                <div className="flex items-start gap-3">
                    <span className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${itemTone(event.color)}`}>
                        <LucideIcon name={event.icon ?? 'calendar'} className="size-6" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h1 className="font-heading text-primary text-2xl break-words">{event.title}</h1>
                        <p className="text-muted-foreground text-sm">{when}</p>
                    </div>
                    <Button variant="ghost" size="icon" aria-label={t('calendar.share')} onClick={() => setSharing(true)}>
                        <Share2 className="size-5" />
                    </Button>
                    {(canEdit || canDelete) && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" aria-label={t('calendar.options')}>
                                    <MoreHorizontal className="size-5" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-52">
                                {canEdit && (
                                    <DropdownMenuItem onSelect={() => setEditing(true)}>
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

                {event.people.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {event.people.map((p) => (
                            <span
                                key={`${p.type}:${p.id}`}
                                className="bg-surface-card flex items-center gap-1.5 rounded-full border py-1 pr-3 pl-1 text-sm"
                            >
                                <MemberAvatar userId={p.type === 'user' ? p.id : null} name={p.name} />
                                {p.name}
                            </span>
                        ))}
                    </div>
                )}

                {event.notes && <p className="text-foreground whitespace-pre-wrap">{event.notes}</p>}

                {event.project && (
                    <Link
                        href={event.project.url}
                        className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-2xl border p-3 transition-colors"
                    >
                        <span className="bg-tile text-tile-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                            <FolderKanban className="size-4.5" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="text-muted-foreground block text-xs">{t('calendar.project')}</span>
                            <span className="text-primary block truncate font-medium">{event.project.name}</span>
                        </span>
                        <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                    </Link>
                )}

                <section className="flex flex-col gap-2">
                    <h2 className="font-heading text-primary text-lg">{t('calendar.todos')}</h2>
                    <TaskTree
                        tasks={tasks}
                        members={members}
                        canEdit={canEdit}
                        emptyHint={t('calendar.todos_empty')}
                        addUrl={`/m/calendar/events/${event.id}/tasks`}
                        taskUrl={(id) => `/m/calendar/tasks/${id}`}
                        reorderUrl={`/m/calendar/events/${event.id}/tasks/reorder`}
                        addInputRef={addInputRef}
                    />
                </section>
            </div>
            {canEdit && (
                <EventSheet
                    open={editing}
                    onOpenChange={setEditing}
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
