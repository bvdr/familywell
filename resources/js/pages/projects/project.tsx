import { useOptimistic } from '@/hooks/use-optimistic';
import { useAddAction } from '@/lib/add-action';
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
    StickyNote,
    Trash2,
    Unlink,
    UserPlus,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { MemberAvatar } from '@/components/member-avatar';
import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { TaskTree, type TaskNode } from '@/components/tasks/task-tree';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import AppLayout from '@/layouts/app-layout';
import { compressImage, makeThumbnail } from '@/lib/compress-image';
import { useT } from '@/lib/i18n';
import { noteColor, snippet } from '@/lib/notes';
import { DEP_STATUS_KEYS, depStatus, PROJECT_STATUS_KEYS, projectStatus, shortDate } from '@/lib/projects';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type Dep = { id: number; name: string; note: string | null; status: string };
type LinkedList = { id: number; name: string; store: string | null; bought: number; total: number };
type LinkedNote = { id: number; title: string; body: string; color: string };
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
    notes,
    share,
}: {
    project: Project;
    members: Member[];
    tasks: TaskNode[];
    dependencies: Dep[];
    lists: LinkedList[];
    notes: LinkedNote[];
    share: ShareState;
}) {
    const t = useT();
    const [sharing, setSharing] = useState(false);
    const [editing, setEditing] = useState(false);
    const [linking, setLinking] = useState(false);
    const [linkView, setLinkView] = useState<'pick' | 'new'>('pick'); // which view the link-list sheet opens to
    const [linkingNote, setLinkingNote] = useState(false); // the link-note sheet
    const [adding, setAdding] = useState(false); // the "Add to project" sheet
    useAddAction(() => setAdding(true));
    // Sections stay hidden until they have content; choosing to add one reveals it (with its input).
    const [reveal, setReveal] = useState<Set<string>>(new Set());
    const [depName, setDepName] = useState('');

    const revealSection = (key: string) => {
        setAdding(false);
        setReveal((s) => new Set(s).add(key));
        setTimeout(() => document.getElementById(`section-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 60);
    };
    const openLink = (view: 'pick' | 'new') => {
        setAdding(false);
        setLinkView(view);
        setLinking(true);
    };
    const coverInput = useRef<HTMLInputElement>(null);
    const uploadCover = async (file: File) => {
        // Upload the compressed full image plus a small thumbnail, so list cards can load the thumb.
        const img = await compressImage(file, 1_200_000);
        const thumb = await makeThumbnail(file, 640);
        router.post(
            route('projects.cover.upload', project.id),
            { photo: img, photo_thumb: thumb ?? undefined },
            { forceFormData: true, preserveScroll: true },
        );
    };

    // Task progress counts top-level tasks + their sub-tasks (header summary only; live toggles are
    // handled optimistically inside <TaskTree>).
    const taskTotal = tasks.reduce((n, t) => n + 1 + t.subtasks.length, 0);
    const taskDone = tasks.reduce((n, t) => n + (t.done ? 1 : 0) + t.subtasks.filter((s) => s.done).length, 0);
    const taskPct = taskTotal ? Math.round((taskDone / taskTotal) * 100) : 0;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.projects'), href: '/m/projects' },
        { title: project.name, href: route('projects.show', project.id) },
    ];
    const st = projectStatus(project.status);

    // Optimistic overlays (see use-optimistic.ts): the tap shows its result now, the server reload
    // confirms it. Only for outcomes we can predict locally: a dep's next status, a row vanishing
    // after remove/unlink, the archive label flipping. Creates stay server-driven (they need ids).
    const depStatusOpt = useOptimistic<string>();
    const removedDep = useOptimistic<boolean>();
    const unlinkedList = useOptimistic<boolean>();
    const unlinkedNote = useOptimistic<boolean>();
    const archivedOpt = useOptimistic<boolean>();
    const isArchived = archivedOpt.get(project.id, project.archived);
    const visibleDeps = dependencies.filter((d) => !removedDep.get(d.id, false));
    const visibleLists = lists.filter((l) => !unlinkedList.get(l.id, false));
    const visibleNotes = notes.filter((n) => !unlinkedNote.get(n.id, false));
    const settle = (o: ReturnType<typeof useOptimistic<boolean>> | ReturnType<typeof useOptimistic<string>>, id: number) => ({
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => o.clear(id),
        onError: () => o.clear(id),
    });

    // A section shows once it has content, or right after the user chooses to add it from the sheet.
    const showDeps = visibleDeps.length > 0 || reveal.has('deps');
    const showTasks = taskTotal > 0 || reveal.has('tasks');
    const showLists = visibleLists.length > 0;
    const showNotes = visibleNotes.length > 0;

    const toggleArchived = () => {
        archivedOpt.set(project.id, !isArchived);
        router.patch(route('projects.update', project.id), { archived: !isArchived }, settle(archivedOpt, project.id));
    };
    const cycleDep = (d: Dep) => {
        const current = depStatusOpt.get(d.id, d.status);
        const next = DEP_STATUS_KEYS[(DEP_STATUS_KEYS.indexOf(current) + 1) % DEP_STATUS_KEYS.length];
        depStatusOpt.set(d.id, next);
        router.patch(route('projects.deps.update', d.id), { status: next }, settle(depStatusOpt, d.id));
    };
    const removeDep = (d: Dep) => {
        removedDep.set(d.id, true);
        router.delete(route('projects.deps.destroy', d.id), settle(removedDep, d.id));
    };
    const unlinkList = (l: LinkedList) => {
        unlinkedList.set(l.id, true);
        router.delete(route('projects.lists.unlink', [project.id, l.id]), settle(unlinkedList, l.id));
    };
    const unlinkNote = (n: LinkedNote) => {
        unlinkedNote.set(n.id, true);
        router.delete(route('projects.notes.unlink', [project.id, n.id]), settle(unlinkedNote, n.id));
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
                aria-label={t('projects.share_project')}
                className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
            >
                <UserPlus className="size-4.5" />
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={t('projects.project_options')}
                        className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
                    >
                        <span className="text-lg leading-none">⋯</span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem onSelect={() => setEditing(true)}>
                        <Pencil className="size-4" /> {t('projects.edit_project')}
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => router.post(route('projects.duplicate', project.id))}>
                        <Copy className="size-4" /> {t('projects.duplicate')}
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={toggleArchived}>
                        <Archive className="size-4" /> {t(isArchived ? 'projects.unarchive' : 'projects.archive')}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        className="text-destructive focus:text-destructive"
                        onSelect={() => {
                            if (confirm(t('projects.confirm_delete'))) router.delete(route('projects.destroy', project.id));
                        }}
                    >
                        <Trash2 className="size-4" /> {t('common.delete')}
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
                            <span className="text-sm font-medium">{t('projects.add_photo')}</span>
                        </button>
                    )}
                    <div className="absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-[#142218] via-[#142218]/60 to-transparent" />
                    <span
                        className={`absolute top-4 left-4 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${st.hero}`}
                    >
                        <span className="size-1.5 rounded-full bg-current opacity-60" /> {t(`projects.status_${project.status}`)}
                    </span>
                    {project.coverUrl && (
                        <button
                            type="button"
                            onClick={() => coverInput.current?.click()}
                            aria-label={t('projects.change_photo')}
                            className="absolute top-4 right-4 flex size-9 items-center justify-center rounded-full bg-black/30 text-white backdrop-blur-sm transition-colors hover:bg-black/45"
                        >
                            <Camera className="size-4.5" />
                        </button>
                    )}
                    <div className="pointer-events-none absolute inset-x-0 bottom-0 p-4">
                        <div className="text-[11px] font-semibold tracking-widest text-white/70 uppercase">{t('projects.family_project')}</div>
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
                            {members.slice(0, 3).map((m) => (
                                <MemberAvatar key={m.id} userId={m.id} name={m.name} ring className="size-6 text-[10px]" />
                            ))}
                        </div>
                        <span className="text-muted-foreground text-sm">{t('projects.shared_with_family')}</span>
                    </div>
                    {project.updated && <span className="text-text-muted font-mono text-xs">{t('projects.updated', { date: project.updated })}</span>}
                </div>

                {project.description && <p className="text-text-secondary text-sm leading-relaxed">{project.description}</p>}

                {/* Overall progress */}
                <div className="bg-surface-card rounded-2xl border p-4">
                    <div className="flex items-baseline justify-between">
                        <span className="text-muted-foreground text-sm font-medium">{t('projects.overall_progress')}</span>
                        <span className="text-primary font-mono text-sm font-semibold">{project.progress}%</span>
                    </div>
                    <div className="mt-3 flex items-center gap-4">
                        <div className="flex flex-col">
                            <span className="text-primary font-mono text-base font-semibold">{shortDate(project.targetStart)}</span>
                            <span className="text-text-muted text-[11px]">{t('projects.target_start')}</span>
                        </div>
                        <div className="bg-border h-6 w-px" />
                        <div className="flex flex-col">
                            <span className="text-primary font-mono text-base font-semibold">{project.ownerName ?? '—'}</span>
                            <span className="text-text-muted text-[11px]">{t('projects.owner')}</span>
                        </div>
                    </div>
                </div>

                {/* Blocked by */}
                {showDeps && (
                    <Section id="section-deps" icon={Ban} tone="red" title={t('projects.blocked_by')} count={visibleDeps.length}>
                        <div className="flex flex-col gap-2">
                            {visibleDeps.map((d) => {
                                const status = depStatusOpt.get(d.id, d.status);
                                const ds = depStatus(status);
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
                                            {t(`projects.dep_status_${status}`)}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => removeDep(d)}
                                            aria-label={t('projects.remove')}
                                            className="text-muted-foreground hover:text-destructive flex size-6 items-center justify-center"
                                        >
                                            <X className="size-4" />
                                        </button>
                                    </div>
                                );
                            })}
                            <InlineAdd
                                value={depName}
                                onChange={setDepName}
                                onAdd={addDep}
                                placeholder={t('projects.add_blocker_placeholder')}
                                autoFocus={reveal.has('deps') && dependencies.length === 0}
                            />
                        </div>
                    </Section>
                )}

                {/* Tasks */}
                {showTasks && (
                    <Section
                        id="section-tasks"
                        icon={ListChecks}
                        title={t('nav.tasks')}
                        sub={taskTotal ? t('projects.tasks_done', { done: taskDone, total: taskTotal }) : undefined}
                        pct={taskTotal ? taskPct : undefined}
                    >
                        <TaskTree
                            tasks={tasks}
                            members={members}
                            canEdit
                            addUrl={route('projects.tasks.store', project.id)}
                            taskUrl={(id) => route('projects.tasks.update', id)}
                            reorderUrl={route('projects.tasks.reorder', project.id)}
                            emptyHint={t('projects.no_tasks_hint')}
                        />
                    </Section>
                )}

                {/* Materials — linked shopping lists */}
                {showLists && (
                    <Section id="section-lists" icon={ShoppingCart} title={t('projects.shopping_lists')} count={visibleLists.length}>
                        <div className="flex flex-col gap-2">
                            {visibleLists.map((l) => (
                                <div key={l.id} className="bg-surface-card flex items-center gap-3 rounded-xl border p-3">
                                    <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                                        <ShoppingCart className="size-4.5" />
                                    </span>
                                    <Link href={`/m/shopping/lists/${l.id}`} className="min-w-0 flex-1">
                                        <div className="text-primary text-sm font-medium">{l.name}</div>
                                        <div className="text-muted-foreground text-xs">
                                            {l.store ? `${l.store} · ` : ''}
                                            {t('projects.items_bought', { done: l.bought, total: l.total })}
                                        </div>
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={() => unlinkList(l)}
                                        aria-label={t('projects.unlink_list')}
                                        className="text-muted-foreground hover:text-destructive flex size-6 items-center justify-center"
                                    >
                                        <Unlink className="size-4" />
                                    </button>
                                </div>
                            ))}
                            <button
                                type="button"
                                onClick={() => openLink('pick')}
                                className="border-accent text-primary hover:bg-surface flex items-center justify-center gap-2 rounded-xl border border-dashed py-3 text-sm font-medium transition-colors"
                            >
                                <Plus className="size-4" /> {t('projects.link_shopping_list')}
                            </button>
                        </div>
                    </Section>
                )}

                {/* Linked notes */}
                {showNotes && (
                    <Section id="section-notes" icon={StickyNote} title={t('nav.notes')} count={visibleNotes.length}>
                        <div className="flex flex-col gap-2">
                            {visibleNotes.map((n) => (
                                <div key={n.id} className={`flex items-center gap-3 rounded-xl border p-3 ${noteColor(n.color)}`}>
                                    <span className="bg-surface/70 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                                        <StickyNote className="size-4.5" />
                                    </span>
                                    <Link href={`/m/notes/${n.id}`} className="min-w-0 flex-1">
                                        <div className="text-primary truncate text-sm font-medium">{n.title}</div>
                                        {snippet(n.body, 60) && <div className="text-muted-foreground truncate text-xs">{snippet(n.body, 60)}</div>}
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={() => unlinkNote(n)}
                                        aria-label={t('projects.unlink_note')}
                                        className="text-muted-foreground hover:text-destructive flex size-6 items-center justify-center"
                                    >
                                        <Unlink className="size-4" />
                                    </button>
                                </div>
                            ))}
                            <button
                                type="button"
                                onClick={() => setLinkingNote(true)}
                                className="border-accent text-primary hover:bg-surface flex items-center justify-center gap-2 rounded-xl border border-dashed py-3 text-sm font-medium transition-colors"
                            >
                                <Plus className="size-4" /> {t('projects.add_note_button')}
                            </button>
                        </div>
                    </Section>
                )}

                {/* Compose the project: add a section / piece of content. Sections appear once they have content. */}
                <button
                    type="button"
                    onClick={() => setAdding(true)}
                    className="border-accent text-primary hover:bg-surface flex items-center justify-center gap-2 rounded-xl border border-dashed py-3.5 text-sm font-semibold transition-colors"
                >
                    <Plus className="size-4.5" /> {t('projects.add_something')}
                </button>
            </div>

            {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={project.name} />}
            <EditModal open={editing} onOpenChange={setEditing} project={project} members={members} />
            <LinkListModal open={linking} onOpenChange={setLinking} projectId={project.id} projectName={project.name} initialView={linkView} />
            <LinkNoteModal open={linkingNote} onOpenChange={setLinkingNote} projectId={project.id} projectName={project.name} />
            <AddToProjectSheet
                open={adding}
                onOpenChange={setAdding}
                projectName={project.name}
                onTaskList={() => revealSection('tasks')}
                onDependency={() => revealSection('deps')}
                onShoppingNew={() => openLink('new')}
                onShoppingLink={() => openLink('pick')}
                onNoteNew={() => {
                    setAdding(false);
                    router.post(route('projects.notes.create', project.id));
                }}
                onNoteLink={() => {
                    setAdding(false);
                    setLinkingNote(true);
                }}
            />
        </AppLayout>
    );
}

function Section({
    id,
    icon: Icon,
    tone = 'green',
    title,
    sub,
    count,
    pct,
    children,
}: {
    id?: string;
    icon: LucideIcon;
    tone?: 'green' | 'red';
    title: string;
    sub?: string;
    count?: number;
    pct?: number;
    children: React.ReactNode;
}) {
    return (
        <section id={id} className="scroll-mt-20">
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
    autoFocus,
}: {
    value: string;
    onChange: (v: string) => void;
    onAdd: () => void;
    placeholder: string;
    autoFocus?: boolean;
}) {
    const t = useT();
    return (
        <div className="flex items-center gap-2">
            <Input
                autoFocus={autoFocus}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && onAdd()}
                placeholder={placeholder}
            />
            <Button type="button" size="icon" onClick={onAdd} disabled={!value.trim()} aria-label={t('common.add')}>
                <Plus className="size-4" />
            </Button>
        </div>
    );
}

// The "Add to project" sheet: pick what to add. New content-type modules (Note, File, Contact, etc.)
// will slot in here as they are built; for now it composes the sections the project already supports.
function AddToProjectSheet({
    open,
    onOpenChange,
    projectName,
    onTaskList,
    onDependency,
    onShoppingNew,
    onShoppingLink,
    onNoteNew,
    onNoteLink,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    projectName: string;
    onTaskList: () => void;
    onDependency: () => void;
    onShoppingNew: () => void;
    onShoppingLink: () => void;
    onNoteNew: () => void;
    onNoteLink: () => void;
}) {
    const t = useT();
    const Row = ({ icon: Icon, title, sub, onClick }: { icon: LucideIcon; title: string; sub: string; onClick: () => void }) => (
        <button
            type="button"
            onClick={onClick}
            className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-2xl border p-3 text-left transition-colors"
        >
            <span className="bg-accent-subtle text-primary flex size-11 shrink-0 items-center justify-center rounded-xl">
                <Icon className="size-5.5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="text-primary font-semibold">{title}</div>
                <div className="text-muted-foreground text-xs">{sub}</div>
            </div>
            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
        </button>
    );

    return (
        <ResponsiveModal open={open} onOpenChange={onOpenChange} title={t('projects.add_to_project')} description={projectName}>
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <div className="text-text-muted text-[11px] font-semibold tracking-widest uppercase">{t('projects.create_new')}</div>
                    <Row icon={ListChecks} title={t('projects.task_list_title')} sub={t('projects.task_list_sub')} onClick={onTaskList} />
                    <Row
                        icon={ShoppingCart}
                        title={t('projects.shopping_list_title')}
                        sub={t('projects.shopping_list_sub')}
                        onClick={onShoppingNew}
                    />
                    <Row icon={StickyNote} title={t('projects.note_title')} sub={t('projects.note_sub')} onClick={onNoteNew} />
                    <Row icon={Ban} title={t('projects.dependency_title')} sub={t('projects.dependency_sub')} onClick={onDependency} />
                </div>
                <div className="flex flex-col gap-2">
                    <div className="text-text-muted text-[11px] font-semibold tracking-widest uppercase">{t('projects.link_existing')}</div>
                    <Row
                        icon={ShoppingCart}
                        title={t('projects.shopping_list_title')}
                        sub={t('projects.link_shopping_sub')}
                        onClick={onShoppingLink}
                    />
                    <Row icon={StickyNote} title={t('projects.note_title')} sub={t('projects.link_note_sub')} onClick={onNoteLink} />
                </div>
                <p className="text-muted-foreground text-xs leading-relaxed">{t('projects.coming_soon')}</p>
            </div>
        </ResponsiveModal>
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
    const t = useT();
    const { data, setData, patch } = useForm({
        name: project.name,
        description: project.description ?? '',
        status: project.status,
        progress: project.progress,
        owner_id: project.ownerId,
        target_start: project.targetStart ?? '',
        waiting_on: project.waitingOn ?? '',
    });
    const patchProject = () => patch(route('projects.update', project.id), { preserveScroll: true, preserveState: true });
    // Auto-save: debounce edits; skip the initial mount; flush on close. No Save button.
    const first = useRef(true);
    useEffect(() => {
        if (!open) return;
        if (first.current) {
            first.current = false;
            return;
        }
        if (!data.name.trim()) return;
        const timer = setTimeout(patchProject, 500);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, open]);
    return (
        <ResponsiveModal
            open={open}
            onOpenChange={(v) => {
                if (!v && data.name.trim()) patchProject(); // flush the last edit
                onOpenChange(v);
            }}
            title={t('projects.edit_project')}
        >
            <form id="edit-project" onSubmit={(e) => e.preventDefault()} className="flex flex-col gap-5">
                <div className="grid gap-2">
                    <Label>{t('projects.name')}</Label>
                    <Input value={data.name} onChange={(e) => setData('name', e.target.value)} />
                </div>
                <div className="grid gap-2">
                    <Label>{t('projects.description')}</Label>
                    <textarea
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        className="border-border focus:border-accent min-h-20 w-full resize-none rounded-lg border bg-transparent p-3 text-base outline-none md:text-sm"
                    />
                </div>
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('projects.status')}</Label>
                    <div className="bg-surface grid grid-cols-4 gap-1 rounded-lg border p-1">
                        {PROJECT_STATUS_KEYS.map((s) => (
                            <button
                                key={s}
                                type="button"
                                onClick={() => setData('status', s)}
                                className={`rounded-md py-1.5 text-xs font-medium transition-colors ${data.status === s ? 'bg-accent-strong text-accent-strong-foreground' : 'text-muted-foreground'}`}
                            >
                                {t(`projects.status_${s}`)}
                            </button>
                        ))}
                    </div>
                </div>
                {data.status === 'blocked' && (
                    <div className="grid gap-2">
                        <Label>{t('projects.waiting_on')}</Label>
                        <Input
                            value={data.waiting_on}
                            onChange={(e) => setData('waiting_on', e.target.value)}
                            placeholder={t('projects.waiting_on_placeholder')}
                        />
                    </div>
                )}
                <div className="grid gap-2">
                    <div className="flex items-baseline justify-between">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('projects.progress')}</Label>
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
                    <Label>{t('projects.target_start')}</Label>
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
                    <Label>{t('projects.owner')}</Label>
                    <select
                        value={data.owner_id ?? ''}
                        onChange={(e) => setData('owner_id', e.target.value ? Number(e.target.value) : null)}
                        className="border-border text-primary h-9 w-full rounded-lg border bg-transparent px-3 text-sm outline-none"
                    >
                        <option value="">{t('projects.unassigned')}</option>
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
    initialView = 'pick',
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    projectId: number;
    projectName: string;
    initialView?: 'pick' | 'new';
}) {
    const t = useT();
    const [view, setView] = useState<'pick' | 'new'>(initialView);
    const [lists, setLists] = useState<Linkable[]>([]);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [q, setQ] = useState('');
    const [newName, setNewName] = useState('');
    const [items, setItems] = useState<NewItem[]>([{ name: '', quantity: '' }]);

    useEffect(() => {
        if (!open) return;
        setView(initialView);
        setSelected(new Set());
        setQ('');
        setNewName('');
        setItems([{ name: '', quantity: '' }]);
        fetch(route('projects.lists.linkable', projectId, false), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { lists: [] }))
            .then((d) => setLists(d.lists));
    }, [open, projectId, initialView]);

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
            title={view === 'pick' ? t('projects.shopping_list_title') : t('projects.new_shopping_list')}
            description={view === 'pick' ? t('projects.add_to', { name: projectName }) : undefined}
            onBack={view === 'new' ? () => setView('pick') : undefined}
            footer={
                view === 'pick' ? (
                    <Button className="w-full" disabled={!selected.size} onClick={addSelected}>
                        {selected.size
                            ? t(selected.size === 1 ? 'projects.add_list_one' : 'projects.add_list_other', { count: selected.size })
                            : t('projects.select_list_to_add')}
                    </Button>
                ) : (
                    <Button className="w-full" disabled={!newName.trim()} onClick={createList}>
                        {t('projects.create_and_add')}
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
                        <span className="bg-tile text-tile-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                            <Plus className="size-5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="text-primary text-sm font-semibold">{t('projects.create_new_list')}</div>
                            <div className="text-muted-foreground text-xs">{t('projects.create_new_list_sub')}</div>
                        </div>
                        <ChevronRight className="text-primary size-4" />
                    </button>

                    <div className="text-text-muted text-[11px] font-semibold tracking-widest uppercase">{t('projects.or_link_existing_list')}</div>
                    <div className="bg-surface flex items-center gap-2 rounded-lg px-3 py-2">
                        <Search className="text-muted-foreground size-4" />
                        <input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder={t('projects.search_lists_placeholder')}
                            // text-base (16px) on mobile so iOS Safari doesn't auto-zoom on focus; text-sm from md up.
                            className="text-primary w-full bg-transparent text-base outline-none md:text-sm"
                        />
                    </div>
                    <div className="flex flex-col gap-2">
                        {filtered.length === 0 && <p className="text-muted-foreground py-4 text-center text-sm">{t('projects.no_lists_to_link')}</p>}
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
                                            {t(l.count === 1 ? 'projects.item_one' : 'projects.item_other', { count: l.count })} · {l.sharedWith}
                                        </div>
                                    </div>
                                    <span
                                        className={`flex size-6 shrink-0 items-center justify-center rounded-md border ${on ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border'}`}
                                    >
                                        {on && <Check className="size-4" />}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-muted-foreground text-xs leading-relaxed">{t('projects.linked_lists_note')}</p>
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    {/* Back lives in the sheet header (above this title); here we render the visible title + subtitle. */}
                    <div>
                        <h2 className="font-heading text-primary text-2xl">{t('projects.new_shopping_list')}</h2>
                        <p className="text-muted-foreground text-sm">{t('projects.new_shopping_list_sub', { name: projectName })}</p>
                    </div>
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('projects.list_name')}</Label>
                        <Input
                            autoFocus
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                            placeholder={t('projects.list_name_placeholder')}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                            {t('projects.start_it_off_with')}
                        </Label>
                        <div className="flex flex-col gap-2">
                            {items.map((it, i) => (
                                <div key={i} className="flex items-center gap-2">
                                    <Input
                                        value={it.name}
                                        onChange={(e) => setItems((a) => a.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))}
                                        placeholder={t('projects.item_placeholder')}
                                        className="flex-1"
                                    />
                                    <Input
                                        value={it.quantity}
                                        onChange={(e) => setItems((a) => a.map((x, j) => (j === i ? { ...x, quantity: e.target.value } : x)))}
                                        placeholder={t('projects.qty_placeholder')}
                                        className="w-20 shrink-0"
                                    />
                                    {items.length > 1 && (
                                        <button
                                            type="button"
                                            onClick={() => setItems((a) => a.filter((_, j) => j !== i))}
                                            aria-label={t('projects.remove_item')}
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
                                <Plus className="size-4" /> {t('projects.add_another_item')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ResponsiveModal>
    );
}

type LinkableNote = { id: number; name: string; preview: string; sharedWith: string };

// Pick existing notes to link to the project (multi-select), or jump straight into a new blank note.
function LinkNoteModal({
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
    const t = useT();
    const [notes, setNotes] = useState<LinkableNote[]>([]);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [q, setQ] = useState('');

    useEffect(() => {
        if (!open) return;
        setSelected(new Set());
        setQ('');
        fetch(route('projects.notes.linkable', projectId, false), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { notes: [] }))
            .then((d) => setNotes(d.notes));
    }, [open, projectId]);

    const filtered = notes.filter((n) => n.name.toLowerCase().includes(q.toLowerCase()));
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
            route('projects.notes.link', projectId),
            { note_ids: [...selected] },
            { preserveScroll: true, onSuccess: () => onOpenChange(false) },
        );
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('projects.note_title')}
            description={t('projects.add_to', { name: projectName })}
            footer={
                <Button className="w-full" disabled={!selected.size} onClick={addSelected}>
                    {selected.size
                        ? t(selected.size === 1 ? 'projects.add_note_one' : 'projects.add_note_other', { count: selected.size })
                        : t('projects.select_note_to_add')}
                </Button>
            }
        >
            <div className="flex flex-col gap-3">
                <button
                    type="button"
                    onClick={() => {
                        onOpenChange(false);
                        router.post(route('projects.notes.create', projectId));
                    }}
                    className="border-primary/40 bg-accent-subtle flex items-center gap-3 rounded-xl border border-dashed p-3 text-left"
                >
                    <span className="bg-tile text-tile-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                        <Plus className="size-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="text-primary text-sm font-semibold">{t('projects.create_new_note')}</div>
                        <div className="text-muted-foreground text-xs">{t('projects.create_new_note_sub')}</div>
                    </div>
                    <ChevronRight className="text-primary size-4" />
                </button>

                <div className="text-text-muted text-[11px] font-semibold tracking-widest uppercase">{t('projects.or_link_existing_note')}</div>
                <div className="bg-surface flex items-center gap-2 rounded-lg px-3 py-2">
                    <Search className="text-muted-foreground size-4" />
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('projects.search_notes_placeholder')}
                        // text-base (16px) on mobile so iOS Safari doesn't auto-zoom on focus; text-sm from md up.
                        className="text-primary w-full bg-transparent text-base outline-none md:text-sm"
                    />
                </div>
                <div className="flex flex-col gap-2">
                    {filtered.length === 0 && <p className="text-muted-foreground py-4 text-center text-sm">{t('projects.no_notes_to_link')}</p>}
                    {filtered.map((n) => {
                        const on = selected.has(n.id);
                        return (
                            <button
                                key={n.id}
                                type="button"
                                onClick={() => toggle(n.id)}
                                className={`flex items-center gap-3 rounded-xl border p-3 text-left transition-colors ${on ? 'border-primary bg-accent-subtle' : 'bg-surface-card'}`}
                            >
                                <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <StickyNote className="size-4.5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="text-primary truncate text-sm font-medium">{n.name}</div>
                                    <div className="text-muted-foreground truncate text-xs">{n.preview || n.sharedWith}</div>
                                </div>
                                <span
                                    className={`flex size-6 shrink-0 items-center justify-center rounded-md border ${on ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border'}`}
                                >
                                    {on && <Check className="size-4" />}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>
        </ResponsiveModal>
    );
}
