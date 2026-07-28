import { router } from '@inertiajs/react';
import { Calendar, Check, ChevronDown, MoreVertical, Plus, Trash2, UserCircle2 } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import { avatarColor } from '@/lib/projects';
import { dueLabel } from '@/lib/tasks';

export type TaskMember = { id: number; name: string };
export type SubTask = { id: number; title: string; note: string | null; done: boolean; dueDate: string | null; assignedTo: number | null };
export type TaskNode = SubTask & { subtasks: SubTask[] };

// Shared task list UI, used both by the tasks module (list detail) and the project detail's unified
// Tasks section. The two contexts differ only in their endpoints, passed in as `addUrl` (POST a new
// task, body {title, parent_id?}) and `taskUrl(id)` (PATCH to edit/toggle, DELETE to remove — same
// path). All toggles are optimistic so a tap feels instant.
type Props = {
    tasks: TaskNode[];
    members: TaskMember[];
    canEdit: boolean;
    addUrl: string;
    taskUrl: (id: number) => string;
    emptyHint?: string;
};

function memberName(members: TaskMember[], id: number | null) {
    return members.find((m) => m.id === id)?.name ?? null;
}

// Small round initial for an assignee.
function AssigneeDot({ members, id }: { members: TaskMember[]; id: number | null }) {
    const name = memberName(members, id);
    if (!name) return null;
    const [bg, fg] = avatarColor(name);
    return (
        <span title={name} className={`flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold ${bg} ${fg}`}>
            {name.charAt(0).toUpperCase()}
        </span>
    );
}

export function TaskTree({ tasks, members, canEdit, addUrl, taskUrl, emptyHint }: Props) {
    const doneOpt = useOptimistic<boolean>();
    const [expanded, setExpanded] = useState<Set<number>>(new Set());
    const [newTitle, setNewTitle] = useState('');
    const [subFor, setSubFor] = useState<number | null>(null); // which task's add-subtask input is open
    const [subTitle, setSubTitle] = useState('');
    const [editing, setEditing] = useState<SubTask | null>(null);

    const isDone = (t: SubTask) => doneOpt.get(t.id, t.done);
    const toggle = (t: SubTask) => {
        if (!canEdit) return;
        doneOpt.set(t.id, !isDone(t));
        router.patch(
            taskUrl(t.id),
            {},
            { preserveScroll: true, preserveState: true, onSuccess: () => doneOpt.clear(t.id), onError: () => doneOpt.clear(t.id) },
        );
    };

    const addTask = () => {
        if (!newTitle.trim()) return;
        router.post(addUrl, { title: newTitle.trim() }, { preserveScroll: true, onSuccess: () => setNewTitle('') });
    };
    const addSub = (parentId: number) => {
        if (!subTitle.trim()) return;
        router.post(
            addUrl,
            { title: subTitle.trim(), parent_id: parentId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSubTitle('');
                    setExpanded((s) => new Set(s).add(parentId));
                },
            },
        );
    };
    const toggleExpand = (id: number) =>
        setExpanded((s) => {
            const next = new Set(s);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });

    const Row = ({ t, sub }: { t: SubTask; sub?: boolean }) => {
        const due = dueLabel(t.dueDate);
        return (
            <div className={`flex items-center gap-3 ${sub ? 'py-1.5' : 'py-2'}`}>
                <button
                    type="button"
                    onClick={() => toggle(t)}
                    aria-label={isDone(t) ? 'Mark not done' : 'Mark done'}
                    className={`flex ${sub ? 'size-5' : 'size-6'} shrink-0 items-center justify-center rounded-full border transition-colors ${
                        isDone(t) ? 'border-primary bg-primary text-primary-foreground' : 'border-border'
                    }`}
                >
                    {isDone(t) && <Check className={sub ? 'size-3' : 'size-3.5'} />}
                </button>
                <div className="min-w-0 flex-1">
                    <div
                        className={`truncate ${sub ? 'text-sm' : 'text-[15px]'} ${isDone(t) ? 'text-muted-foreground line-through' : 'text-primary'}`}
                    >
                        {t.title}
                    </div>
                    {t.note && <div className="text-muted-foreground truncate text-xs">{t.note}</div>}
                </div>
                {due && <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${due.tone}`}>{due.label}</span>}
                <AssigneeDot members={members} id={t.assignedTo} />
                {canEdit && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                aria-label="Task actions"
                                className="text-muted-foreground hover:text-primary flex size-7 shrink-0 items-center justify-center rounded-lg"
                            >
                                <MoreVertical className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                            <DropdownMenuItem onSelect={() => setEditing(t)}>Edit</DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                className="text-destructive focus:text-destructive"
                                onSelect={() => router.delete(taskUrl(t.id), { preserveScroll: true })}
                            >
                                <Trash2 className="size-4" /> Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        );
    };

    return (
        <div className="flex flex-col">
            {tasks.length === 0 && <p className="text-muted-foreground py-3 text-sm">{emptyHint ?? 'No tasks yet.'}</p>}

            {tasks.map((t) => {
                const subs = t.subtasks ?? [];
                const doneSubs = subs.filter((s) => isDone(s)).length;
                const isOpen = expanded.has(t.id);
                return (
                    <div key={t.id} className="border-border/60 border-b last:border-b-0">
                        <div className="flex items-center gap-1">
                            <div className="min-w-0 flex-1">
                                <Row t={t} />
                            </div>
                            {subs.length > 0 && (
                                <button
                                    type="button"
                                    onClick={() => toggleExpand(t.id)}
                                    aria-label={isOpen ? 'Collapse sub-tasks' : 'Expand sub-tasks'}
                                    className="text-muted-foreground flex shrink-0 items-center gap-1 rounded-lg px-1.5 py-1 text-xs"
                                >
                                    {doneSubs}/{subs.length}
                                    <ChevronDown className={`size-4 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                                </button>
                            )}
                        </div>

                        {(isOpen || subFor === t.id) && (
                            <div className="border-border/60 mb-1 ml-4 flex flex-col border-l pl-4">
                                {isOpen && subs.map((s) => <Row key={s.id} t={s} sub />)}
                                {canEdit && subFor === t.id && (
                                    <div className="flex items-center gap-2 py-1.5">
                                        <Input
                                            autoFocus
                                            value={subTitle}
                                            onChange={(e) => setSubTitle(e.target.value)}
                                            onKeyDown={(e) => e.key === 'Enter' && addSub(t.id)}
                                            placeholder="Sub-task"
                                            className="h-8 text-base md:text-sm"
                                        />
                                        <Button
                                            size="icon"
                                            className="size-8 shrink-0 rounded-lg"
                                            onClick={() => addSub(t.id)}
                                            aria-label="Add sub-task"
                                        >
                                            <Plus className="size-4" />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}

                        {canEdit && (
                            <button
                                type="button"
                                onClick={() => {
                                    setSubFor(subFor === t.id ? null : t.id);
                                    setSubTitle('');
                                    if (subs.length) setExpanded((s) => new Set(s).add(t.id));
                                }}
                                className="text-muted-foreground hover:text-primary mb-1.5 ml-4 flex items-center gap-1 pl-4 text-xs"
                            >
                                <Plus className="size-3.5" /> Add sub-task
                            </button>
                        )}
                    </div>
                );
            })}

            {canEdit && (
                <div className="mt-3 flex items-center gap-2">
                    <Input
                        value={newTitle}
                        onChange={(e) => setNewTitle(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && addTask()}
                        placeholder="Add a task..."
                        className="text-base md:text-sm"
                    />
                    <Button size="icon" className="shrink-0 rounded-lg" onClick={addTask} aria-label="Add task">
                        <Plus className="size-5" />
                    </Button>
                </div>
            )}

            {editing && <EditTaskModal task={editing} members={members} taskUrl={taskUrl} onClose={() => setEditing(null)} />}
        </div>
    );
}

// Edit a single task: title, note, due date, assignee. Save PATCHes the same task endpoint.
function EditTaskModal({
    task,
    members,
    taskUrl,
    onClose,
}: {
    task: SubTask;
    members: TaskMember[];
    taskUrl: (id: number) => string;
    onClose: () => void;
}) {
    const [title, setTitle] = useState(task.title);
    const [note, setNote] = useState(task.note ?? '');
    const [due, setDue] = useState(task.dueDate ?? '');
    const [assigned, setAssigned] = useState<number | null>(task.assignedTo);

    const save = () => {
        router.patch(
            taskUrl(task.id),
            { title: title.trim() || task.title, note: note.trim() || null, due_date: due || null, assigned_to: assigned },
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <ResponsiveModal
            open
            onOpenChange={(v) => !v && onClose()}
            title="Edit task"
            footer={
                <Button className="w-full" onClick={save} disabled={!title.trim()}>
                    Save
                </Button>
            }
        >
            <div className="flex flex-col gap-4">
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Title</Label>
                    <Input autoFocus value={title} onChange={(e) => setTitle(e.target.value)} className="text-base md:text-sm" />
                </div>
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Note</Label>
                    <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Optional" className="text-base md:text-sm" />
                </div>
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Due date</Label>
                    {/* Native date input in a bordered box (it won't shrink in a grid cell on its own). */}
                    <div className="border-border flex h-9 w-full items-center gap-2 rounded-lg border px-3">
                        <Calendar className="text-muted-foreground size-4 shrink-0" />
                        <input
                            type="date"
                            value={due}
                            onChange={(e) => setDue(e.target.value)}
                            className="text-primary w-full min-w-0 bg-transparent text-sm outline-none"
                        />
                        {due && (
                            <button type="button" onClick={() => setDue('')} className="text-muted-foreground hover:text-primary text-xs">
                                Clear
                            </button>
                        )}
                    </div>
                </div>
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Assign to</Label>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setAssigned(null)}
                            className={`flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition-colors ${
                                assigned === null ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-primary'
                            }`}
                        >
                            <UserCircle2 className="size-4" /> Anyone
                        </button>
                        {members.map((m) => (
                            <button
                                key={m.id}
                                type="button"
                                onClick={() => setAssigned(m.id)}
                                className={`rounded-full border px-3 py-1.5 text-sm transition-colors ${
                                    assigned === m.id ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-primary'
                                }`}
                            >
                                {m.name}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </ResponsiveModal>
    );
}
