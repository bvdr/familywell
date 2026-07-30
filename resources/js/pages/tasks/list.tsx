import { Head, router } from '@inertiajs/react';
import { Archive, Check, Copy, Lock, MoreHorizontal, Pencil, Trash2, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';

import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { TaskTree, type TaskMember, type TaskNode } from '@/components/tasks/task-tree';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import AppLayout from '@/layouts/app-layout';
import { LIST_COLORS, LIST_ICONS, listColor, listIcon } from '@/lib/tasks';
import { type BreadcrumbItem } from '@/types';

type List = { id: number; name: string; color: string; icon: string; archived: boolean; visibility: string };

export default function ListDetail({ list, share, members, tasks }: { list: List; share: ShareState; members: TaskMember[]; tasks: TaskNode[] }) {
    const [sharing, setSharing] = useState(false);
    const [editing, setEditing] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tasks', href: '/m/tasks' },
        { title: list.name, href: route('tasks.show', list.id) },
    ];

    const Icon = listIcon(list.icon);
    const done = tasks.reduce((n, t) => n + (t.done ? 1 : 0) + t.subtasks.filter((s) => s.done).length, 0);
    const total = tasks.reduce((n, t) => n + 1 + t.subtasks.length, 0);

    const patchList = (data: Record<string, string | number | boolean | null>) =>
        router.patch(route('tasks.lists.update', list.id), data, { preserveScroll: true });

    const headerActions = (
        <>
            <button
                type="button"
                onClick={() => setSharing(true)}
                aria-label="Share list"
                className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
            >
                <UserPlus className="size-4.5" />
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label="List options"
                        className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
                    >
                        <MoreHorizontal className="size-4.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem onSelect={() => setSharing(true)}>
                        <UserPlus className="size-4" /> Share list
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setTimeout(() => setEditing(true), 0)}>
                        <Pencil className="size-4" /> Edit list
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    {list.visibility === 'family' ? (
                        <DropdownMenuItem onSelect={() => patchList({ visibility: 'private' })}>
                            <Lock className="size-4" /> Make private
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => patchList({ visibility: 'family' })}>
                            <Users className="size-4" /> Share with family
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem onSelect={() => router.post(route('tasks.lists.duplicate', list.id))}>
                        <Copy className="size-4" /> Duplicate list
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => patchList({ archived: true })}>
                        <Archive className="size-4" /> Archive list
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        className="text-destructive focus:text-destructive"
                        onSelect={() => {
                            if (confirm('Delete this list and all its tasks?')) router.delete(route('tasks.lists.destroy', list.id));
                        }}
                    >
                        <Trash2 className="size-4" /> Delete list
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs} actions={headerActions}>
            <Head title={list.name} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center gap-3">
                    <span className={`flex size-12 shrink-0 items-center justify-center rounded-xl ${listColor(list.color)}`}>
                        <Icon className="size-6" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h1 className="font-heading text-primary flex items-center gap-1.5 truncate text-2xl">
                            {list.name}
                            {list.visibility === 'private' && <Lock className="text-muted-foreground size-4 shrink-0" />}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {done} of {total} done
                        </p>
                    </div>
                </div>

                <TaskTree
                    tasks={tasks}
                    members={members}
                    canEdit
                    addUrl={route('tasks.tasks.store', list.id)}
                    taskUrl={(id) => route('tasks.tasks.update', id)}
                    reorderUrl={route('tasks.tasks.reorder', list.id)}
                    emptyHint="No tasks yet. Add your first one below."
                />
            </div>

            <EditListModal open={editing} onOpenChange={setEditing} list={list} />
            {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={list.name} />}
        </AppLayout>
    );
}

function EditListModal({ open, onOpenChange, list }: { open: boolean; onOpenChange: (v: boolean) => void; list: List }) {
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
            title="Edit list"
            footer={
                <Button className="w-full" onClick={save} disabled={!name.trim()}>
                    Save
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
                    <Input autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder="List name" className="text-base" />
                </div>
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Color &amp; icon</Label>
                    <div className="flex flex-wrap gap-2">
                        {Object.keys(LIST_COLORS).map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => setColor(c)}
                                aria-label={`${c} color`}
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
                                    aria-label={`${ic} icon`}
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
