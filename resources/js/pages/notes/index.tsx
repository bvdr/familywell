import { useAddAction } from '@/lib/add-action';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, LayoutGrid, List as ListIcon, Lock, MoreHorizontal, Pin, Plus, Search, StickyNote, Trash2 } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { appLocale, useT, type TFn } from '@/lib/i18n';
import { noteColor, snippet } from '@/lib/notes';
import { save } from '@/lib/save';
import { type BreadcrumbItem } from '@/types';

type Folder = { id: number; name: string; color: string; count: number };
type Note = {
    id: number;
    title: string;
    body: string;
    color: string;
    pinned: boolean;
    folderId: number | null;
    visibility: string;
    updatedAt: string;
};

const ALL = '__all__';

function relDate(iso: string, t: TFn): string {
    const then = new Date(iso);
    const now = new Date();
    const days = Math.floor((now.getTime() - then.getTime()) / 86400000);
    if (days <= 0) return t('notes.today');
    if (days === 1) return t('notes.yesterday');
    if (days < 7) return t('notes.days_ago', { days });
    return then.toLocaleDateString(appLocale(), { month: 'short', day: 'numeric' });
}

function NoteCard({ note, folders, grid, t }: { note: Note; folders: Folder[]; grid: boolean; t: TFn }) {
    const folder = folders.find((f) => f.id === note.folderId);
    const title = note.title.trim() || t('notes.untitled');
    return (
        // A real link (not a button + router.visit): opens in a new tab, reads as a link to screen readers.
        <Link
            href={route('notes.show', note.id)}
            className={`hover:border-accent flex flex-col rounded-2xl border p-4 text-left transition-colors ${noteColor(note.color)} ${grid ? '' : 'w-full'}`}
        >
            <div className="flex items-start gap-1.5">
                <span className="text-primary min-w-0 flex-1 truncate font-medium">{title}</span>
                {note.pinned && <Pin className="text-muted-foreground size-3.5 shrink-0 fill-current" />}
                {note.visibility === 'private' && <Lock className="text-muted-foreground size-3.5 shrink-0" />}
            </div>
            <p className={`text-muted-foreground mt-1 text-sm ${grid ? 'line-clamp-4' : 'line-clamp-2'}`}>
                {snippet(note.body) || t('notes.no_text')}
            </p>
            <div className="text-muted-foreground mt-3 flex items-center gap-1.5 text-xs">
                <span>{relDate(note.updatedAt, t)}</span>
                {folder && (
                    <>
                        <span>·</span>
                        <span className="truncate">{folder.name}</span>
                    </>
                )}
            </div>
        </Link>
    );
}

export default function NotesIndex({ notes, folders }: { notes: Note[]; folders: Folder[] }) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.notes'), href: '/m/notes' }];
    const [activeFolder, setActiveFolder] = useState<string>(ALL);
    const [grid, setGrid] = useState(true);
    const [query, setQuery] = useState('');
    const [creatingFolder, setCreatingFolder] = useState(false);
    const [editingFolder, setEditingFolder] = useState<Folder | null>(null);
    // Optimistic folder delete: the chip disappears and the filter falls back to "all" immediately;
    // the chip only comes back if the DELETE fails.
    const removedFolder = useOptimistic<boolean>();
    const visibleFolders = folders.filter((f) => !removedFolder.get(f.id, false));
    const deleteFolder = (folder: Folder) => {
        removedFolder.set(folder.id, true);
        setActiveFolder(ALL);
        void save('delete', route('notes.folders.destroy', folder.id)).then(() => removedFolder.clear(folder.id));
    };

    // The nav's "+" (and the desktop header "+") create a note in the active folder, if one is
    // selected. This runs on a user click, when Inertia's router is ready. Cross-page quick-add
    // instead POSTs straight to notes.store from the nav (see MobileTabBar), landing in the editor.
    const createNote = () => router.post(route('notes.store'), activeFolder === ALL ? {} : { folder_id: Number(activeFolder) });
    useAddAction(createNote);

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        return notes.filter((n) => {
            if (activeFolder !== ALL && String(n.folderId) !== activeFolder) return false;
            if (q && !(n.title + ' ' + n.body).toLowerCase().includes(q)) return false;
            return true;
        });
    }, [notes, activeFolder, query]);

    const pinned = visible.filter((n) => n.pinned);
    const rest = visible.filter((n) => !n.pinned);

    const gridClass = grid ? 'grid grid-cols-2 gap-3 sm:grid-cols-3' : 'flex flex-col gap-2.5';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('nav.notes')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="font-heading text-primary text-2xl">{t('nav.notes')}</h1>
                    <div className="flex items-center gap-2">
                        {/* Grid / list toggle. */}
                        <div className="border-border flex items-center rounded-lg border p-0.5">
                            <button
                                type="button"
                                onClick={() => setGrid(true)}
                                aria-label={t('notes.grid_view')}
                                className={`flex size-7 items-center justify-center rounded-md ${grid ? 'bg-accent-subtle text-primary' : 'text-muted-foreground'}`}
                            >
                                <LayoutGrid className="size-4" />
                            </button>
                            <button
                                type="button"
                                onClick={() => setGrid(false)}
                                aria-label={t('notes.list_view')}
                                className={`flex size-7 items-center justify-center rounded-md ${!grid ? 'bg-accent-subtle text-primary' : 'text-muted-foreground'}`}
                            >
                                <ListIcon className="size-4" />
                            </button>
                        </div>
                        {/* Hidden on mobile: the nav's context-aware "+" creates a note there. */}
                        <Button size="icon" className="hidden rounded-full md:inline-flex" onClick={createNote} aria-label={t('notes.new_note')}>
                            <Plus className="size-5" />
                        </Button>
                    </div>
                </div>

                {/* Search. */}
                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('notes.search_placeholder')}
                        className="pl-9 text-base"
                    />
                </div>

                {/* Folder filter chips. */}
                <div data-scroll-x className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    {[{ id: ALL, name: t('notes.all_notes'), count: notes.length }, ...visibleFolders].map((f) => {
                        const on = String(f.id) === activeFolder;
                        const isReal = f.id !== ALL;
                        return (
                            <button
                                key={f.id}
                                type="button"
                                onClick={() => setActiveFolder(String(f.id))}
                                className={`flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm transition-colors ${
                                    on ? 'border-primary bg-accent-subtle text-primary' : 'border-border text-muted-foreground'
                                }`}
                                onDoubleClick={() => isReal && setEditingFolder(f as Folder)}
                            >
                                <span>{f.name}</span>
                                <span className="text-xs opacity-70">{f.count}</span>
                            </button>
                        );
                    })}
                    <button
                        type="button"
                        onClick={() => setCreatingFolder(true)}
                        aria-label={t('notes.new_folder')}
                        className="border-border text-muted-foreground hover:text-primary flex shrink-0 items-center gap-1 rounded-full border border-dashed px-3 py-1.5 text-sm"
                    >
                        <Plus className="size-3.5" /> {t('notes.folder')}
                    </button>
                    {activeFolder !== ALL && (
                        <FolderMenu
                            folder={visibleFolders.find((f) => String(f.id) === activeFolder)}
                            onEdit={setEditingFolder}
                            onDelete={deleteFolder}
                            t={t}
                        />
                    )}
                </div>

                {visible.length === 0 && (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                        <StickyNote className="size-6" />
                        <p className="text-sm">{query ? t('notes.no_search_results') : t('notes.empty_state')}</p>
                    </div>
                )}

                {pinned.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h2 className="text-muted-foreground flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
                            <Pin className="size-3.5" /> {t('notes.pinned')}
                        </h2>
                        <div className={gridClass}>
                            {pinned.map((n) => (
                                <NoteCard key={n.id} note={n} folders={folders} grid={grid} t={t} />
                            ))}
                        </div>
                    </div>
                )}

                {rest.length > 0 && (
                    <div className="flex flex-col gap-2">
                        {pinned.length > 0 && (
                            <h2 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('nav.notes')}</h2>
                        )}
                        <div className={gridClass}>
                            {rest.map((n) => (
                                <NoteCard key={n.id} note={n} folders={folders} grid={grid} t={t} />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <FolderModal open={creatingFolder} onOpenChange={setCreatingFolder} t={t} />
            <FolderModal open={!!editingFolder} onOpenChange={(v) => !v && setEditingFolder(null)} folder={editingFolder} t={t} />
        </AppLayout>
    );
}

// Small ⋯ menu shown when a real folder is the active filter: rename or delete it.
function FolderMenu({ folder, onEdit, onDelete, t }: { folder?: Folder; onEdit: (f: Folder) => void; onDelete: (f: Folder) => void; t: TFn }) {
    if (!folder) return null;
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={t('notes.folder_options')}
                    className="border-border text-muted-foreground hover:text-primary flex size-8 shrink-0 items-center justify-center rounded-full border"
                >
                    <MoreHorizontal className="size-4" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuItem onSelect={() => setTimeout(() => onEdit(folder), 0)}>{t('notes.rename_folder')}</DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    className="text-destructive focus:text-destructive"
                    onSelect={() => {
                        if (confirm(t('notes.confirm_delete_folder'))) onDelete(folder);
                    }}
                >
                    <Trash2 className="size-4" /> {t('notes.delete_folder')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

// Create or rename a folder (same form; `folder` set => rename).
function FolderModal({ open, onOpenChange, folder, t }: { open: boolean; onOpenChange: (v: boolean) => void; folder?: Folder | null; t: TFn }) {
    const { data, setData, post, patch, processing, errors, reset } = useForm({ name: folder?.name ?? '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const done = () => {
            reset();
            onOpenChange(false);
        };
        if (folder) patch(route('notes.folders.update', folder.id), { preserveScroll: true, onSuccess: done });
        else post(route('notes.folders.store'), { preserveScroll: true, onSuccess: done });
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={folder ? t('notes.rename_folder') : t('notes.new_folder')}
            footer={
                <Button type="submit" form="folder-form" disabled={processing || !data.name.trim()} className="w-full">
                    <Check className="size-4" /> {folder ? t('common.save') : t('notes.create_folder')}
                </Button>
            }
        >
            <form id="folder-form" onSubmit={submit}>
                <Input
                    autoFocus
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder={t('notes.folder_name_placeholder')}
                    className="text-base"
                />
                <InputError message={errors.name} className="mt-2" />
            </form>
        </ResponsiveModal>
    );
}
