import { Link, router } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import { useRef, useState } from 'react';

import { Picker } from '@/components/picker';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { appLocale, useT } from '@/lib/i18n';
import { save } from '@/lib/save';

// One of the lists a to-do can go in (TaskList::pickerChoices; only the fields this sheet reads).
export type TodoList = { value: string; label: string };

const LAST_KEY = 'familywell:calendarTodoList';

// Right-click › To-do due <day> on the calendar (design 04b D3): a title and a list, due that day. A to-do lives in a
// list (tasks.tasks.store), so the lists load when the sheet first opens (the calendar's optional `todoLists` prop) and
// the one used last on this device is picked again. ponytail: title + list only; who / note / repeat are in the list's
// own editor once it's added, add them here if people ask.
export function TodoSheet({ day, onClose, lists }: { day: string | null; onClose: () => void; lists: TodoList[] | undefined }) {
    const t = useT();
    const [title, setTitle] = useState('');
    const [picked, setPicked] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const titleRef = useRef<HTMLInputElement>(null);

    // Reset on every open (React's "adjust state on prop change", no effect), and ask for the lists the first time.
    const [was, setWas] = useState<string | null>(null);
    if (day !== was) {
        setWas(day);
        if (day) {
            setTitle('');
            setPicked(null);
            if (!lists) router.reload({ only: ['todoLists'] });
        }
    }

    const remembered = (() => {
        try {
            return localStorage.getItem(LAST_KEY);
        } catch {
            return null;
        }
    })();
    // The pick, else the last list used here (if it's still there), else the first.
    const list = picked ?? (lists?.some((l) => l.value === remembered) ? remembered : (lists?.[0]?.value ?? null));
    const due = day ? new Date(`${day}T12:00`).toLocaleDateString(appLocale(), { weekday: 'short', month: 'short', day: 'numeric' }) : '';

    const add = () => {
        if (!day || !list || !title.trim() || saving) return;
        setSaving(true);
        try {
            localStorage.setItem(LAST_KEY, list);
        } catch {
            /* private mode: not remembered */
        }
        // lib/save reloads the calendar's items after, so the new to-do shows on its day.
        void save('post', route('tasks.tasks.store', list), { title: title.trim(), due_date: day }).then((r) => {
            setSaving(false);
            if (r === 'ok') onClose();
        });
    };

    return (
        <ResponsiveModal
            open={!!day}
            onOpenChange={(v) => !v && onClose()}
            title={t('calendar.todo_new')}
            autoFocusRef={titleRef}
            footer={
                lists?.length !== 0 && (
                    <button
                        type="button"
                        disabled={saving || !title.trim() || !list}
                        onClick={add}
                        className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
                    >
                        {t('calendar.todo_add')}
                    </button>
                )
            }
        >
            {lists?.length === 0 ? (
                // No list to put it in yet: Tasks is where lists are made.
                <Link href="/m/tasks?new=1" className="text-primary flex items-center gap-3 rounded-xl border p-3 text-base font-medium">
                    <ListChecks className="size-5" />
                    {t('calendar.todo_no_lists')}
                </Link>
            ) : (
                <div className="flex flex-col gap-3">
                    <Input
                        ref={titleRef}
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && add()}
                        placeholder={t('calendar.todo_placeholder')}
                        aria-label={t('calendar.todo_placeholder')}
                        className="text-base md:text-sm"
                    />
                    <Picker
                        ariaLabel={t('calendar.todo_list')}
                        placeholder={t('calendar.todo_list')}
                        fallbackIcon={ListChecks}
                        options={lists ?? []}
                        selected={list ? [list] : []}
                        onChange={(next) => next[0] && setPicked(next[0])}
                        disabled={!lists}
                    />
                    <p className="text-muted-foreground text-sm">{t('calendar.todo_due', { date: due })}</p>
                </div>
            )}
        </ResponsiveModal>
    );
}
