import { EditorContent, useEditor, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import TaskItem from '@tiptap/extension-task-item';
import TaskList from '@tiptap/extension-task-list';
import { Markdown } from 'tiptap-markdown';
import { Bold, CheckSquare, Heading, List } from 'lucide-react';
import { useRef } from 'react';
import { useT } from '@/lib/i18n';

// tiptap-markdown injects a phantom empty task list when a note mixes a bullet list and a task list.
// The stored markdown is clean, so we strip any empty list node once, right after parsing on load.
function stripEmptyLists(editor: Editor) {
    const ranges: { from: number; to: number }[] = [];
    editor.state.doc.descendants((node, pos) => {
        if (/^(taskList|bulletList|orderedList)$/.test(node.type.name) && node.textContent.trim() === '') {
            ranges.push({ from: pos, to: pos + node.nodeSize });
            return false; // don't descend into a node we're deleting
        }
    });
    if (!ranges.length) return;
    const tr = editor.state.tr;
    ranges.reverse().forEach(({ from, to }) => tr.delete(from, to)); // back-to-front keeps positions valid
    editor.view.dispatch(tr);
}

// A live (WYSIWYG) markdown editor. The document is edited as rich text but read/written as plain
// markdown (via tiptap-markdown), so the stored `body`, the index snippets and existing notes all
// keep working. `value` seeds the editor once on mount; we intentionally don't sync it back in on
// every prop change, which would fight the cursor while typing.
export function MarkdownEditor({
    value,
    editable = true,
    onChange,
    onBlur,
}: {
    value: string;
    editable?: boolean;
    onChange?: (markdown: string) => void;
    onBlur?: () => void;
}) {
    const t = useT();
    // Stays false until the initial parse + cleanup finishes, so opening a note never fires a save.
    const hydrated = useRef(false);
    const editor = useEditor({
        extensions: [
            StarterKit,
            // Clickable links, auto-detected as you type. rel=nofollow (+ noopener/noreferrer) and
            // opened in a new tab; family notes are user content, so we don't pass link equity on.
            Link.configure({
                openOnClick: true,
                autolink: true,
                defaultProtocol: 'https',
                HTMLAttributes: { rel: 'nofollow noopener noreferrer', target: '_blank' },
            }),
            TaskList,
            TaskItem.configure({ nested: false }),
            // `-` bullets + tight lists match our markdown-lite dialect (see lib/notes NoteBody).
            Markdown.configure({ html: false, tightLists: true, bulletListMarker: '-', linkify: true }),
        ],
        content: value,
        editable,
        editorProps: {
            attributes: {
                // >=16px on mobile so iOS Safari doesn't auto-zoom when the editor gets focus
                // (contenteditable follows the same rule as inputs); 15px is fine on desktop.
                class: 'note-prose min-h-[60vh] outline-none text-base md:text-[15px] leading-relaxed text-primary',
            },
        },
        onCreate: ({ editor }) => {
            stripEmptyLists(editor); // fires onUpdate, but hydrated is still false so no save
            hydrated.current = true;
        },
        onUpdate: ({ editor }) => {
            if (!hydrated.current) return;
            onChange?.(editor.storage.markdown.getMarkdown());
        },
        onBlur: () => onBlur?.(),
    });

    if (!editor) return null;

    const Btn = ({ label, active, onClick, children }: { label: string; active: boolean; onClick: () => void; children: React.ReactNode }) => (
        <button
            type="button"
            aria-label={label}
            aria-pressed={active}
            // Keep the selection in the doc when the toolbar is tapped (matters on touch).
            onMouseDown={(e) => e.preventDefault()}
            onClick={onClick}
            className={`flex size-8 items-center justify-center rounded-lg transition-colors ${
                active ? 'bg-accent-subtle text-primary' : 'text-primary hover:bg-surface'
            }`}
        >
            {children}
        </button>
    );

    return (
        <div className="flex flex-col gap-3">
            {editable && (
                <div className="border-border/60 -mx-1 flex items-center gap-1 border-b pb-2">
                    <Btn label={t('notes.bold')} active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}>
                        <Bold className="size-4" />
                    </Btn>
                    <Btn
                        label={t('notes.heading')}
                        active={editor.isActive('heading', { level: 1 })}
                        onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}
                    >
                        <Heading className="size-4" />
                    </Btn>
                    <Btn label={t('notes.bullet_list')} active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}>
                        <List className="size-4" />
                    </Btn>
                    <Btn label={t('notes.checkbox')} active={editor.isActive('taskList')} onClick={() => editor.chain().focus().toggleTaskList().run()}>
                        <CheckSquare className="size-4" />
                    </Btn>
                </div>
            )}
            <EditorContent editor={editor} />
        </div>
    );
}
