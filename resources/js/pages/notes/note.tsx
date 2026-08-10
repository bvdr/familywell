import { Head, router } from '@inertiajs/react';
import { Check, FolderInput, Lock, MoreHorizontal, Palette, Pin, Trash2, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { MarkdownEditor } from '@/components/notes/markdown-editor';
import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { NOTE_COLOR_KEYS, NOTE_SWATCHES, noteColor } from '@/lib/notes';
import { type BreadcrumbItem } from '@/types';

type Folder = { id: number; name: string; color: string };
type Note = {
    id: number;
    title: string;
    body: string;
    color: string;
    pinned: boolean;
    folderId: number | null;
    visibility: string;
    canEdit: boolean;
};

export default function NoteDetail({ note, folders, share }: { note: Note; folders: Folder[]; share: ShareState }) {
    const t = useT();
    const [title, setTitle] = useState(note.title);
    const [sharing, setSharing] = useState(false);
    const titleRef = useRef<HTMLTextAreaElement>(null);

    // The title is a textarea so a long title wraps to multiple lines; keep its height to content.
    const growTitle = () => {
        const el = titleRef.current;
        if (el) {
            el.style.height = 'auto';
            el.style.height = `${el.scrollHeight}px`;
        }
    };
    useEffect(growTitle, []); // size a long title on first load

    // Debounced auto-save: accumulate changed fields, flush after a pause / on leave.
    type Payload = Record<string, string | number | boolean | null>;
    const pending = useRef<Payload>({});
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const flush = () => {
        clearTimeout(timer.current);
        if (Object.keys(pending.current).length === 0) return;
        const data = pending.current;
        pending.current = {};
        router.patch(route('notes.update', note.id), data, { preserveScroll: true, preserveState: true });
    };
    const queue = (data: Payload) => {
        pending.current = { ...pending.current, ...data };
        clearTimeout(timer.current);
        timer.current = setTimeout(flush, 600);
    };
    // Flush any unsaved edits when leaving the page.
    useEffect(() => flush, []); // eslint-disable-line react-hooks/exhaustive-deps

    // Immediate (non-text) changes save right away.
    const save = (data: Payload) => router.patch(route('notes.update', note.id), data, { preserveScroll: true, preserveState: true });

    const onTitle = (v: string) => {
        setTitle(v);
        queue({ title: v });
        growTitle();
    };
    // The WYSIWYG editor hands us markdown on every change; just debounce-save it.
    const onBody = (markdown: string) => queue({ body: markdown });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.notes'), href: '/m/notes' },
        { title: title.trim() || t('notes.untitled'), href: route('notes.show', note.id) },
    ];

    const headerActions = note.canEdit ? (
        <>
            <button
                type="button"
                onClick={() => save({ pinned: !note.pinned })}
                aria-label={note.pinned ? t('notes.unpin') : t('notes.pin')}
                className={`flex size-9 items-center justify-center rounded-lg border transition-colors ${
                    note.pinned ? 'border-primary bg-accent-subtle text-primary' : 'border-border text-primary hover:bg-surface'
                }`}
            >
                {note.pinned ? <Pin className="size-4.5 fill-current" /> : <Pin className="size-4.5" />}
            </button>
            <button
                type="button"
                onClick={() => setSharing(true)}
                aria-label={t('notes.share_note')}
                className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
            >
                <Users className="size-4.5" />
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={t('notes.note_options')}
                        className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
                    >
                        <MoreHorizontal className="size-4.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    {/* Color swatches. */}
                    <DropdownMenuLabel className="flex items-center gap-1.5 text-xs">
                        <Palette className="size-3.5" /> {t('notes.color')}
                    </DropdownMenuLabel>
                    <div className="flex flex-wrap gap-1.5 px-2 pb-1.5">
                        {NOTE_COLOR_KEYS.map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => save({ color: c })}
                                aria-label={`${c} color`}
                                className={`flex size-7 items-center justify-center rounded-full border ${NOTE_SWATCHES[c]} ${
                                    note.color === c ? 'ring-primary ring-2 ring-offset-1' : ''
                                }`}
                            >
                                {note.color === c && <Check className="size-3.5" />}
                            </button>
                        ))}
                    </div>
                    <DropdownMenuSeparator />
                    {/* Move to folder. */}
                    <DropdownMenuLabel className="flex items-center gap-1.5 text-xs">
                        <FolderInput className="size-3.5" /> {t('notes.folder')}
                    </DropdownMenuLabel>
                    <DropdownMenuItem onSelect={() => save({ folder_id: null })}>
                        {note.folderId === null && <Check className="size-4" />} {t('notes.no_folder')}
                    </DropdownMenuItem>
                    {folders.map((f) => (
                        <DropdownMenuItem key={f.id} onSelect={() => save({ folder_id: f.id })}>
                            {note.folderId === f.id && <Check className="size-4" />} {f.name}
                        </DropdownMenuItem>
                    ))}
                    <DropdownMenuSeparator />
                    {note.visibility === 'family' ? (
                        <DropdownMenuItem onSelect={() => save({ visibility: 'private' })}>
                            <Lock className="size-4" /> {t('notes.make_private')}
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => save({ visibility: 'family' })}>
                            <Users className="size-4" /> {t('notes.share_with_family')}
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        className="text-destructive focus:text-destructive"
                        onSelect={() => {
                            if (confirm(t('notes.confirm_delete_note'))) router.delete(route('notes.destroy', note.id));
                        }}
                    >
                        <Trash2 className="size-4" /> {t('notes.delete_note')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    ) : null;

    const folderName = folders.find((f) => f.id === note.folderId)?.name;

    return (
        <AppLayout breadcrumbs={breadcrumbs} actions={headerActions}>
            <Head title={title.trim() || t('notes.untitled')} />
            {/* The tint fills the viewport below the h-16 app header; ancestors don't resolve a
                height, so min-h-full alone would collapse to the content height. */}
            <div className={`min-h-[calc(100dvh-4rem)] ${noteColor(note.color)} border-0`}>
                <div className="mx-auto flex w-full max-w-2xl flex-col gap-3 p-4">
                    <textarea
                        ref={titleRef}
                        value={title}
                        onChange={(e) => onTitle(e.target.value)}
                        onBlur={flush}
                        disabled={!note.canEdit}
                        placeholder={t('notes.title_placeholder')}
                        rows={1}
                        className="font-heading text-primary w-full resize-none overflow-hidden bg-transparent text-2xl leading-tight outline-none placeholder:text-muted-foreground/50"
                    />
                    <div className="text-muted-foreground flex items-center gap-2 text-xs">
                        {folderName && <span className="bg-surface/60 rounded-full px-2 py-0.5">{folderName}</span>}
                        {note.visibility === 'private' && (
                            <span className="flex items-center gap-1">
                                <Lock className="size-3" /> {t('notes.private')}
                            </span>
                        )}
                    </div>

                    {/* Live WYSIWYG: formats as you type, reads/writes markdown. Viewers get it read-only. */}
                    <MarkdownEditor value={note.body} editable={note.canEdit} onChange={onBody} onBlur={flush} />
                </div>
            </div>

            {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={title.trim() || t('notes.untitled')} />}
        </AppLayout>
    );
}
