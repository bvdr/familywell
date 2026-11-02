import { ArrowUp, Check, ChevronDown, Mic, Plus } from 'lucide-react';
import { useState } from 'react';

import { MemberAvatar } from '@/components/member-avatar';
import { TaskSheet } from '@/components/tasks/task-sheet';
import { type SubTask, type TaskMember, type TaskNode } from '@/components/tasks/task-tree';
import { useOptimistic } from '@/hooks/use-optimistic';
import { useVoice, voiceSupported } from '@/hooks/use-voice';
import { localToday } from '@/lib/date-groups';
import { appLocale, relativeWord, useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { rhythmText } from '@/lib/tasks';

// The list's rows (T2): open ones in one card (starred first, then by due date, the server's order), each with its
// due · repeat · note line, a star chip and the face of who's on it; sub-tasks under their task. Done folds into one
// row ("Done · 7  last: Mow lawn, by David") that opens in place. The add bar sits at the bottom, with the mic.
export function TaskRows({
    tasks,
    members,
    family,
    list,
    lastDone = null,
    addUrl,
    taskUrl,
    addons = true,
    canEdit = true,
    addInputRef,
}: {
    tasks: TaskNode[];
    members: TaskMember[];
    family: TaskMember[];
    // A task list's rows: the sheet can move a task to another list. An event's to-dos have none.
    list?: { id: number; name: string };
    lastDone?: { title: string; by: string | null } | null;
    addUrl: string;
    taskUrl: (id: number) => string;
    // Repeat / Important rows in the sheet: only where the task.saved add-ons run (a list's tasks, not an event's).
    addons?: boolean;
    canEdit?: boolean;
    addInputRef?: React.RefObject<HTMLInputElement | null>;
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

    const isDone = (x: SubTask) => doneOpt.get(x.id, x.done);
    const live = tasks.filter((x) => !removed.get(x.id, false));
    // A task ticked here stays in place until the next visit (no jump under the finger); done ones load folded.
    const open = live.filter((x) => !x.done);
    const done = live.filter((x) => x.done);
    const toggle = (x: SubTask) => {
        if (!canEdit) return;
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
        void save('post', addUrl, { title: v }).then((r) => {
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
            x.recurrence && x.recurrence !== 'none' && rhythmText(t, x.recurrence),
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
            {canEdit && (
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
            )}

            {editing && canEdit && (
                <TaskSheet
                    task={editing}
                    members={members}
                    family={family}
                    list={list}
                    addons={addons}
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
