import { useAddAction } from '@/lib/add-action';
import { openNewOnArrival } from '@/lib/create-intent';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Archive, Check, ListChecks, Lock, Plus, RotateCcw, UserCheck, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import { HomeTopBar } from '@/components/home-top-bar';
import InputError from '@/components/input-error';
import { MemberAvatar } from '@/components/member-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { localToday } from '@/lib/date-groups';
import { appLocale, relativeWord, useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { ago, LIST_COLORS, LIST_ICONS, listColor, listIcon } from '@/lib/tasks';
import { type BreadcrumbItem, type SharedData } from '@/types';

type Member = { id: number; name: string };
type List = {
    id: number;
    name: string;
    color: string;
    icon: string;
    open: number;
    next: string | null;
    archived: boolean;
    visibility: string;
    touchedAt: string | null;
    touchedBy: Member | null;
};
type Row = { id: number; title: string; done: boolean; dueDate: string | null; assignedTo: number | null; listId: number; list: string | null };

// The overview reopens on the view you left (design T1: "The app reopens on whichever view you left"), per device.
const VIEW_KEY = 'fw:tasks-view';
const readView = (): 'lists' | 'tasks' => {
    try {
        return localStorage.getItem(VIEW_KEY) === 'tasks' ? 'tasks' : 'lists';
    } catch {
        return 'lists';
    }
};
const eyebrow = 'text-muted-foreground font-mono text-xs font-semibold tracking-[0.12em] uppercase';

// A list card (T1): tile + how many are open, the name, "8 left · next: tiles" (a private list says "only you"), and
// who touched it last, when.
function ListCard({ list }: { list: List }) {
    const t = useT();
    const Icon = listIcon(list.icon);
    const sub = [
        t('tasks.n_left', { count: list.open }),
        list.visibility === 'private' ? t('tasks.only_you') : list.next && t('tasks.next', { title: list.next }),
    ].filter(Boolean);
    return (
        <Link
            href={route('tasks.show', list.id)}
            prefetch
            className="bg-surface-card hover:border-accent flex min-w-0 flex-col gap-3 rounded-2xl border p-4"
        >
            <div className="flex items-start justify-between gap-2">
                <span className={`flex size-11 shrink-0 items-center justify-center rounded-xl ${listColor(list.color)}`}>
                    <Icon className="size-5.5" />
                </span>
                <span className="font-heading text-primary text-2xl leading-none font-bold tabular-nums">{list.open}</span>
            </div>
            <div className="min-w-0 flex-1">
                <div className="text-primary text-[17px] leading-snug font-bold break-words">{list.name}</div>
                <div className="text-muted-foreground mt-0.5 line-clamp-2 text-sm break-words">{sub.join(' · ')}</div>
            </div>
            {list.touchedAt && (
                <div className="flex min-w-0 items-center gap-1.5">
                    {list.touchedBy && <MemberAvatar userId={list.touchedBy.id} name={list.touchedBy.name} className="size-5 text-[10px]" />}
                    <span className="text-primary truncate font-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                        {ago(list.touchedAt)}
                    </span>
                </div>
            )}
        </Link>
    );
}

// "Since Mon" / "Today" / "Thu" / "Nov 12": a row's due, said the way the group around it needs.
function dueText(due: string, today: string, t: ReturnType<typeof useT>): string {
    const d = new Date(`${due}T00:00:00`);
    const days = Math.round((d.getTime() - new Date(`${today}T00:00:00`).getTime()) / 864e5);
    const short = (o: Intl.DateTimeFormatOptions) => d.toLocaleDateString(appLocale(), o);
    if (days < 0) return t('tasks.since', { day: days >= -6 ? short({ weekday: 'short' }) : short({ month: 'short', day: 'numeric' }) });
    if (days === 0) return relativeWord(0, 'day');
    if (days === 1) return relativeWord(1, 'day');
    return days < 7 ? short({ weekday: 'short' }) : short({ month: 'short', day: 'numeric' });
}

// Overdue / Today / This week (to Sunday) / Later / No date, in that order.
function groupOf(due: string | null, today: string): string {
    if (!due) return 'none';
    if (due < today) return 'overdue';
    if (due === today) return 'today';
    const toSunday = (7 - new Date(`${today}T00:00:00Z`).getUTCDay()) % 7;
    return due <= localToday(toSunday) ? 'week' : 'later';
}
const GROUPS = ['overdue', 'today', 'week', 'later', 'none'];

// The Tasks view (T1b): every open task of every list by when it's due. The people strip: everyone, you, then whoever
// has the most open; people with nothing open sit at the end, faded. Done (the switch) adds the done ones last.
function TasksView({ tasks, members, done }: { tasks: Row[]; members: Member[]; done: Row[] | null }) {
    const t = useT();
    const me = usePage<SharedData>().props.auth.user.id;
    const [who, setWho] = useState<number | null>(null);
    const doneOpt = useOptimistic<boolean>();
    const today = localToday();
    const count = (id: number) => tasks.filter((r) => r.assignedTo === id).length;
    const people = [...members].sort((a, b) => (a.id === me ? -1 : b.id === me ? 1 : count(b.id) - count(a.id)));
    const shown = (rows: Row[]) => rows.filter((r) => who === null || r.assignedTo === who);
    const toggle = (r: Row) => {
        const next = !doneOpt.get(r.id, r.done);
        doneOpt.set(r.id, next);
        void save('patch', route('tasks.tasks.update', r.id), { done: next }).then(() => doneOpt.clear(r.id));
    };
    const groups = GROUPS.map((g) => ({ key: g, rows: shown(tasks).filter((r) => groupOf(r.dueDate, today) === g) })).filter((g) => g.rows.length);
    if (done?.length) groups.push({ key: 'done', rows: shown(done) });

    const chip = (on: boolean, faded = false) =>
        `flex shrink-0 items-center gap-1.5 rounded-full border py-1.5 pr-3 text-sm font-semibold ${on ? 'bg-primary border-primary text-primary-foreground' : 'bg-surface-card text-primary'} ${faded ? 'opacity-50' : ''}`;
    return (
        <div className="flex flex-col gap-5">
            <div data-scroll-x className="-mx-4 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none]">
                <button type="button" onClick={() => setWho(null)} className={`${chip(who === null)} pl-3.5`} aria-pressed={who === null}>
                    {t('tasks.everyone')}
                    <span className={who === null ? 'text-accent-strong' : 'text-muted-foreground'}>{tasks.length}</span>
                </button>
                {people.map((m) => (
                    <button
                        key={m.id}
                        type="button"
                        onClick={() => setWho(m.id)}
                        aria-pressed={who === m.id}
                        className={`${chip(who === m.id, !count(m.id))} pl-1.5`}
                    >
                        <MemberAvatar userId={m.id} name={m.name} className="size-6 text-[11px]" />
                        {m.id === me ? t('tasks.you') : m.name.split(' ')[0]}
                        <span className={who === m.id ? 'text-accent-strong' : 'text-muted-foreground'}>{count(m.id)}</span>
                    </button>
                ))}
            </div>

            {groups.length === 0 && <p className="text-muted-foreground py-6 text-center text-sm">{t('tasks.nothing_open')}</p>}
            {groups.map((g) => (
                <section key={g.key} className="flex flex-col gap-2">
                    <h2 className={`${eyebrow} ${g.key === 'overdue' ? 'text-destructive' : ''}`}>
                        {t(`tasks.group_${g.key}`)} <span className="text-primary">{g.rows.length}</span>
                    </h2>
                    <div className="bg-surface-card divide-y overflow-hidden rounded-2xl border">
                        {g.rows.map((r) => {
                            const isDone = doneOpt.get(r.id, r.done);
                            const name = members.find((m) => m.id === r.assignedTo)?.name;
                            const meta = [r.dueDate && !isDone && dueText(r.dueDate, today, t), r.list].filter(Boolean).join(' · ');
                            const tone = g.key === 'overdue' ? 'text-destructive' : g.key === 'today' ? 'text-amber-700' : 'text-muted-foreground';
                            return (
                                <div key={r.id} className="flex items-center gap-3 px-4 py-3">
                                    <button
                                        type="button"
                                        onClick={() => toggle(r)}
                                        aria-label={isDone ? t('tasks.mark_not_done') : t('tasks.mark_done')}
                                        className={`flex size-7 shrink-0 items-center justify-center rounded-full border-2 ${isDone ? 'border-primary bg-primary text-primary-foreground' : 'border-primary'}`}
                                    >
                                        {isDone && <Check className="size-4" />}
                                    </button>
                                    <Link href={route('tasks.show', r.listId)} className="min-w-0 flex-1">
                                        <span
                                            className={`block text-base font-semibold break-words ${isDone ? 'text-muted-foreground line-through' : 'text-primary'}`}
                                        >
                                            {r.title}
                                        </span>
                                        {meta && <span className={`block truncate text-sm ${isDone ? 'text-muted-foreground' : tone}`}>{meta}</span>}
                                    </Link>
                                    {name && <MemberAvatar userId={r.assignedTo} name={name} className="size-7 text-xs" />}
                                </div>
                            );
                        })}
                    </div>
                </section>
            ))}
        </div>
    );
}

export default function Lists({ lists, tasks, members, doneTasks }: { lists: List[]; tasks: Row[]; members: Member[]; doneTasks?: Row[] }) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.tasks'), href: '/m/tasks' }];
    const VISIBILITY = [
        { key: 'family', icon: Users, title: t('tasks.visibility_family_title'), sub: t('tasks.visibility_family_sub') },
        { key: 'invited', icon: UserCheck, title: t('tasks.visibility_invited_title'), sub: t('tasks.visibility_invited_sub') },
        { key: 'private', icon: Lock, title: t('tasks.visibility_private_title'), sub: t('tasks.visibility_private_sub') },
    ] as const;
    const [creating, setCreating] = useState(openNewOnArrival);
    const [view, setViewState] = useState(readView);
    const setView = (v: 'lists' | 'tasks') => {
        setViewState(v);
        try {
            localStorage.setItem(VIEW_KEY, v);
        } catch {
            // storage blocked: the view just isn't remembered
        }
    };
    // Done is off by default (design): switched on, the done ones load once (a partial reload).
    const [showDone, setShowDone] = useState(false);
    const toggleDone = () => {
        const next = !showDone;
        setShowDone(next);
        if (next && !doneTasks) router.reload({ only: ['doneTasks'] });
    };
    useAddAction(() => setCreating(true), { label: 'create.tasks', icon: ListChecks, replaces: 'tasks' });
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        color: 'green',
        icon: 'list',
        visibility: 'family',
    });

    const archivedOpt = useOptimistic<boolean>();
    const isArchived = (l: List) => archivedOpt.get(l.id, l.archived);
    const active = lists.filter((l) => !isArchived(l));
    const archived = lists.filter((l) => isArchived(l));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('tasks.lists.store'), {
            onSuccess: () => {
                reset();
                setCreating(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs} mobileHeader={<HomeTopBar />}>
            <Head title={t('nav.tasks')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-3">
                    <h1 className="font-heading text-primary text-4xl font-extrabold tracking-tight">{t('nav.tasks')}</h1>
                    {view === 'tasks' ? (
                        <label className="text-primary flex items-center gap-2 text-sm font-semibold">
                            {t('tasks.done_toggle')}
                            <button
                                type="button"
                                role="switch"
                                aria-checked={showDone}
                                onClick={toggleDone}
                                className={`relative h-7 w-12 rounded-full transition-colors ${showDone ? 'bg-primary' : 'bg-border'}`}
                            >
                                <span
                                    className={`absolute top-1 size-5 rounded-full shadow-sm transition-all ${showDone ? 'bg-accent-strong left-6' : 'left-1 bg-white'}`}
                                />
                            </button>
                        </label>
                    ) : (
                        // Hidden on mobile: the nav's context-aware "+" creates a list there. Desktop has no nav "+".
                        <Button
                            size="icon"
                            className="hidden rounded-full md:inline-flex"
                            onClick={() => setCreating((v) => !v)}
                            aria-label={t('tasks.new_list')}
                        >
                            <Plus className="size-5" />
                        </Button>
                    )}
                </div>

                {/* Lists / Tasks: two ways in (T1). */}
                <div className="bg-surface grid grid-cols-2 rounded-full border p-1">
                    {(['lists', 'tasks'] as const).map((v) => (
                        <button
                            key={v}
                            type="button"
                            aria-pressed={view === v}
                            onClick={() => setView(v)}
                            className={`rounded-full py-2 text-base font-semibold ${view === v ? 'bg-accent-strong text-accent-strong-foreground' : 'text-primary'}`}
                        >
                            {t(`tasks.tab_${v}`)}
                        </button>
                    ))}
                </div>

                <ResponsiveModal
                    open={creating}
                    onOpenChange={setCreating}
                    title={t('tasks.new_list')}
                    footer={
                        <Button type="submit" form="new-list" disabled={processing || !data.name.trim()} className="w-full">
                            <Plus className="size-4" /> {t('tasks.create_list')}
                        </Button>
                    }
                >
                    <form id="new-list" onSubmit={submit} className="flex flex-col gap-5">
                        <div className="flex items-center gap-3">
                            <span className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${listColor(data.color)}`}>
                                {(() => {
                                    const Icon = listIcon(data.icon);
                                    return <Icon className="size-6" />;
                                })()}
                            </span>
                            <Input
                                autoFocus
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder={t('tasks.list_name_placeholder')}
                                className="text-base"
                            />
                        </div>
                        <InputError message={errors.name} />

                        <div className="grid gap-2">
                            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('tasks.color_icon')}</Label>
                            <div className="flex flex-wrap gap-2">
                                {Object.keys(LIST_COLORS).map((c) => (
                                    <button
                                        key={c}
                                        type="button"
                                        onClick={() => setData('color', c)}
                                        aria-label={t('tasks.color_aria', { color: c })}
                                        className={`flex size-9 items-center justify-center rounded-full ${listColor(c)} ${data.color === c ? 'ring-primary ring-2 ring-offset-2' : ''}`}
                                    >
                                        {data.color === c && <Check className="size-4" />}
                                    </button>
                                ))}
                            </div>
                            <div className="mt-1 flex flex-wrap gap-2">
                                {Object.keys(LIST_ICONS).map((ic) => {
                                    const Icon = listIcon(ic);
                                    return (
                                        <button
                                            key={ic}
                                            type="button"
                                            onClick={() => setData('icon', ic)}
                                            aria-label={t('tasks.icon_aria', { icon: ic })}
                                            className={`flex size-9 items-center justify-center rounded-lg border transition-colors ${
                                                data.icon === ic
                                                    ? 'border-primary bg-accent-subtle text-primary'
                                                    : 'border-border text-muted-foreground'
                                            }`}
                                        >
                                            <Icon className="size-4.5" />
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('tasks.who_can_view')}</Label>
                            <div className="flex flex-col gap-2">
                                {VISIBILITY.map((v) => {
                                    const on = data.visibility === v.key;
                                    return (
                                        <button
                                            key={v.key}
                                            type="button"
                                            onClick={() => setData('visibility', v.key)}
                                            className={`flex items-center gap-3 rounded-xl border p-3 text-left transition-colors ${
                                                on ? 'border-primary bg-accent-subtle' : 'border-border'
                                            }`}
                                        >
                                            <v.icon className="text-primary size-5 shrink-0" />
                                            <div className="min-w-0 flex-1">
                                                <div className="text-primary text-sm font-medium">{v.title}</div>
                                                <div className="text-muted-foreground text-xs">{v.sub}</div>
                                            </div>
                                            {on && <Check className="text-primary size-4 shrink-0" />}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </form>
                </ResponsiveModal>

                {view === 'tasks' ? (
                    <TasksView tasks={tasks} members={members} done={showDone ? (doneTasks ?? null) : null} />
                ) : (
                    <>
                        {active.length === 0 && !creating && (
                            <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                                <ListChecks className="size-6" />
                                <p className="text-sm">{t('tasks.empty_lists')}</p>
                            </div>
                        )}
                        {active.length > 0 && (
                            <div className="flex items-baseline justify-between">
                                <h2 className={eyebrow}>
                                    {t('tasks.tab_lists')} <span className="text-primary">{active.length}</span>
                                </h2>
                                <span className="text-primary text-sm font-semibold">{t('tasks.last_edited')}</span>
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
                            {active.map((l) => (
                                <ListCard key={l.id} list={l} />
                            ))}
                        </div>
                    </>
                )}

                {view === 'lists' && archived.length > 0 && (
                    <div className="mt-2 flex flex-col gap-2">
                        <h2 className="text-muted-foreground flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                            <Archive className="size-3.5" /> {t('tasks.archived')}
                        </h2>
                        {archived.map((l) => {
                            const Icon = listIcon(l.icon);
                            return (
                                <div key={l.id} className="bg-surface flex items-center gap-3 rounded-xl border p-3 opacity-70">
                                    <span className="text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg border">
                                        <Icon className="size-4.5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-primary truncate text-sm font-medium">{l.name}</div>
                                        <div className="text-muted-foreground text-xs">{t('tasks.n_left', { count: l.open })}</div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            archivedOpt.set(l.id, false);
                                            void save('patch', route('tasks.lists.update', l.id), { archived: false }).then(() =>
                                                archivedOpt.clear(l.id),
                                            );
                                        }}
                                        aria-label={t('tasks.restore_list')}
                                        className="text-muted-foreground hover:text-primary flex size-8 items-center justify-center rounded-lg transition-colors"
                                    >
                                        <RotateCcw className="size-4" />
                                    </button>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
