import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, StickyNote, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import { relativeTime } from '@/lib/notifications';
import { mileageText, vehicleTitle, type VehicleHeader, type VehicleNote } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

import VehicleNav from '../vehicle-nav';

type Props = {
    vehicle: VehicleHeader;
    notes: VehicleNote[];
    canManage: boolean;
};

export default function VehicleNotes({ vehicle, notes, canManage }: Props) {
    const t = useT();
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    // Optimistic removal: a deleted note disappears immediately, reappearing only if the delete fails.
    const removed = useOptimistic<boolean>();
    const visibleNotes = notes.filter((n) => !removed.get(n.id, false));
    const removeNote = (note: VehicleNote) => {
        if (!confirm(t('vehicles.note_delete_confirm'))) return;
        removed.set(note.id, true);
        router.delete(`/m/vehicles/${vehicle.id}/notes/${note.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => removed.clear(note.id),
            onError: () => removed.clear(note.id),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
        { title: t('vehicles.notes_section'), href: `/m/vehicles/${vehicle.id}/notes` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${vehicleTitle(vehicle)} · ${t('vehicles.notes_section')}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex flex-col gap-1">
                    <h1 className="font-heading text-primary text-2xl">{vehicleTitle(vehicle)}</h1>
                    <p className="text-muted-foreground text-sm tabular-nums">{mileageText(t, vehicle.currentMileage)}</p>
                </header>

                <VehicleNav vehicleId={vehicle.id} active="notes" />

                <div className="flex items-center justify-between gap-3">
                    <h2 className="font-heading text-primary text-xl">{t('vehicles.notes_section')}</h2>
                    {canManage && (
                        <Button type="button" onClick={() => setAdding(true)}>
                            <Plus className="size-4" /> {t('vehicles.add_note')}
                        </Button>
                    )}
                </div>

                {/* Notes reuse the Notes module; without it enabled we still list existing notes read-only. */}
                {!canManage && <p className="text-muted-foreground bg-surface/60 rounded-xl border border-dashed px-4 py-3 text-sm">{t('vehicles.notes_module_off')}</p>}

                {visibleNotes.length === 0 ? (
                    <EmptyState t={t} />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {visibleNotes.map((n) => (
                            <NoteCard key={n.id} note={n} canManage={canManage} t={t} onEdit={() => setEditingId(n.id)} onRemove={() => removeNote(n)} />
                        ))}
                    </ul>
                )}
            </div>

            <NoteFormModal
                open={adding || editingId !== null}
                onOpenChange={(v) => {
                    if (!v) {
                        setAdding(false);
                        setEditingId(null);
                    }
                }}
                note={editingId !== null ? (notes.find((n) => n.id === editingId) ?? null) : null}
                vehicleId={vehicle.id}
                t={t}
            />
        </AppLayout>
    );
}

function EmptyState({ t }: { t: TFn }) {
    return (
        <div className="text-muted-foreground bg-surface-card flex flex-col items-center gap-2 rounded-2xl border px-4 py-10 text-center">
            <StickyNote className="size-8 opacity-40" />
            <p className="text-primary font-medium">{t('vehicles.notes_empty_title')}</p>
            <p className="max-w-sm text-sm">{t('vehicles.notes_empty_body')}</p>
        </div>
    );
}

function NoteCard({ note, canManage, t, onEdit, onRemove }: { note: VehicleNote; canManage: boolean; t: TFn; onEdit: () => void; onRemove: () => void }) {
    return (
        <li className="bg-surface-card flex flex-col gap-1.5 rounded-2xl border p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    {note.title ? <h3 className="text-primary font-medium">{note.title}</h3> : <h3 className="text-muted-foreground font-medium italic">{t('vehicles.untitled_note')}</h3>}
                    {note.updatedAt && <p className="text-muted-foreground text-xs">{relativeTime(note.updatedAt)}</p>}
                </div>
                {canManage && (
                    <div className="flex shrink-0 items-center gap-1">
                        <IconButton label={t('vehicles.edit')} onClick={onEdit}>
                            <Pencil className="size-4" />
                        </IconButton>
                        <IconButton label={t('vehicles.delete')} onClick={onRemove}>
                            <Trash2 className="size-4" />
                        </IconButton>
                    </div>
                )}
            </div>
            {note.body && <p className="text-text-secondary text-sm whitespace-pre-wrap">{note.body}</p>}
        </li>
    );
}

// Add / Edit note. One modal for both: pass a `note` to edit, `null` to add. Self-contained — re-seeds
// its own local form state whenever it opens, mirroring the ItemFormModal pattern (inventory-modals.tsx).
function NoteFormModal({
    open,
    onOpenChange,
    note,
    vehicleId,
    t,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    note: VehicleNote | null;
    vehicleId: number;
    t: TFn;
}) {
    const editing = note !== null;
    const titleRef = useRef<HTMLInputElement>(null);
    const [form, setForm] = useState({ title: '', body: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    // Re-seed on open (not on every keystroke) so Add starts blank and Edit reflects the current note,
    // even after a prior edit of a different note.
    useEffect(() => {
        if (open) {
            setForm({ title: note?.title ?? '', body: note?.body ?? '' });
            setErrors({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, note?.id]);

    const set = (k: keyof typeof form, v: string) => setForm((p) => ({ ...p, [k]: v }));

    const submit = () => {
        setProcessing(true);
        const url = editing ? `/m/vehicles/${vehicleId}/notes/${note!.id}` : `/m/vehicles/${vehicleId}/notes`;
        const method = editing ? router.patch : router.post;
        method(url, form, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? t('vehicles.edit_note') : t('vehicles.add_note')}
            autoFocusRef={titleRef}
            footer={
                <Button type="button" onClick={submit} disabled={processing || form.body.trim() === ''} className="w-full">
                    {t('vehicles.save')}
                </Button>
            }
        >
            <div className="flex flex-col gap-4 pb-1">
                <Field label={t('vehicles.note_title_label')} error={errors.title}>
                    <Input
                        ref={titleRef}
                        value={form.title}
                        onChange={(e) => set('title', e.target.value)}
                        placeholder={t('vehicles.note_title_placeholder')}
                        className="text-base"
                    />
                </Field>

                <Field label={t('vehicles.note_body_label')} error={errors.body}>
                    <textarea
                        value={form.body}
                        onChange={(e) => set('body', e.target.value)}
                        rows={4}
                        className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                    />
                </Field>
            </div>
        </ResponsiveModal>
    );
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function IconButton({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            className="text-muted-foreground hover:text-primary hover:bg-surface flex size-8 items-center justify-center rounded-lg transition-colors"
        >
            {children}
        </button>
    );
}
