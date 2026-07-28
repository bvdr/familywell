import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Archive,
    Ban,
    Calendar,
    Camera,
    Check,
    ChevronRight,
    Copy,
    Image as ImageIcon,
    ListChecks,
    Pencil,
    Plus,
    Search,
    ShoppingCart,
    Trash2,
    Unlink,
    UserPlus,
    X,
    type LucideIcon,
} from 'lucide-react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { TaskTree, type TaskNode } from '@/components/tasks/task-tree';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import AppLayout from '@/layouts/app-layout';
import { compressImage } from '@/lib/compress-image';
import { avatarColor, DEP_STATUS_KEYS, depStatus, PROJECT_STATUS_KEYS, projectStatus, shortDate } from '@/lib/projects';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
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
    coverUrl: string | null;
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
    tasks: TaskNode[];
    dependencies: Dep[];
    lists: LinkedList[];
    share: ShareState;
}) {
    const [sharing, setSharing] = useState(false);
    const [editing, setEditing] = useState(false);
    const [linking, setLinking] = useState(false);
    const [depName, setDepName] = useState('');
    const coverInput = useRef<HTMLInputElement>(null);
    const uploadCover = async (file: File) => {
        const img = await compressImage(file, 1_200_000);
        router.post(route('projects.cover.upload', project.id), { photo: img }, { forceFormData: true, preserveScroll: true });
    };

    // Task progress counts top-level tasks + their sub-tasks (header summary only; live toggles are
    // handled optimistically inside <TaskTree>).
    const taskTotal = tasks.reduce((n, t) => n + 1 + t.subtasks.length, 0);
    const taskDone = tasks.reduce((n, t) => n + (t.done ? 1 : 0) + t.subtasks.filter((s) => s.done).length, 0);
    const taskPct = taskTotal ? Math.round((taskDone / taskTotal) * 100) : 0;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Projects', href: '/m/projects' },
        { title: project.name, href: route('projects.show', project.id) },
    ];
    const st = projectStatus(project.status);

    const patch = (data: Record<string, string | number | boolean | null>) =>
        router.patch(route('projects.update', project.id), data, { preserveScroll: true });

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
                {/* Hero: a cover photo (tap to add/change), with the status pill and title over a gradient. */}
                <div className="bg-accent-subtle relative h-56 overflow-hidden rounded-2xl">
                    {project.coverUrl ? (
                        <img src={project.coverUrl} alt="" className="absolute inset-0 h-full w-full object-cover" />
                    ) : (
                        <button
                            type="button"
                            onClick={() => coverInput.current?.click()}
                            className="text-primary/45 hover:text-primary/60 absolute inset-0 flex flex-col items-center justify-center gap-1.5 pb-14 transition-colors"
                        >
                            <ImageIcon className="size-7" />
                            <span className="text-sm font-medium">Add a project photo</span>
                        </button>
                    )}
                    <div className="absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-[#142218] via-[#142218]/60 to-transparent" />
                    <span
                        className={`absolute top-4 left-4 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${st.hero}`}
                    >
                        <span className="size-1.5 rounded-full bg-current opacity-60" /> {st.label}
                    </span>
                    {project.coverUrl && (
                        <button
                            type="button"
                            onClick={() => coverInput.current?.click()}
                            aria-label="Change photo"
                            className="absolute top-4 right-4 flex size-9 items-center justify-center rounded-full bg-black/30 text-white backdrop-blur-sm transition-colors hover:bg-black/45"
                        >
                            <Camera className="size-4.5" />
                        </button>
                    )}
                    <div className="pointer-events-none absolute inset-x-0 bottom-0 p-4">
                        <div className="text-[11px] font-semibold tracking-widest text-white/70 uppercase">Family project</div>
                        <h1 className="font-heading mt-1 text-3xl leading-tight text-white">{project.name}</h1>
                    </div>
                    <input
                        ref={coverInput}
                        type="file"
                        accept="image/*"
                        className="hidden"
                        onChange={(e) => {
                            const f = e.target.files?.[0];
                            if (f) uploadCover(f);
                            e.target.value = '';
                        }}
                    />
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
                    sub={taskTotal ? `${taskDone} of ${taskTotal} done` : undefined}
                    pct={taskTotal ? taskPct : undefined}
                >
                    {taskTotal > 0 && (
                        <div className="bg-surface mb-2 h-1.5 overflow-hidden rounded-full">
                            <div className="bg-primary h-full rounded-full" style={{ width: `${taskPct}%` }} />
                        </div>
                    )}
                    <TaskTree
                        tasks={tasks}
                        members={members}
                        canEdit
                        addUrl={route('projects.tasks.store', project.id)}
                        taskUrl={(id) => route('projects.tasks.update', id)}
                        reorderUrl={route('projects.tasks.reorder', project.id)}
                        emptyHint="No tasks yet. Add the first one below."
                    />
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
            <LinkListModal open={linking} onOpenChange={setLinking} projectId={project.id} projectName={project.name} />
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
                <div className="grid gap-2">
                    <Label>Target start</Label>
                    {/* Wrap the native date input so it can't grow past its box (iOS date inputs won't shrink). */}
                    <div className="border-border flex h-9 w-full items-center gap-2 rounded-lg border px-3">
                        <Calendar className="text-muted-foreground size-4 shrink-0" />
                        <input
                            type="date"
                            value={data.target_start}
                            onChange={(e) => setData('target_start', e.target.value)}
                            className="text-primary w-full min-w-0 bg-transparent text-sm outline-none"
                        />
                    </div>
                </div>
                <div className="grid gap-2">
                    <Label>Owner</Label>
                    <select
                        value={data.owner_id ?? ''}
                        onChange={(e) => setData('owner_id', e.target.value ? Number(e.target.value) : null)}
                        className="border-border text-primary h-9 w-full rounded-lg border bg-transparent px-3 text-sm outline-none"
                    >
                        <option value="">Unassigned</option>
                        {members.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.name}
                            </option>
                        ))}
                    </select>
                </div>
            </form>
        </ResponsiveModal>
    );
}

type Linkable = { id: number; name: string; store: string | null; count: number; sharedWith: string };
type NewItem = { name: string; quantity: string };

function LinkListModal({
    open,
    onOpenChange,
    projectId,
    projectName,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    projectId: number;
    projectName: string;
}) {
    const [view, setView] = useState<'pick' | 'new'>('pick');
    const [lists, setLists] = useState<Linkable[]>([]);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [q, setQ] = useState('');
    const [newName, setNewName] = useState('');
    const [items, setItems] = useState<NewItem[]>([{ name: '', quantity: '' }]);

    useEffect(() => {
        if (!open) return;
        setView('pick');
        setSelected(new Set());
        setQ('');
        setNewName('');
        setItems([{ name: '', quantity: '' }]);
        fetch(route('projects.lists.linkable', projectId), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { lists: [] }))
            .then((d) => setLists(d.lists));
    }, [open, projectId]);

    const filtered = lists.filter((l) => l.name.toLowerCase().includes(q.toLowerCase()));
    const toggle = (id: number) =>
        setSelected((s) => {
            const n = new Set(s);
            if (n.has(id)) n.delete(id);
            else n.add(id);
            return n;
        });
    const addSelected = () => {
        if (!selected.size) return;
        router.post(
            route('projects.lists.link', projectId),
            { list_ids: [...selected] },
            { preserveScroll: true, onSuccess: () => onOpenChange(false) },
        );
    };
    const createList = () => {
        if (!newName.trim()) return;
        const payload = {
            name: newName.trim(),
            items: items.filter((i) => i.name.trim()).map((i) => ({ name: i.name.trim(), quantity: i.quantity.trim() || null })),
        };
        router.post(route('projects.lists.create', projectId), payload, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={view === 'pick' ? 'Shopping list' : 'New shopping list'}
            description={view === 'pick' ? `Add to ${projectName}` : undefined}
            onBack={view === 'new' ? () => setView('pick') : undefined}
            footer={
                view === 'pick' ? (
                    <Button className="w-full" disabled={!selected.size} onClick={addSelected}>
                        {selected.size ? `Add ${selected.size} list${selected.size > 1 ? 's' : ''}` : 'Select a list to add'}
                    </Button>
                ) : (
                    <Button className="w-full" disabled={!newName.trim()} onClick={createList}>
                        Create &amp; add to project
                    </Button>
                )
            }
        >
            {view === 'pick' ? (
                <div className="flex flex-col gap-3">
                    <button
                        type="button"
                        onClick={() => setView('new')}
                        className="border-primary/40 bg-accent-subtle flex items-center gap-3 rounded-xl border border-dashed p-3 text-left"
                    >
                        <span className="bg-primary text-primary-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                            <Plus className="size-5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="text-primary text-sm font-semibold">Create a new list</div>
                            <div className="text-muted-foreground text-xs">Starts empty, lives inside this project</div>
                        </div>
                        <ChevronRight className="text-primary size-4" />
                    </button>

                    <div className="text-text-muted text-[11px] font-semibold tracking-widest uppercase">Or link a list you already have</div>
                    <div className="bg-surface flex items-center gap-2 rounded-lg px-3 py-2">
                        <Search className="text-muted-foreground size-4" />
                        <input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search your lists..."
                            // text-base (16px) on mobile so iOS Safari doesn't auto-zoom on focus; text-sm from md up.
                            className="text-primary w-full bg-transparent text-base outline-none md:text-sm"
                        />
                    </div>
                    <div className="flex flex-col gap-2">
                        {filtered.length === 0 && <p className="text-muted-foreground py-4 text-center text-sm">No lists to link.</p>}
                        {filtered.map((l) => {
                            const on = selected.has(l.id);
                            return (
                                <button
                                    key={l.id}
                                    type="button"
                                    onClick={() => toggle(l.id)}
                                    className={`flex items-center gap-3 rounded-xl border p-3 text-left transition-colors ${on ? 'border-primary bg-accent-subtle' : 'bg-surface-card'}`}
                                >
                                    <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                                        <ShoppingCart className="size-4.5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-primary text-sm font-medium">{l.name}</div>
                                        <div className="text-muted-foreground text-xs">
                                            {l.count} item{l.count === 1 ? '' : 's'} · {l.sharedWith}
                                        </div>
                                    </div>
                                    <span
                                        className={`flex size-6 shrink-0 items-center justify-center rounded-md border ${on ? 'border-primary bg-primary text-primary-foreground' : 'border-border'}`}
                                    >
                                        {on && <Check className="size-4" />}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-muted-foreground text-xs leading-relaxed">
                        Linked lists stay in your Lists tab too, the project just points at them.
                    </p>
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    {/* Back lives in the sheet header (above this title); here we render the visible title + subtitle. */}
                    <div>
                        <h2 className="font-heading text-primary text-2xl">New shopping list</h2>
                        <p className="text-muted-foreground text-sm">It will be added to {projectName}</p>
                    </div>
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">List name</Label>
                        <Input autoFocus value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="Patio materials" />
                    </div>
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Start it off with</Label>
                        <div className="flex flex-col gap-2">
                            {items.map((it, i) => (
                                <div key={i} className="flex items-center gap-2">
                                    <Input
                                        value={it.name}
                                        onChange={(e) => setItems((a) => a.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))}
                                        placeholder="Item"
                                        className="flex-1"
                                    />
                                    <Input
                                        value={it.quantity}
                                        onChange={(e) => setItems((a) => a.map((x, j) => (j === i ? { ...x, quantity: e.target.value } : x)))}
                                        placeholder="Qty"
                                        className="w-20 shrink-0"
                                    />
                                    {items.length > 1 && (
                                        <button
                                            type="button"
                                            onClick={() => setItems((a) => a.filter((_, j) => j !== i))}
                                            aria-label="Remove item"
                                            className="text-muted-foreground hover:text-destructive flex size-8 shrink-0 items-center justify-center"
                                        >
                                            <X className="size-4" />
                                        </button>
                                    )}
                                </div>
                            ))}
                            <button
                                type="button"
                                onClick={() => setItems((a) => [...a, { name: '', quantity: '' }])}
                                className="border-border text-muted-foreground hover:text-primary flex items-center justify-center gap-2 rounded-lg border border-dashed py-2.5 text-sm transition-colors"
                            >
                                <Plus className="size-4" /> Add another item
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ResponsiveModal>
    );
}
