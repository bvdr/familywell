import { Head, router } from '@inertiajs/react';
import { Bold, Check, CheckSquare, FolderInput, Heading, List, Lock, MoreHorizontal, Palette, Pencil, Pin, Trash2, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { ShareSheet, type ShareState } from '@/components/share-sheet';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { NoteBody, NOTE_COLOR_KEYS, NOTE_SWATCHES, noteColor, toggleCheckbox } from '@/lib/notes';
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
    const [title, setTitle] = useState(note.title);
    const [body, setBody] = useState(note.body);
    const [mode, setMode] = useState<'read' | 'edit'>(note.body.trim() === '' && note.canEdit ? 'edit' : 'read');
    const [sharing, setSharing] = useState(false);
    const bodyRef = useRef<HTMLTextAreaElement>(null);

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
    };
    const onBody = (v: string) => {
        setBody(v);
        queue({ body: v });
    };
    // Tapping a checkbox in read mode toggles it and saves immediately.
    const onToggleCheckbox = (line: number) => {
        if (!note.canEdit) return;
        const next = toggleCheckbox(body, line);
        setBody(next);
        save({ body: next });
    };

    // --- Toolbar + auto-format (operate on the textarea around the caret) ---
    // Commit a new body and restore the caret after React re-renders the controlled textarea.
    const applyBody = (next: string, caret: number) => {
        setBody(next);
        queue({ body: next });
        requestAnimationFrame(() => {
            const el = bodyRef.current;
            if (el) {
                el.focus();
                el.setSelectionRange(caret, caret);
            }
        });
    };

    // Wrap the selection in `mark` on both sides (** for bold); with no selection, drop the caret
    // between the inserted pair.
    const wrap = (mark: string) => {
        const el = bodyRef.current;
        if (!el) return;
        const { selectionStart: s, selectionEnd: e } = el;
        const sel = body.slice(s, e);
        const next = body.slice(0, s) + mark + sel + mark + body.slice(e);
        applyBody(next, (sel ? e : s) + mark.length);
    };

    // Toggle a line prefix (# , - , - [ ] ) on the caret's line; swaps out any existing list/heading
    // marker so they never stack.
    const linePrefix = (prefix: string) => {
        const el = bodyRef.current;
        if (!el) return;
        const s = el.selectionStart;
        const lineStart = body.lastIndexOf('\n', s - 1) + 1;
        const lineEndRaw = body.indexOf('\n', s);
        const lineEnd = lineEndRaw === -1 ? body.length : lineEndRaw;
        const line = body.slice(lineStart, lineEnd);
        const stripped = line.replace(/^(#{1,3} |- \[[ xX]\] |[-*] )/, '');
        const newLine = line.startsWith(prefix) ? stripped : prefix + stripped;
        const next = body.slice(0, lineStart) + newLine + body.slice(lineEnd);
        applyBody(next, Math.max(lineStart, s + (newLine.length - line.length)));
    };

    // Enter on a list/checkbox line continues it; Enter on an empty item exits the list.
    const onBodyKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key !== 'Enter' || e.shiftKey) return;
        const el = bodyRef.current;
        if (!el || el.selectionStart !== el.selectionEnd) return;
        const s = el.selectionStart;
        const lineStart = body.lastIndexOf('\n', s - 1) + 1;
        const m = body.slice(lineStart, s).match(/^(\s*)(- \[[ xX]\] |[-*] )(.*)$/);
        if (!m) return;
        const [, indent, marker, content] = m;
        e.preventDefault();
        if (content.trim() === '') {
            // Empty item -> clear the marker (leave the indent), exiting the list.
            applyBody(body.slice(0, lineStart) + indent + body.slice(s), lineStart + indent.length);
        } else {
            // A continued checkbox always starts unchecked.
            const insert = '\n' + indent + (marker.startsWith('- [') ? '- [ ] ' : marker);
            applyBody(body.slice(0, s) + insert + body.slice(s), s + insert.length);
        }
    };

    const toEdit = () => {
        if (!note.canEdit) return;
        setMode('edit');
        setTimeout(() => bodyRef.current?.focus(), 0);
    };
    const toRead = () => {
        flush();
        setMode('read');
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Notes', href: '/m/notes' },
        { title: title.trim() || 'Note', href: route('notes.show', note.id) },
    ];

    const headerActions = note.canEdit ? (
        <>
            <button
                type="button"
                onClick={() => save({ pinned: !note.pinned })}
                aria-label={note.pinned ? 'Unpin note' : 'Pin note'}
                className={`flex size-9 items-center justify-center rounded-lg border transition-colors ${
                    note.pinned ? 'border-primary bg-accent-subtle text-primary' : 'border-border text-primary hover:bg-surface'
                }`}
            >
                {note.pinned ? <Pin className="size-4.5 fill-current" /> : <Pin className="size-4.5" />}
            </button>
            <button
                type="button"
                onClick={() => setSharing(true)}
                aria-label="Share note"
                className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
            >
                <Users className="size-4.5" />
            </button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label="Note options"
                        className="border-border text-primary hover:bg-surface flex size-9 items-center justify-center rounded-lg border transition-colors"
                    >
                        <MoreHorizontal className="size-4.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    {/* Color swatches. */}
                    <DropdownMenuLabel className="flex items-center gap-1.5 text-xs">
                        <Palette className="size-3.5" /> Color
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
                        <FolderInput className="size-3.5" /> Folder
                    </DropdownMenuLabel>
                    <DropdownMenuItem onSelect={() => save({ folder_id: null })}>
                        {note.folderId === null && <Check className="size-4" />} No folder
                    </DropdownMenuItem>
                    {folders.map((f) => (
                        <DropdownMenuItem key={f.id} onSelect={() => save({ folder_id: f.id })}>
                            {note.folderId === f.id && <Check className="size-4" />} {f.name}
                        </DropdownMenuItem>
                    ))}
                    <DropdownMenuSeparator />
                    {note.visibility === 'family' ? (
                        <DropdownMenuItem onSelect={() => save({ visibility: 'private' })}>
                            <Lock className="size-4" /> Make private
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => save({ visibility: 'family' })}>
                            <Users className="size-4" /> Share with family
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        className="text-destructive focus:text-destructive"
                        onSelect={() => {
                            if (confirm('Delete this note?')) router.delete(route('notes.destroy', note.id));
                        }}
                    >
                        <Trash2 className="size-4" /> Delete note
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    ) : null;

    // A format button. onMouseDown-preventDefault keeps focus (and the caret) in the textarea so the
    // command applies where the user was typing instead of losing the selection on click.
    const ToolBtn = ({ label, onClick, children }: { label: string; onClick: () => void; children: React.ReactNode }) => (
        <button
            type="button"
            aria-label={label}
            onMouseDown={(e) => e.preventDefault()}
            onClick={onClick}
            className="text-primary hover:bg-surface flex size-8 items-center justify-center rounded-lg transition-colors"
        >
            {children}
        </button>
    );

    const folderName = folders.find((f) => f.id === note.folderId)?.name;

    return (
        <AppLayout breadcrumbs={breadcrumbs} actions={headerActions}>
            <Head title={title.trim() || 'Note'} />
            {/* The tint fills the viewport below the h-16 app header; ancestors don't resolve a
                height, so min-h-full alone would collapse to the content height. */}
            <div className={`min-h-[calc(100dvh-4rem)] ${noteColor(note.color)} border-0`}>
                <div className="mx-auto flex w-full max-w-2xl flex-col gap-3 p-4">
                    <input
                        value={title}
                        onChange={(e) => onTitle(e.target.value)}
                        onBlur={flush}
                        disabled={!note.canEdit}
                        placeholder="Title"
                        className="font-heading text-primary w-full bg-transparent text-2xl outline-none placeholder:text-muted-foreground/50"
                    />
                    <div className="text-muted-foreground flex items-center gap-2 text-xs">
                        {folderName && <span className="bg-surface/60 rounded-full px-2 py-0.5">{folderName}</span>}
                        {note.visibility === 'private' && (
                            <span className="flex items-center gap-1">
                                <Lock className="size-3" /> Private
                            </span>
                        )}
                        {note.canEdit && (
                            <button
                                type="button"
                                onClick={mode === 'read' ? toEdit : toRead}
                                className="text-primary ml-auto flex items-center gap-1 font-medium"
                            >
                                {mode === 'read' ? (
                                    <>
                                        <Pencil className="size-3.5" /> Edit
                                    </>
                                ) : (
                                    <>
                                        <Check className="size-3.5" /> Done
                                    </>
                                )}
                            </button>
                        )}
                    </div>

                    {mode === 'edit' ? (
                        <>
                            {/* Format toolbar: acts on the caret/selection. Auto-continue on Enter is
                                handled in onBodyKeyDown, so the markdown stays the source of truth. */}
                            <div className="border-border/60 -mx-1 flex items-center gap-1 border-b pb-2">
                                <ToolBtn label="Bold" onClick={() => wrap('**')}>
                                    <Bold className="size-4" />
                                </ToolBtn>
                                <ToolBtn label="Heading" onClick={() => linePrefix('# ')}>
                                    <Heading className="size-4" />
                                </ToolBtn>
                                <ToolBtn label="Bullet list" onClick={() => linePrefix('- ')}>
                                    <List className="size-4" />
                                </ToolBtn>
                                <ToolBtn label="Checkbox" onClick={() => linePrefix('- [ ] ')}>
                                    <CheckSquare className="size-4" />
                                </ToolBtn>
                            </div>
                            <textarea
                                ref={bodyRef}
                                value={body}
                                onChange={(e) => onBody(e.target.value)}
                                onKeyDown={onBodyKeyDown}
                                onBlur={flush}
                                placeholder={'Start typing…\n\n# Heading\n**bold**\n- bullet\n- [ ] to-do'}
                                className="text-primary min-h-[60vh] w-full resize-none bg-transparent text-[15px] leading-relaxed outline-none placeholder:text-muted-foreground/50"
                            />
                        </>
                    ) : (
                        <div onClick={note.canEdit ? toEdit : undefined} className={note.canEdit ? 'cursor-text' : ''}>
                            <NoteBody body={body} onToggle={onToggleCheckbox} />
                        </div>
                    )}
                </div>
            </div>

            {share && <ShareSheet open={sharing} onOpenChange={setSharing} share={share} eventName={title.trim() || 'Note'} />}
        </AppLayout>
    );
}
