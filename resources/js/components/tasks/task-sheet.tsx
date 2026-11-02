import { router, usePage } from '@inertiajs/react';
import { ListChecks, Trash2 } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { ListPicker, When } from '@/components/add-details';
import { type Detail } from '@/components/add-just-say-it';
import { MemberAvatar } from '@/components/member-avatar';
import { SettingsGroup, SettingsRow } from '@/components/settings-ui';
import { type SubTask, type TaskMember } from '@/components/tasks/task-tree';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { appLocale, useT } from '@/lib/i18n';
import { Slot } from '@/lib/modules';
import { type SharedData } from '@/types';

type Choice = NonNullable<Detail['choices']>[number];
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
    lists,
    taskUrl,
    onClose,
    onDelete,
}: {
    task: SubTask;
    members: TaskMember[];
    family: TaskMember[];
    list: { id: number; name: string };
    lists: Choice[] | undefined;
    taskUrl: (id: number) => string;
    onClose: () => void;
    onDelete: () => void;
}) {
    const t = useT();
    const [draft, setDraft] = useState<Draft>({ ...task });
    // The add-ons' fields changed in the sheet ({important: true, recurrence: 'quarterly'}), sent with Save.
    const [ext, setExt] = useState<Record<string, unknown>>({});
    const [listId, setListId] = useState(String(list.id));
    // A pushed screen: an add-on's (Repeat) or the list picker.
    const [view, setView] = useState<View | 'list' | null>(null);
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
    const options = lists ?? [{ value: String(list.id), label: list.name }];
    const listName = options.find((o) => o.value === listId)?.label ?? list.name;

    const openLists = () => {
        if (!lists) router.reload({ only: ['lists'] }); // the picker's rows load on first open
        setView('list');
    };
    const save = () => {
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
                ...(listId !== String(list.id) ? { task_list_id: Number(listId) } : {}),
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

    const picking = view === 'list';
    return (
        <ResponsiveModal
            open
            onOpenChange={(v) => !v && onClose()}
            title={t('tasks.edit_task')}
            onBack={view ? () => setView(null) : undefined}
            backLabel={t('tasks.edit_task')}
            aside={
                view && (
                    <span className="text-muted-foreground font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                        {picking ? t('add_details.f_list_id') : view.aside}
                    </span>
                )
            }
            footer={
                view ? (
                    !picking && (
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
                        onClick={save}
                        disabled={saving || !draft.title.trim()}
                        className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
                    >
                        {t('common.save')}
                    </button>
                )
            }
        >
            {picking ? (
                <ListPicker
                    options={options}
                    value={listId}
                    suggested={list.name}
                    // Moving a task needs a list that exists: lists are made on the Tasks page.
                    canCreate={false}
                    onPick={(v) => {
                        setListId(v);
                        setView(null);
                    }}
                />
            ) : view ? (
                view.render({ task: draft, onPatch: patch })
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
                            onClick={openLists}
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
