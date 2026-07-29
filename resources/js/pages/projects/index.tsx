import { Head, Link, router, useForm } from '@inertiajs/react';
import { Archive, FolderKanban, Link2, Plus, RotateCcw } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { MemberAvatar } from '@/components/member-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { PROJECT_STATUS_KEYS, projectStatus } from '@/lib/projects';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string };
type Project = {
    id: number;
    name: string;
    description: string | null;
    status: string;
    progress: number;
    color: string;
    waitingOn: string | null;
    done: number;
    total: number;
    archived: boolean;
    coverUrl: string | null;
    updated: string | null;
    members: Member[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Projects', href: '/m/projects' }];

function Avatars({ members }: { members: Member[] }) {
    if (members.length === 0) return null;
    return (
        <div className="flex -space-x-1.5">
            {members.slice(0, 4).map((m) => (
                <MemberAvatar key={m.id} userId={m.id} name={m.name} ring className="size-6 text-[10px]" />
            ))}
        </div>
    );
}

function Card({ p }: { p: Project }) {
    const st = projectStatus(p.status);
    return (
        <Link
            href={route('projects.show', p.id)}
            prefetch
            className="bg-surface-card hover:border-accent block overflow-hidden rounded-2xl border p-4 text-left transition-colors"
        >
            {p.coverUrl && <img src={p.coverUrl} alt="" className="-mx-4 -mt-4 mb-3 h-28 w-full object-cover" />}
            <div className="flex items-start justify-between gap-2.5">
                <div className="font-heading text-primary text-lg leading-tight font-semibold">{p.name}</div>
                <span className={`shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold ${st.chip}`}>{st.label}</span>
            </div>
            {p.description && <div className="text-muted-foreground mt-1.5 line-clamp-2 text-sm">{p.description}</div>}
            {p.status === 'blocked' && p.waitingOn && (
                <div className="mt-2.5 flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
                    <Link2 className="size-3.5 shrink-0" /> {p.waitingOn}
                </div>
            )}
            <div className="mt-3 text-xs">
                <span className="text-muted-foreground">{p.total ? `${p.done} of ${p.total} done` : 'No tasks yet'}</span>
            </div>
            <div className="mt-3 flex items-center justify-between border-t pt-3">
                <Avatars members={p.members} />
                {p.updated && <span className="text-text-muted font-mono text-[11px]">{p.updated}</span>}
            </div>
        </Link>
    );
}

export default function ProjectsIndex({ projects }: { projects: Project[] }) {
    const [creating, setCreating] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        description: '',
        status: 'planning',
        progress: 0,
    });

    const archivedOpt = useOptimistic<boolean>();
    const isArchived = (p: Project) => archivedOpt.get(p.id, p.archived);
    const active = projects.filter((p) => !isArchived(p));
    const archived = projects.filter((p) => isArchived(p));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('projects.store'), {
            onSuccess: () => {
                reset();
                setCreating(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Projects" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="font-heading text-primary text-2xl">Projects</h1>
                        <p className="text-muted-foreground text-sm">The bigger things your family is working on together.</p>
                    </div>
                    <Button size="icon" className="rounded-full" onClick={() => setCreating(true)} aria-label="New project">
                        <Plus className="size-5" />
                    </Button>
                </div>

                {active.length === 0 && (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                        <FolderKanban className="size-6" />
                        <p className="text-sm">No projects yet. Start one to plan something together.</p>
                    </div>
                )}

                <div className="flex flex-col gap-3">
                    {active.map((p) => (
                        <Card key={p.id} p={p} />
                    ))}
                </div>

                {archived.length > 0 && (
                    <div className="mt-2 flex flex-col gap-2">
                        <h2 className="text-muted-foreground flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                            <Archive className="size-3.5" /> Archived
                        </h2>
                        {archived.map((p) => {
                            const st = projectStatus(p.status);
                            return (
                                <div key={p.id} className="bg-surface flex items-center gap-3 rounded-xl border p-3 opacity-70">
                                    <span className="text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg border">
                                        <FolderKanban className="size-4.5" />
                                    </span>
                                    <Link href={route('projects.show', p.id)} className="min-w-0 flex-1">
                                        <div className="text-primary truncate text-sm font-medium">{p.name}</div>
                                        <div className="text-muted-foreground text-xs">{st.label}</div>
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            archivedOpt.set(p.id, false);
                                            router.patch(
                                                route('projects.update', p.id),
                                                { archived: false },
                                                {
                                                    preserveScroll: true,
                                                    preserveState: true,
                                                    onSuccess: () => archivedOpt.clear(p.id),
                                                    onError: () => archivedOpt.clear(p.id),
                                                },
                                            );
                                        }}
                                        aria-label="Restore project"
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

            <ResponsiveModal
                open={creating}
                onOpenChange={setCreating}
                title="New project"
                footer={
                    <Button type="submit" form="new-project" disabled={processing || !data.name.trim()} className="w-full">
                        <Plus className="size-4" /> Create project
                    </Button>
                }
            >
                <form id="new-project" onSubmit={submit} className="flex flex-col gap-5">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Project name</Label>
                        <Input
                            id="name"
                            autoFocus
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Backyard Paving"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="desc">Description</Label>
                        <textarea
                            id="desc"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="What are you trying to get done, and by when?"
                            className="border-border focus:border-accent min-h-24 w-full resize-none rounded-lg border bg-transparent p-3 text-base outline-none md:text-sm"
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
                                    className={`rounded-md py-1.5 text-xs font-medium transition-colors ${
                                        data.status === s ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'
                                    }`}
                                >
                                    {projectStatus(s).label}
                                </button>
                            ))}
                        </div>
                    </div>
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
                </form>
            </ResponsiveModal>
        </AppLayout>
    );
}
