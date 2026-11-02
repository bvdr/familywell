import { router, usePage } from '@inertiajs/react';
import { ListChecks, Trash2 } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

import { When } from '@/components/add-details';
import {
    type Choice,
    emptyDraft,
    IconBrowser,
    ListPicker,
    type NewListDraft,
    NewListForm,
    newListValue,
    parseNew,
    withNew,
} from '@/components/list-picker';
import { MemberAvatar } from '@/components/member-avatar';
import { SettingsGroup, SettingsRow } from '@/components/settings-ui';
import { type SubTask, type TaskMember } from '@/components/tasks/task-tree';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { appLocale, useT } from '@/lib/i18n';
import { Slot } from '@/lib/modules';
import { type SharedData } from '@/types';

// A screen pushed into the sheet (T4 Repeat, the list picker): the header's right words and what to draw. render gets
// the sheet's draft, so a picker shows and changes what Save will send.
type View = { aside: ReactNode; render: (ctx: { task: Draft; onPatch: (p: Record<string, unknown>) => void }) => ReactNode };
type Draft = SubTask & Record<string, unknown>;

// The task sheet (design j6QDy4 T3): the same form as Add details, for a task that exists. Title and note in one box,
// When as quick picks, Who's on it as faces with names, then Repeat / Important (the add-ons' rows, through the
// task.editor.fields slot) and List; Delete and who made it at the bottom; Save sends it all in one go. Repeat and
// List push their pickers into the same sheet (T4, D-T1).
export function TaskSheet({
    task,
    members,
    family,
    list,
    taskUrl,
    onClose,
    onDelete,
}: {
    task: SubTask;
    members: TaskMember[];
    family: TaskMember[];
    list: { id: number; name: string };
    taskUrl: (id: number) => string;
    onClose: () => void;
    onDelete: () => void;
}) {
    const t = useT();
    const [draft, setDraft] = useState<Draft>({ ...task });
    // The add-ons' fields changed in the sheet ({important: true, recurrence: 'quarterly'}), sent with Save.
    const [ext, setExt] = useState<Record<string, unknown>>({});
    const [listId, setListId] = useState(String(list.id));
    // A pushed screen: an add-on's (Repeat), or the list picker's: pick (T3b), New list (T3c), every icon (T3d).
    const [view, setView] = useState<View | 'list' | 'new' | 'icons' | null>(null);
    const [lists, setLists] = useState<{ choices: Choice[]; guess: { value: string; word: string; count: number } | null } | null>(null);
    const [newList, setNewList] = useState<NewListDraft>(() => emptyDraft());
    const me = usePage<SharedData>().props.auth.user.id;
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const patch = (p: Record<string, unknown>) => {
        setDraft((d) => ({ ...d, ...p }));
        setExt((e) => ({ ...e, ...p }));
    };
    const set = (p: Partial<SubTask>) => setDraft((d) => ({ ...d, ...p }));
    const creator = family.find((m) => m.id === task.createdBy);
    const made = task.createdAt ? new Date(`${task.createdAt}T00:00:00`).toLocaleDateString(appLocale(), { month: 'short', day: 'numeric' }) : null;
    const options = withNew(lists?.choices ?? [{ value: String(list.id), label: list.name }], listId);
    const listName = options.find((o) => o.value === listId)?.label ?? list.name;

    // The picker's rows and its guess from similar tasks load when it first opens (one small request).
    useEffect(() => {
        if (view !== 'list' || lists) return;
        void fetch(route('tasks.tasks.lists', task.id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => d && setLists(d));
    }, [view, lists, task.id]);

    const save = (moveTo: string = listId) => {
        if (!draft.title.trim()) return;
        setSaving(true);
        setError(null);
        router.patch(
            taskUrl(task.id),
            {
                title: draft.title.trim(),
                note: (draft.note ?? '').trim() || null,
                due_date: draft.dueDate || null,
                assigned_to: draft.assignedTo,
                // A list made in the picker is made on Save (its name, icon, colour, who sees it); an existing one is
                // moved to.
                ...(parseNew(moveTo)
                    ? { new_list: JSON.parse(moveTo.slice(4)) }
                    : moveTo !== String(list.id)
                      ? { task_list_id: Number(moveTo) }
                      : {}),
                ...(ext as Record<string, string | number | boolean | null>),
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
                // A 4th important star, a list gone: say why and stay.
                onError: (errors) => setError(Object.values(errors)[0] ?? t('tasks.save_failed')),
                onFinish: () => setSaving(false),
            },
        );
    };

    // Each list screen steps back one: icons → New list → Lists → the task.
    const listStage = typeof view === 'string' ? view : null;
    const back = { list: [null, t('tasks.edit_task')], new: ['list', t('add_details.lists')], icons: ['new', t('add_details.new_list')] } as const;
    const aside = { list: t('add_details.f_list_id'), new: t('add_details.new_list'), icons: t('add_details.icon') };
    return (
        <ResponsiveModal
            open
            onOpenChange={(v) => !v && onClose()}
            title={t('tasks.edit_task')}
            onBack={view ? () => setView(listStage ? back[listStage][0] : null) : undefined}
            backLabel={listStage ? back[listStage][1] : t('tasks.edit_task')}
            aside={
                view && (
                    <span className="text-muted-foreground font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                        {listStage ? aside[listStage] : (view as View).aside}
                    </span>
                )
            }
            footer={
                view ? (
                    !listStage && (
                        <button
                            type="button"
                            onClick={() => setView(null)}
                            className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold"
                        >
                            {t('common.done')}
                        </button>
                    )
                ) : (
                    <button
                        type="button"
                        onClick={() => save()}
                        disabled={saving || !draft.title.trim()}
                        className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
                    >
                        {t('common.save')}
                    </button>
                )
            }
        >
            {listStage === 'list' ? (
                <ListPicker
                    options={options}
                    value={listId}
                    guess={lists?.guess}
                    onPick={(v) => {
                        setListId(v);
                        setView(null);
                    }}
                    onNew={(name) => {
                        setNewList(parseNew(listId) ?? emptyDraft(name));
                        setView('new');
                    }}
                    newSub={t('add_details.new_list_sub_task')}
                />
            ) : listStage === 'new' ? (
                // One button makes the list and moves the task in: it saves the whole sheet.
                <NewListForm
                    draft={newList}
                    setDraft={setNewList}
                    note={t('add_details.moves_here', { title: draft.title, from: list.name })}
                    submitLabel={t('add_details.create_and_move')}
                    onMoreIcons={() => setView('icons')}
                    busy={saving}
                    onSubmit={() => save(newListValue(newList))}
                />
            ) : listStage === 'icons' ? (
                <IconBrowser
                    name={newList.name}
                    value={newList.icon}
                    onPick={(icon) => {
                        setNewList({ ...newList, icon, iconPicked: true });
                        setView('new');
                    }}
                />
            ) : view ? (
                (view as View).render({ task: draft, onPatch: patch })
            ) : (
                <div className="flex flex-col gap-5">
                    <div className="bg-surface flex flex-col gap-1 rounded-2xl px-4 py-3">
                        <input
                            value={draft.title}
                            onChange={(e) => set({ title: e.target.value })}
                            aria-label={t('tasks.field_title')}
                            // 16px+ so iOS doesn't zoom in on focus.
                            className="text-primary w-full bg-transparent text-xl font-semibold outline-none"
                        />
                        <textarea
                            value={draft.note ?? ''}
                            onChange={(e) => set({ note: e.target.value })}
                            rows={1}
                            placeholder={t('add_details.note_placeholder')}
                            aria-label={t('tasks.field_note')}
                            className="text-muted-foreground placeholder:text-muted-foreground field-sizing-content w-full resize-none bg-transparent text-base outline-none"
                        />
                    </div>

                    <When value={draft.dueDate ?? ''} onChange={(v) => set({ dueDate: v || null })} />

                    {/* Who's on it (T3): faces with names, "You" for you; swipes sideways when they don't fit. */}
                    <section className="flex flex-col gap-2">
                        <h3 className="text-primary text-sm font-semibold">{t('add_details.who_on_it')}</h3>
                        <div data-scroll-x className="-mx-5 flex gap-2 overflow-x-auto px-5 [scrollbar-width:none] md:mx-0 md:px-0 [&>*]:shrink-0">
                            {[null, ...members.map((m) => m.id)].map((id) => {
                                const m = members.find((x) => x.id === id);
                                const on = draft.assignedTo === id;
                                return (
                                    <button
                                        key={id ?? 'anyone'}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => set({ assignedTo: id })}
                                        className={`flex min-h-10 items-center gap-1.5 rounded-full border py-1 text-sm font-semibold ${m ? 'pr-3.5 pl-1' : 'px-4'} ${on ? 'bg-primary border-primary text-primary-foreground' : 'bg-surface-card text-primary'}`}
                                    >
                                        {m && <MemberAvatar userId={m.id} name={m.name} className="size-7 text-xs" />}
                                        {m ? (m.id === me ? t('tasks.you') : m.name.split(' ')[0]) : t('add_details.anyone')}
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    <SettingsGroup>
                        {/* Repeat and Important: the add-ons' own rows. push lets Repeat open its picker in this sheet. */}
                        <Slot name="task.editor.fields" ctx={{ task: draft, onPatch: patch, variant: 'row', push: (v: View) => setView(v) }} />
                        <SettingsRow
                            icon={ListChecks}
                            title={t('add_details.f_list_id')}
                            value={<span className="text-primary font-semibold">{listName}</span>}
                            onClick={() => setView('list')}
                            className="order-2"
                        />
                    </SettingsGroup>

                    {error && (
                        <p role="alert" className="text-destructive text-sm">
                            {error}
                        </p>
                    )}

                    <div className="flex items-center justify-between gap-3">
                        <button
                            type="button"
                            onClick={() => {
                                onDelete();
                                onClose();
                            }}
                            className="text-destructive flex min-h-11 items-center gap-2 text-base font-semibold"
                        >
                            <Trash2 className="size-5" />
                            {t('tasks.delete_task')}
                        </button>
                        {creator && made && (
                            <span className="text-muted-foreground text-right text-sm">
                                {t('tasks.created_by', { name: creator.name.split(' ')[0], date: made })}
                            </span>
                        )}
                    </div>
                </div>
            )}
        </ResponsiveModal>
    );
}
