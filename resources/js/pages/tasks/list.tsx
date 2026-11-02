import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArrowUp,
    Check,
    ChevronDown,
    ChevronLeft,
    Copy,
    Lock,
    Mic,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
    UserPlus,
    Users,
} from 'lucide-react';
import { useRef, useState } from 'react';

import { MemberAvatar } from '@/components/member-avatar';
import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { EditTaskModal, type SubTask, type TaskMember, type TaskNode } from '@/components/tasks/task-tree';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import { useVoice, voiceSupported } from '@/hooks/use-voice';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { localToday } from '@/lib/date-groups';
import { appLocale, relativeWord, useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { LIST_COLORS, LIST_ICONS, listColor, listIcon } from '@/lib/tasks';
import { type BreadcrumbItem } from '@/types';

type List = { id: number; name: string; color: string; icon: string; archived: boolean; visibility: string };

type Props = {
    list: List;
    share: ShareState;
    members: TaskMember[];
    tasks: TaskNode[];
    lastDone: { title: string; by: string | null } | null;
    family: TaskMember[];
};

export default function ListDetail({ list, share, members, tasks, lastDone }: Props) {
    const t = useT();
    const [sharing, setSharing] = useState(false);
    const [editing, setEditing] = useState(false);

    // The nav's "+" focuses the add-a-task input on this page.
    const addInputRef = useRef<HTMLInputElement>(null);
    useAddAction(() => {
        addInputRef.current?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        addInputRef.current?.focus();
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.tasks'), href: '/m/tasks' },
        { title: list.name, href: route('tasks.show', list.id) },
    ];

    const Icon = listIcon(list.icon);
    const total = tasks.length;

    const patchList = (data: Record<string, string | number | boolean | null>) => void save('patch', route('tasks.lists.update', list.id), data);
    // Optimistic visibility: the lock icon + menu item flip on tap, snapping back only if the PATCH fails.
    const visibilityOpt = useOptimistic<string>();
    const visibility = visibilityOpt.get(list.id, list.visibility);
    const setVisibility = (v: string) => {
        visibilityOpt.set(list.id, v);
        void save('patch', route('tasks.lists.update', list.id), { visibility: v }).then(() => visibilityOpt.clear(list.id));
    };

    const headerActions = (
        <>
            <button
                type="button"
                onClick={() => setSharing(true)}
                aria-label={t('tasks.share_list')}
                className="bg-surface text-primary flex size-10 items-center justify-center rounded-full"
            >
                <UserPlus className="size-5" />
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={t('tasks.list_options')}
                        className="bg-surface text-primary flex size-10 items-center justify-center rounded-full"
                    >
                        <MoreHorizontal className="size-5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem onSelect={() => setSharing(true)}>
                        <UserPlus className="size-4" /> {t('tasks.share_list')}
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setTimeout(() => setEditing(true), 0)}>
                        <Pencil className="size-4" /> {t('tasks.edit_list')}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    {visibility === 'family' ? (
                        <DropdownMenuItem onSelect={() => setVisibility('private')}>
                            <Lock className="size-4" /> {t('tasks.make_private')}
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => setVisibility('family')}>
                            <Users className="size-4" /> {t('tasks.share_with_family')}
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={() => router.post(route('tasks.lists.duplicate', list.id))}>
                        <Copy className="size-4" /> {t('tasks.duplicate_list')}
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => patchList({ archived: true })}>
                        <Archive className="size-4" /> {t('tasks.archive_list')}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        className="text-destructive focus:text-destructive"
                        onSelect={() => {
                            if (confirm(t('tasks.confirm_delete_list'))) router.delete(route('tasks.lists.destroy', list.id));
                        }}
                    >
                        <Trash2 className="size-4" /> {t('tasks.delete_list')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );

    // Phones (T2): no app bar, the back pill and the list's actions instead. Desktop keeps the breadcrumb header.
    const mobileHeader = (
        <div className="flex w-full items-center justify-between gap-2">
            <Link
                href="/m/tasks"
                prefetch
                className="bg-surface text-primary inline-flex min-h-10 items-center gap-1.5 rounded-full py-2 pr-4 pl-3 text-base font-semibold"
            >
                <ChevronLeft className="size-5" />
                {t('nav.tasks')}
            </Link>
            <div className="flex gap-2">{headerActions}</div>
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs} actions={headerActions} mobileHeader={mobileHeader}>
            <Head title={list.name} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4 pb-28">
                <div className="flex items-center gap-3">
                    <span className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${listColor(list.color)}`}>
                        <Icon className="size-6" />
                    </span>
                    <h1 className="font-heading text-primary flex min-w-0 flex-1 items-center gap-1.5 text-3xl font-extrabold tracking-tight break-words">
                        {list.name}
                        {visibility === 'private' && <Lock className="text-muted-foreground size-4 shrink-0" />}
                    </h1>
                    {/* open / all, as in the design ("7/12"). */}
                    <span className="text-muted-foreground shrink-0 font-mono text-sm font-semibold">
                        {tasks.filter((x) => !x.done).length}/{total}
                    </span>
                </div>

                <ListTasks tasks={tasks} members={members} listId={list.id} lastDone={lastDone} addInputRef={addInputRef} />
            </div>

            <EditListModal open={editing} onOpenChange={setEditing} list={list} />
            {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={list.name} />}
        </AppLayout>
    );
}

// The list's rows (T2): open ones in one card (starred first, then by due date, the server's order), each with its
// due · repeat · note line, a star chip and the face of who's on it; sub-tasks under their task. Done folds into one
// row ("Done · 7  last: Mow lawn, by David") that opens in place. The add bar sits at the bottom, with the mic.
function ListTasks({
    tasks,
    members,
    listId,
    lastDone,
    addInputRef,
}: {
    tasks: TaskNode[];
    members: TaskMember[];
    listId: number;
    lastDone: Props['lastDone'];
    addInputRef: React.RefObject<HTMLInputElement | null>;
}) {
    const t = useT();
    const doneOpt = useOptimistic<boolean>();
    const removed = useOptimistic<boolean>();
    const [editing, setEditing] = useState<SubTask | null>(null);
    const [showDone, setShowDone] = useState(false);
    const [title, setTitle] = useState('');
    const [adding, setAdding] = useState<{ key: number; title: string }[]>([]);
    const voice = useVoice((text) => setTitle(text));
    const today = localToday();
    const taskUrl = (id: number) => route('tasks.tasks.update', id);

    const isDone = (x: SubTask) => doneOpt.get(x.id, x.done);
    const live = tasks.filter((x) => !removed.get(x.id, false));
    // A task ticked here stays in place until the next visit (no jump under the finger); done ones load folded.
    const open = live.filter((x) => !x.done);
    const done = live.filter((x) => x.done);
    const toggle = (x: SubTask) => {
        const next = !isDone(x);
        doneOpt.set(x.id, next);
        void save('patch', taskUrl(x.id), { done: next }).then(() => doneOpt.clear(x.id));
    };
    const add = () => {
        const v = title.trim();
        if (!v) return;
        const key = Date.now() + Math.random();
        setAdding((a) => [...a, { key, title: v }]);
        setTitle('');
        void save('post', route('tasks.tasks.store', listId), { title: v }).then((r) => {
            setAdding((a) => a.filter((p) => p.key !== key));
            if (r !== 'ok') setTitle((c) => c || v);
        });
    };

    const meta = (x: SubTask) => {
        const due = x.dueDate ? dueWords(x.dueDate, today, t('tasks.overdue')) : null;
        const parts = [
            due && (
                <span key="d" className={due.tone}>
                    {due.text}
                </span>
            ),
            x.recurrence && x.recurrence !== 'none' && t(`tasks.rhythm_${x.recurrence}`),
            x.note && t('tasks.note_meta', { note: x.note }),
        ].filter(Boolean);
        return parts.length ? parts.flatMap((p, i) => (i ? [' · ', p] : [p])) : null;
    };
    const row = (x: SubTask, sub = false) => {
        const d = isDone(x);
        const who = members.find((m) => m.id === x.assignedTo);
        const m = meta(x);
        return (
            <div key={x.id} className={`flex items-center gap-3 py-3 pr-4 ${sub ? 'pl-12' : 'pl-4'}`}>
                <button
                    type="button"
                    onClick={() => toggle(x)}
                    aria-label={d ? t('tasks.mark_not_done') : t('tasks.mark_done')}
                    className={`flex ${sub ? 'size-6' : 'size-7'} shrink-0 items-center justify-center rounded-full border-2 ${d ? 'border-primary bg-primary text-primary-foreground' : 'border-primary'}`}
                >
                    {d && <Check className="size-4" />}
                </button>
                <button type="button" onClick={() => setEditing(x)} className="flex min-w-0 flex-1 items-center gap-2 text-left">
                    <span className="min-w-0 flex-1">
                        <span
                            className={`block font-semibold break-words ${sub ? 'text-[15px]' : 'text-base'} ${d ? 'text-muted-foreground line-through' : 'text-primary'}`}
                        >
                            {x.title}
                        </span>
                        {m && !d && <span className="text-muted-foreground block truncate text-sm">{m}</span>}
                    </span>
                    {x.important && !d && (
                        <span
                            className="bg-accent-strong/40 text-primary flex size-7 shrink-0 items-center justify-center rounded-full text-sm"
                            aria-label={t('important_todos.important')}
                        >
                            ★
                        </span>
                    )}
                    {who && <MemberAvatar userId={who.id} name={who.name} className="size-7 text-xs" />}
                </button>
            </div>
        );
    };
    const withSubs = (x: TaskNode) => [row(x), ...x.subtasks.filter((s) => !removed.get(s.id, false)).map((s) => row(s, true))];

    return (
        <>
            {open.length === 0 && adding.length === 0 && <p className="text-muted-foreground py-3 text-sm">{t('tasks.empty_tasks')}</p>}
            {(open.length > 0 || adding.length > 0) && (
                <div className="bg-surface-card divide-y overflow-hidden rounded-2xl border">
                    {open.flatMap(withSubs)}
                    {adding.map((p) => (
                        <div key={p.key} className="flex items-center gap-3 px-4 py-3 opacity-60" aria-busy>
                            <span className="border-primary size-7 shrink-0 rounded-full border-2" />
                            <span className="text-primary text-base font-semibold break-words">{p.title}</span>
                        </div>
                    ))}
                </div>
            )}

            {done.length > 0 && (
                <div className="bg-surface overflow-hidden rounded-2xl border">
                    <button
                        type="button"
                        onClick={() => setShowDone((v) => !v)}
                        aria-expanded={showDone}
                        className="flex w-full items-center gap-2.5 px-4 py-3 text-left"
                    >
                        <span className="bg-accent-subtle text-primary flex size-6 shrink-0 items-center justify-center rounded-full">
                            <Check className="size-3.5" />
                        </span>
                        <span className="text-primary shrink-0 text-base font-semibold">{t('tasks.done_n', { count: done.length })}</span>
                        <span className="text-muted-foreground min-w-0 flex-1 truncate text-right text-sm">
                            {lastDone &&
                                (lastDone.by
                                    ? t('tasks.last_done_by', { title: lastDone.title, name: lastDone.by.split(' ')[0] })
                                    : t('tasks.last_done', { title: lastDone.title }))}
                        </span>
                        <ChevronDown className={`text-muted-foreground size-5 shrink-0 transition-transform ${showDone ? 'rotate-180' : ''}`} />
                    </button>
                    {showDone && <div className="bg-surface-card divide-y border-t">{done.flatMap(withSubs)}</div>}
                </div>
            )}

            {/* The add bar (T2): stays at the bottom, over the tab bar on phones. Enter adds; the mic fills it in. */}
            <div className="sticky bottom-[calc(6.5rem+env(safe-area-inset-bottom))] z-10 mt-auto md:bottom-4">
                <div className="bg-surface-card flex items-center gap-2 rounded-full border py-1.5 pr-1.5 pl-4 shadow-lg">
                    <Plus className="text-primary size-5 shrink-0" />
                    <input
                        ref={addInputRef}
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && add()}
                        placeholder={t('tasks.add_task_placeholder')}
                        aria-label={t('tasks.add_task')}
                        // 16px so iOS doesn't zoom in on focus.
                        className="text-primary placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-base outline-none"
                    />
                    {title.trim() ? (
                        <button
                            type="button"
                            onClick={add}
                            aria-label={t('tasks.add_task')}
                            className="bg-primary text-primary-foreground flex size-10 shrink-0 items-center justify-center rounded-full"
                        >
                            <ArrowUp className="size-5" />
                        </button>
                    ) : (
                        voiceSupported() && (
                            <button
                                type="button"
                                onClick={() => (voice.listening ? voice.stop() : voice.start())}
                                aria-pressed={voice.listening}
                                aria-label={t('tasks.dictate')}
                                className={`flex size-10 shrink-0 items-center justify-center rounded-full ${voice.listening ? 'bg-accent-strong text-accent-strong-foreground' : 'bg-primary text-accent-strong'}`}
                            >
                                <Mic className="size-5" />
                            </button>
                        )
                    )}
                </div>
            </div>

            {editing && (
                <EditTaskModal
                    task={editing}
                    members={members}
                    taskUrl={taskUrl}
                    onClose={() => setEditing(null)}
                    onDelete={() => {
                        removed.set(editing.id, true);
                        void save('delete', taskUrl(editing.id)).then(() => removed.clear(editing.id));
                    }}
                />
            )}
        </>
    );
}

// "Overdue" (red) / "Today" / "Tomorrow" / "Sat, Oct 24": a row's due, said plainly (T2).
function dueWords(due: string, today: string, overdue: string): { text: string; tone: string } {
    const d = new Date(`${due}T00:00:00`);
    const days = Math.round((d.getTime() - new Date(`${today}T00:00:00`).getTime()) / 864e5);
    if (days < 0) return { text: overdue, tone: 'text-destructive' };
    if (days <= 1) return { text: relativeWord(days, 'day'), tone: days === 0 ? 'text-amber-700' : '' };
    return {
        text: d.toLocaleDateString(appLocale(), days < 7 ? { weekday: 'short', month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric' }),
        tone: '',
    };
}

function EditListModal({ open, onOpenChange, list }: { open: boolean; onOpenChange: (v: boolean) => void; list: List }) {
    const t = useT();
    const [name, setName] = useState(list.name);
    const [color, setColor] = useState(list.color);
    const [icon, setIcon] = useState(list.icon);

    const save = () => {
        router.patch(
            route('tasks.lists.update', list.id),
            { name: name.trim() || list.name, color, icon },
            { preserveScroll: true, onSuccess: () => onOpenChange(false) },
        );
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('tasks.edit_list')}
            footer={
                <Button className="w-full" onClick={save} disabled={!name.trim()}>
                    {t('common.save')}
                </Button>
            }
        >
            <div className="flex flex-col gap-5">
                <div className="flex items-center gap-3">
                    <span className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${listColor(color)}`}>
                        {(() => {
                            const Icon = listIcon(icon);
                            return <Icon className="size-6" />;
                        })()}
                    </span>
                    <Input
                        autoFocus
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder={t('tasks.list_name_placeholder')}
                        className="text-base"
                    />
                </div>
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('tasks.color_icon')}</Label>
                    <div className="flex flex-wrap gap-2">
                        {Object.keys(LIST_COLORS).map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => setColor(c)}
                                aria-label={t('tasks.color_aria', { color: c })}
                                className={`flex size-9 items-center justify-center rounded-full ${listColor(c)} ${color === c ? 'ring-primary ring-2 ring-offset-2' : ''}`}
                            >
                                {color === c && <Check className="size-4" />}
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
                                    onClick={() => setIcon(ic)}
                                    aria-label={t('tasks.icon_aria', { icon: ic })}
                                    className={`flex size-9 items-center justify-center rounded-lg border transition-colors ${
                                        icon === ic ? 'border-primary bg-accent-subtle text-primary' : 'border-border text-muted-foreground'
                                    }`}
                                >
                                    <Icon className="size-4.5" />
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>
        </ResponsiveModal>
    );
}
