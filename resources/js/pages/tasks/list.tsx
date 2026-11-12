import { Head, Link, router } from '@inertiajs/react';
import { Archive, Check, ChevronLeft, Copy, ListChecks, Lock, MoreHorizontal, Pencil, Trash2, UserPlus, Users } from 'lucide-react';
import { useRef, useState } from 'react';

import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { TaskRows } from '@/components/tasks/task-rows';
import { type TaskMember, type TaskNode } from '@/components/tasks/task-tree';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useAddAction } from '@/lib/add-action';
import { useT } from '@/lib/i18n';
import { backClick } from '@/lib/nav-origin';
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

export default function ListDetail({ list, share, members, tasks, lastDone, family }: Props) {
    const t = useT();
    const [sharing, setSharing] = useState(false);
    const [editing, setEditing] = useState(false);

    // The nav's "+" focuses the add-a-task input on this page.
    const addInputRef = useRef<HTMLInputElement>(null);
    useAddAction(
        () => {
            addInputRef.current?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            addInputRef.current?.focus();
        },
        { label: 'add.page_todo', icon: ListChecks },
    );

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
                onClick={backClick}
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

                <TaskRows
                    tasks={tasks}
                    members={members}
                    family={family}
                    list={list}
                    lastDone={lastDone}
                    addUrl={route('tasks.tasks.store', list.id)}
                    taskUrl={(id) => route('tasks.tasks.update', id)}
                    addInputRef={addInputRef}
                />
            </div>

            <EditListModal open={editing} onOpenChange={setEditing} list={list} />
            {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={list.name} />}
        </AppLayout>
    );
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
