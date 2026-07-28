import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Archive,
    Ban,
    Check,
    ChevronRight,
    Copy,
    Image as ImageIcon,
    ListChecks,
    type LucideIcon,
    Pencil,
    Plus,
    ShoppingCart,
    Trash2,
    Unlink,
    UserPlus,
    X,
} from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';

import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { avatarColor, DEP_STATUS_KEYS, depStatus, PROJECT_STATUS_KEYS, projectStatus, shortDate } from '@/lib/projects';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type Task = { id: number; name: string; done: boolean; assignedTo: number | null };
type Dep = { id: number; name: string; note: string | null; status: string };
type LinkedList = { id: number; name: string; store: string | null; bought: number; total: number };
type Project = {
    id: number;
    name: string;
    description: string | null;
    status: string;
    progress: number;
    color: string;
    ownerId: number | null;
    ownerName: string | null;
    targetStart: string | null;
    waitingOn: string | null;
    archived: boolean;
    visibility: string;
    updated: string | null;
};

export default function ProjectDetail({
    project,
    members,
    tasks,
    dependencies,
    lists,
    share,
}: {
    project: Project;
    members: Member[];
    tasks: Task[];
    dependencies: Dep[];
    lists: LinkedList[];
    share: ShareState;
}) {
    const memberById = useMemo(() => new Map(members.map((m) => [m.id, m])), [members]);
    const [sharing, setSharing] = useState(false);
    const [editing, setEditing] = useState(false);
    const [linking, setLinking] = useState(false);
    const [taskName, setTaskName] = useState('');
    const [depName, setDepName] = useState('');

    const done = useOptimistic<boolean>();
    const isDone = (t: Task) => done.get(t.id, t.done);
    const taskDone = tasks.filter(isDone).length;
    const taskPct = tasks.length ? Math.round((taskDone / tasks.length) * 100) : 0;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Projects', href: '/m/projects' },
        { title: project.name, href: route('projects.show', project.id) },
    ];
    const st = projectStatus(project.status);

    const patch = (data: Record<string, string | number | boolean | null>) =>
        router.patch(route('projects.update', project.id), data, { preserveScroll: true });

    const toggleTask = (t: Task) => {
        done.set(t.id, !isDone(t));
        router.patch(
            route('projects.tasks.update', t.id),
            {},
            { preserveScroll: true, preserveState: true, onSuccess: () => done.clear(t.id), onError: () => done.clear(t.id) },
        );
    };
    const addTask = () => {
        if (!taskName.trim()) return;
        router.post(route('projects.tasks.store', project.id), { name: taskName.trim() }, { preserveScroll: true, onSuccess: () => setTaskName('') });
    };
    const cycleDep = (d: Dep) => {
        const next = DEP_STATUS_KEYS[(DEP_STATUS_KEYS.indexOf(d.status) + 1) % DEP_STATUS_KEYS.length];
        router.patch(route('projects.deps.update', d.id), { status: next }, { preserveScroll: true });
    };
    const addDep = () => {
        if (!depName.trim()) return;
        router.post(route('projects.deps.store', project.id), { name: depName.trim() }, { preserveScroll: true, onSuccess: () => setDepName('') });
    };

    const headerActions = (
        <>
            <button
                type="button"
                onClick={() => setSharing(true)}
                aria-label="Share project"
                className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
            >
                <UserPlus className="size-4.5" />
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label="Project options"
                        className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
                    >
                        <span className="text-lg leading-none">⋯</span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem onSelect={() => setEditing(true)}>
                        <Pencil className="size-4" /> Edit project
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => router.post(route('projects.duplicate', project.id))}>
                        <Copy className="size-4" /> Duplicate
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => patch({ archived: !project.archived })}>
                        <Archive className="size-4" /> {project.archived ? 'Unarchive' : 'Archive'}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        className="text-destructive focus:text-destructive"
                        onSelect={() => {
                            if (confirm('Delete this project and everything in it?')) router.delete(route('projects.destroy', project.id));
                        }}
                    >
                        <Trash2 className="size-4" /> Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs} actions={headerActions}>
            <Head title={project.name} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-5 p-4">
                {/* Hero: a cover-photo band (photo upload deferred — shows the placeholder for now). */}
                <div className="bg-accent-subtle relative h-56 overflow-hidden rounded-2xl">
                    <div className="text-primary/45 absolute inset-0 flex flex-col items-center justify-center gap-1.5 pb-14">
                        <ImageIcon className="size-7" />
                        <span className="text-sm font-medium">Add a project photo</span>
                    </div>
                    <div className="absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-[#142218] via-[#142218]/60 to-transparent" />
                    <span
                        className={`absolute top-4 left-4 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${st.hero}`}
                    >
                        <span className="size-1.5 rounded-full bg-current opacity-60" /> {st.label}
                    </span>
                    <div className="absolute inset-x-0 bottom-0 p-4">
                        <div className="text-[11px] font-semibold tracking-widest text-white/70 uppercase">Family project</div>
                        <h1 className="font-heading mt-1 text-3xl leading-tight text-white">{project.name}</h1>
                    </div>
                </div>

                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                        <div className="flex -space-x-1.5">
                            {members.slice(0, 3).map((m) => {
                                const [bg, fg] = avatarColor(m.name);
                                return (
                                    <span
                                        key={m.id}
                                        title={m.name}
                                        style={{ background: bg, color: fg }}
                                        className="ring-background flex size-6 items-center justify-center rounded-full text-[10px] font-semibold ring-2"
                                    >
                                        {m.name.charAt(0).toUpperCase()}
                                    </span>
                                );
                            })}
                        </div>
                        <span className="text-muted-foreground text-sm">Shared with family</span>
                    </div>
                    {project.updated && <span className="text-text-muted font-mono text-xs">Updated {project.updated}</span>}
                </div>

                {project.description && <p className="text-text-secondary text-sm leading-relaxed">{project.description}</p>}

                {/* Overall progress */}
                <div className="bg-surface-card rounded-2xl border p-4">
                    <div className="flex items-baseline justify-between">
                        <span className="text-muted-foreground text-sm font-medium">Overall progress</span>
                        <span className="text-primary font-mono text-sm font-semibold">{project.progress}%</span>
                    </div>
                    <div className="bg-surface mt-2.5 h-2 overflow-hidden rounded-full">
                        <div className="bg-primary h-full rounded-full transition-all" style={{ width: `${project.progress}%` }} />
                    </div>
                    <div className="mt-3 flex items-center gap-4">
                        <div className="flex flex-col">
                            <span className="text-primary font-mono text-base font-semibold">{shortDate(project.targetStart)}</span>
                            <span className="text-text-muted text-[11px]">Target start</span>
                        </div>
                        <div className="bg-border h-6 w-px" />
                        <div className="flex flex-col">
                            <span className="text-primary font-mono text-base font-semibold">{project.ownerName ?? '—'}</span>
                            <span className="text-text-muted text-[11px]">Owner</span>
                        </div>
                    </div>
                </div>

                {/* Blocked by */}
                <Section icon={Ban} tone="red" title="Blocked by" count={dependencies.length}>
                    <div className="flex flex-col gap-2">
                        {dependencies.map((d) => {
                            const ds = depStatus(d.status);
                            return (
                                <div key={d.id} className="bg-surface-card flex items-center gap-3 rounded-xl border p-3">
                                    <span className={`size-2 shrink-0 rounded-full ${ds.dot}`} />
                                    <div className="min-w-0 flex-1">
                                        <div className="text-primary text-sm font-medium">{d.name}</div>
                                        {d.note && <div className="text-muted-foreground text-xs">{d.note}</div>}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => cycleDep(d)}
                                        className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ${ds.chip}`}
                                    >
                                        {ds.label}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => router.delete(route('projects.deps.destroy', d.id), { preserveScroll: true })}
                                        aria-label="Remove"
                                        className="text-muted-foreground hover:text-destructive flex size-6 items-center justify-center"
                                    >
                                        <X className="size-4" />
                                    </button>
                                </div>
                            );
                        })}
                        <InlineAdd value={depName} onChange={setDepName} onAdd={addDep} placeholder="Add a blocker..." />
                    </div>
                </Section>

                {/* Tasks */}
                <Section
                    icon={ListChecks}
                    title="Tasks"
                    sub={tasks.length ? `${taskDone} of ${tasks.length} done` : undefined}
                    pct={tasks.length ? taskPct : undefined}
                >
                    {tasks.length > 0 && (
                        <div className="bg-surface mb-2 h-1.5 overflow-hidden rounded-full">
                            <div className="bg-primary h-full rounded-full" style={{ width: `${taskPct}%` }} />
                        </div>
                    )}
                    <div className="flex flex-col gap-2">
                        {tasks.map((t) => (
                            <div key={t.id} className="bg-surface-card group flex items-center gap-3 rounded-xl border p-3">
                                <button
                                    type="button"
                                    onClick={() => toggleTask(t)}
                                    aria-label={isDone(t) ? 'Mark not done' : 'Mark done'}
                                    className={`flex size-6 shrink-0 items-center justify-center rounded-md border transition-colors ${
                                        isDone(t) ? 'border-primary bg-primary text-primary-foreground' : 'border-border'
                                    }`}
                                >
                                    {isDone(t) && <Check className="size-4" />}
                                </button>
                                <div className="min-w-0 flex-1">
                                    <div className={`text-sm font-medium ${isDone(t) ? 'text-muted-foreground line-through' : 'text-primary'}`}>
                                        {t.name}
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {t.assignedTo ? (memberById.get(t.assignedTo)?.name ?? '?') : 'Unassigned'}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => router.delete(route('projects.tasks.destroy', t.id), { preserveScroll: true })}
                                    aria-label="Remove task"
                                    className="text-muted-foreground hover:text-destructive flex size-6 items-center justify-center opacity-0 transition-opacity group-hover:opacity-100"
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                        ))}
                        <InlineAdd value={taskName} onChange={setTaskName} onAdd={addTask} placeholder="Add a task..." />
                    </div>
                </Section>

                {/* Materials — linked shopping lists */}
                <Section icon={ShoppingCart} title="Shopping lists" count={lists.length}>
                    <div className="flex flex-col gap-2">
                        {lists.map((l) => (
                            <div key={l.id} className="bg-surface-card flex items-center gap-3 rounded-xl border p-3">
                                <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <ShoppingCart className="size-4.5" />
                                </span>
                                <Link href={`/m/shopping/lists/${l.id}`} className="min-w-0 flex-1">
                                    <div className="text-primary text-sm font-medium">{l.name}</div>
                                    <div className="text-muted-foreground text-xs">
                                        {l.store ? `${l.store} · ` : ''}
                                        {l.bought} of {l.total} bought
                                    </div>
                                </Link>
                                <button
                                    type="button"
                                    onClick={() => router.delete(route('projects.lists.unlink', [project.id, l.id]), { preserveScroll: true })}
                                    aria-label="Unlink list"
                                    className="text-muted-foreground hover:text-destructive flex size-6 items-center justify-center"
                                >
                                    <Unlink className="size-4" />
                                </button>
                            </div>
                        ))}
                        <button
                            type="button"
                            onClick={() => setLinking(true)}
                            className="border-accent text-primary hover:bg-surface flex items-center justify-center gap-2 rounded-xl border border-dashed py-3 text-sm font-medium transition-colors"
                        >
                            <Plus className="size-4" /> Link a shopping list
                        </button>
                    </div>
                </Section>
            </div>

            {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={project.name} />}
            <EditModal open={editing} onOpenChange={setEditing} project={project} members={members} />
            <LinkListModal open={linking} onOpenChange={setLinking} projectId={project.id} />
        </AppLayout>
    );
}

function Section({
    icon: Icon,
    tone = 'green',
    title,
    sub,
    count,
    pct,
    children,
}: {
    icon: LucideIcon;
    tone?: 'green' | 'red';
    title: string;
    sub?: string;
    count?: number;
    pct?: number;
    children: React.ReactNode;
}) {
    return (
        <section>
            <div className="mb-3 flex items-center gap-2.5">
                <span
                    className={`flex size-8 items-center justify-center rounded-lg ${tone === 'red' ? 'bg-red-100 text-red-600' : 'bg-accent-subtle text-primary'}`}
                >
                    <Icon className="size-4.5" />
                </span>
                <h2 className="font-heading text-primary flex-1 text-lg font-semibold">{title}</h2>
                {sub && <span className="text-muted-foreground text-xs">{sub}</span>}
                {pct != null && <span className="text-primary font-mono text-sm font-semibold">{pct}%</span>}
                {count != null && sub == null && pct == null && <span className="text-text-muted font-mono text-xs">{count}</span>}
            </div>
            {children}
        </section>
    );
}

function InlineAdd({
    value,
    onChange,
    onAdd,
    placeholder,
}: {
    value: string;
    onChange: (v: string) => void;
    onAdd: () => void;
    placeholder: string;
}) {
    return (
        <div className="flex items-center gap-2">
            <Input
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && onAdd()}
                placeholder={placeholder}
            />
            <Button type="button" size="icon" onClick={onAdd} disabled={!value.trim()} aria-label="Add">
                <Plus className="size-4" />
            </Button>
        </div>
    );
}

function EditModal({
    open,
    onOpenChange,
    project,
    members,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    project: Project;
    members: Member[];
}) {
    const { data, setData, patch, processing } = useForm({
        name: project.name,
        description: project.description ?? '',
        status: project.status,
        progress: project.progress,
        owner_id: project.ownerId,
        target_start: project.targetStart ?? '',
        waiting_on: project.waitingOn ?? '',
    });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('projects.update', project.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };
    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title="Edit project"
            footer={
                <Button type="submit" form="edit-project" disabled={processing} className="w-full">
                    Save
                </Button>
            }
        >
            <form id="edit-project" onSubmit={submit} className="flex flex-col gap-5">
                <div className="grid gap-2">
                    <Label>Name</Label>
                    <Input value={data.name} onChange={(e) => setData('name', e.target.value)} />
                </div>
                <div className="grid gap-2">
                    <Label>Description</Label>
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        className="border-border focus:border-accent min-h-20 w-full resize-none rounded-lg border bg-transparent p-3 text-base outline-none md:text-sm"
                    />
                </div>
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Status</Label>
                    <div className="bg-surface grid grid-cols-4 gap-1 rounded-lg border p-1">
                        {PROJECT_STATUS_KEYS.map((s) => (
                            <button
                                key={s}
                                type="button"
                                onClick={() => setData('status', s)}
                                className={`rounded-md py-1.5 text-xs font-medium transition-colors ${data.status === s ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'}`}
                            >
                                {projectStatus(s).label}
                            </button>
                        ))}
                    </div>
                </div>
                {data.status === 'blocked' && (
                    <div className="grid gap-2">
                        <Label>Waiting on</Label>
                        <Input value={data.waiting_on} onChange={(e) => setData('waiting_on', e.target.value)} placeholder="Contractor quote #218" />
                    </div>
                )}
                <div className="grid gap-2">
                    <div className="flex items-baseline justify-between">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Progress</Label>
                        <span className="text-primary font-mono text-sm font-semibold">{data.progress}%</span>
                    </div>
                    <input
                        type="range"
                        min={0}
                        max={100}
                        step={5}
                        value={data.progress}
                        onChange={(e) => setData('progress', Number(e.target.value))}
                        className="accent-primary w-full"
                    />
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div className="grid gap-2">
                        <Label>Target start</Label>
                        <Input type="date" value={data.target_start} onChange={(e) => setData('target_start', e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Owner</Label>
                        <select
                            value={data.owner_id ?? ''}
                            onChange={(e) => setData('owner_id', e.target.value ? Number(e.target.value) : null)}
                            className="border-border h-9 rounded-lg border bg-transparent px-2 text-sm outline-none"
                        >
                            <option value="">Unassigned</option>
                            {members.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            </form>
        </ResponsiveModal>
    );
}

function LinkListModal({ open, onOpenChange, projectId }: { open: boolean; onOpenChange: (v: boolean) => void; projectId: number }) {
    const [lists, setLists] = useState<{ id: number; name: string; store: string | null }[]>([]);
    useEffect(() => {
        if (!open) return;
        fetch(route('projects.lists.linkable', projectId), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { lists: [] }))
            .then((d) => setLists(d.lists));
    }, [open, projectId]);

    const link = (id: number) =>
        router.post(route('projects.lists.link', projectId), { list_id: id }, { preserveScroll: true, onSuccess: () => onOpenChange(false) });

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title="Link a shopping list"
            description="Point this project at a list you already have"
        >
            <div className="flex flex-col gap-2">
                {lists.length === 0 && <p className="text-muted-foreground py-4 text-center text-sm">No lists to link.</p>}
                {lists.map((l) => (
                    <button
                        key={l.id}
                        type="button"
                        onClick={() => link(l.id)}
                        className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-xl border p-3 text-left transition-colors"
                    >
                        <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                            <ShoppingCart className="size-4.5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="text-primary text-sm font-medium">{l.name}</div>
                            {l.store && <div className="text-muted-foreground text-xs">{l.store}</div>}
                        </div>
                        <ChevronRight className="text-muted-foreground size-4" />
                    </button>
                ))}
            </div>
        </ResponsiveModal>
    );
}
