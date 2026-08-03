import { Head, Link, router, useForm } from '@inertiajs/react';
import { Archive, Check, ChevronRight, ListChecks, Lock, Plus, RotateCcw, UserCheck, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { MemberAvatar } from '@/components/member-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { LIST_COLORS, LIST_ICONS, listColor, listIcon } from '@/lib/tasks';
import { type BreadcrumbItem } from '@/types';

const VISIBILITY = [
    { key: 'family', icon: Users, title: 'Anyone in the family', sub: 'All family members can view' },
    { key: 'invited', icon: UserCheck, title: 'Only invited people', sub: 'Just the people you add' },
    { key: 'private', icon: Lock, title: 'Private', sub: 'Visible only to you' },
] as const;

type Member = { id: number; name: string };
type List = {
    id: number;
    name: string;
    color: string;
    icon: string;
    taskCount: number;
    doneCount: number;
    archived: boolean;
    visibility: string;
    members: Member[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tasks', href: '/m/tasks' }];

function Avatars({ members }: { members: Member[] }) {
    if (members.length === 0) return null;
    return (
        <div className="flex -space-x-1.5">
            {members.slice(0, 3).map((m) => (
                <MemberAvatar key={m.id} userId={m.id} name={m.name} ring className="size-6 text-[10px]" />
            ))}
            {members.length > 3 && <span className="text-muted-foreground self-center pl-2.5 text-xs">+{members.length - 3}</span>}
        </div>
    );
}

function ListCard({ list }: { list: List }) {
    const Icon = listIcon(list.icon);
    return (
        <Link
            href={route('tasks.show', list.id)}
            prefetch
            className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-2xl border p-4 text-left transition-colors"
        >
            <span className={`flex size-11 shrink-0 items-center justify-center rounded-xl ${listColor(list.color)}`}>
                <Icon className="size-5.5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="text-primary flex items-center gap-1.5 font-medium">
                    <span className="truncate">{list.name}</span>
                    {list.visibility === 'private' && <Lock className="text-muted-foreground size-3.5 shrink-0" />}
                </div>
                <div className="text-muted-foreground mt-0.5 text-xs">
                    {list.doneCount} of {list.taskCount} done
                </div>
            </div>
            <Avatars members={list.members} />
            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
        </Link>
    );
}

export default function Lists({ lists }: { lists: List[] }) {
    const [creating, setCreating] = useState(false);
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
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tasks" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="font-heading text-primary text-2xl">To-do Lists</h1>
                    <Button size="icon" className="rounded-full" onClick={() => setCreating((v) => !v)} aria-label="New list">
                        <Plus className="size-5" />
                    </Button>
                </div>

                <ResponsiveModal
                    open={creating}
                    onOpenChange={setCreating}
                    title="New List"
                    footer={
                        <Button type="submit" form="new-list" disabled={processing || !data.name.trim()} className="w-full">
                            <Plus className="size-4" /> Create list
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
                                placeholder="List name"
                                className="text-base"
                            />
                        </div>
                        <InputError message={errors.name} />

                        <div className="grid gap-2">
                            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Color &amp; icon</Label>
                            <div className="flex flex-wrap gap-2">
                                {Object.keys(LIST_COLORS).map((c) => (
                                    <button
                                        key={c}
                                        type="button"
                                        onClick={() => setData('color', c)}
                                        aria-label={`${c} color`}
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
                                            aria-label={`${ic} icon`}
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
                            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Who can view</Label>
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

                {active.length === 0 && !creating && (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                        <ListChecks className="size-6" />
                        <p className="text-sm">No lists yet. Create one to start organizing to-dos.</p>
                    </div>
                )}

                <div className="flex flex-col gap-3">
                    {active.map((l) => (
                        <ListCard key={l.id} list={l} />
                    ))}
                </div>

                {archived.length > 0 && (
                    <div className="mt-2 flex flex-col gap-2">
                        <h2 className="text-muted-foreground flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                            <Archive className="size-3.5" /> Archived
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
                                        <div className="text-muted-foreground text-xs">
                                            {l.doneCount} of {l.taskCount} done
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            archivedOpt.set(l.id, false);
                                            router.patch(
                                                route('tasks.lists.update', l.id),
                                                { archived: false },
                                                {
                                                    preserveScroll: true,
                                                    preserveState: true,
                                                    onSuccess: () => archivedOpt.clear(l.id),
                                                    onError: () => archivedOpt.clear(l.id),
                                                },
                                            );
                                        }}
                                        aria-label="Restore list"
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
