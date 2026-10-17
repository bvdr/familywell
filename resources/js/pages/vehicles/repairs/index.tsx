import { Head, router } from '@inertiajs/react';
import { FileText, ImageIcon, Paperclip, Pencil, Plus, Trash2, Wrench, X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn, dayText } from '@/lib/i18n';
import { fmtMileage, mileageText, vehicleTitle, type ProviderOption, type Repair, type RepairAttachment, type VehicleHeader, useCarMoney } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

import ProviderPicker from '../provider-picker';
import VehicleNav from '../vehicle-nav';

type Props = {
    vehicle: VehicleHeader;
    repairs: Repair[];
    canExpense: boolean;
    providers: ProviderOption[];
};

export default function VehicleRepairs({ vehicle, repairs, canExpense, providers }: Props) {
    const t = useT();
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    // Optimistic removal: a deleted repair disappears immediately, reappearing only if the delete fails.
    const removed = useOptimistic<boolean>();
    const visibleRepairs = repairs.filter((r) => !removed.get(r.id, false));
    const removeRepair = (repair: Repair) => {
        if (!confirm(t('vehicles.repair_delete_confirm'))) return;
        removed.set(repair.id, true);
        router.delete(`/m/vehicles/${vehicle.id}/repairs/${repair.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => removed.clear(repair.id),
            onError: () => removed.clear(repair.id),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
        { title: t('vehicles.repairs'), href: `/m/vehicles/${vehicle.id}/repairs` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${vehicleTitle(vehicle)} · ${t('vehicles.repairs')}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex flex-col gap-1">
                    <h1 className="font-heading text-primary text-2xl">{vehicleTitle(vehicle)}</h1>
                    <p className="text-muted-foreground text-sm tabular-nums">{mileageText(t, vehicle.currentMileage)}</p>
                </header>

                <VehicleNav vehicleId={vehicle.id} active="repairs" />

                <div className="flex items-center justify-between gap-3">
                    <h2 className="font-heading text-primary text-xl">{t('vehicles.repairs')}</h2>
                    <Button type="button" onClick={() => setAdding(true)}>
                        <Plus className="size-4" /> {t('vehicles.add_repair')}
                    </Button>
                </div>

                {visibleRepairs.length === 0 ? (
                    <EmptyState t={t} />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {visibleRepairs.map((r) => (
                            <RepairCard key={r.id} repair={r} t={t} onEdit={() => setEditingId(r.id)} onRemove={() => removeRepair(r)} />
                        ))}
                    </ul>
                )}
            </div>

            {/* One shared add/edit sheet, mounted once. Add opens it blank; a row's edit opens it seeded. */}
            <RepairFormModal
                open={adding || editingId !== null}
                onOpenChange={(v) => {
                    if (!v) {
                        setAdding(false);
                        setEditingId(null);
                    }
                }}
                vehicle={vehicle}
                repair={editingId !== null ? (repairs.find((r) => r.id === editingId) ?? null) : null}
                canExpense={canExpense}
                providers={providers}
                t={t}
            />
        </AppLayout>
    );
}

function EmptyState({ t }: { t: TFn }) {
    return (
        <div className="text-muted-foreground bg-surface-card flex flex-col items-center gap-2 rounded-2xl border px-4 py-10 text-center">
            <Wrench className="size-8 opacity-40" />
            <p className="text-primary font-medium">{t('vehicles.repairs_empty_title')}</p>
            <p className="max-w-sm text-sm">{t('vehicles.repairs_empty_body')}</p>
        </div>
    );
}

// One repair record: what/when/mileage/cost/garage, its notes, and any attachments as tappable chips.
function RepairCard({ repair, t, onEdit, onRemove }: { repair: Repair; t: TFn; onEdit: () => void; onRemove: () => void }) {
    const carMoney = useCarMoney();
    return (
        <li className="bg-surface-card flex flex-col gap-2 rounded-2xl border p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-primary font-medium">{repair.title}</h3>
                    <p className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm">
                        {repair.occurredOn && <span>{dayText(repair.occurredOn)}</span>}
                        {repair.mileage != null && <span className="tabular-nums">{mileageText(t, repair.mileage)}</span>}
                        {repair.cost != null && <span className="tabular-nums">{carMoney(repair.cost)}</span>}
                        {repair.provider && <span>{repair.provider}</span>}
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                    <IconButton label={t('vehicles.edit')} onClick={onEdit}>
                        <Pencil className="size-4" />
                    </IconButton>
                    <IconButton label={t('vehicles.delete')} onClick={onRemove}>
                        <Trash2 className="size-4" />
                    </IconButton>
                </div>
            </div>

            {repair.notes && <p className="text-text-secondary text-sm whitespace-pre-wrap">{repair.notes}</p>}

            {repair.attachments.length > 0 && (
                <div className="flex flex-wrap gap-2 pt-1">
                    {repair.attachments.map((a) => (
                        <AttachmentChip key={a.id} attachment={a} t={t} />
                    ))}
                </div>
            )}
        </li>
    );
}

// A tappable attachment: opens the file in a new tab. Shows the filename (or a generic label) with an icon.
function AttachmentChip({ attachment, t }: { attachment: RepairAttachment; t: TFn }) {
    const isPdf = (attachment.name ?? '').toLowerCase().endsWith('.pdf');
    return (
        <a
            href={attachment.url}
            target="_blank"
            rel="noreferrer"
            className="border-border text-primary hover:bg-surface flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors"
        >
            {isPdf ? <FileText className="size-3.5" /> : <ImageIcon className="size-3.5" />}
            <span className="max-w-[12rem] truncate">{attachment.name || t('vehicles.attachment')}</span>
        </a>
    );
}

// The initial form state for a repair (blank for Add, seeded from the record for Edit).
function initialRepairForm(repair: Repair | null) {
    return {
        title: repair?.title ?? '',
        occurred_on: repair?.occurredOn ?? todayIso(),
        mileage: repair?.mileage != null ? String(repair.mileage) : '',
        cost: repair?.cost != null ? String(repair.cost) : '',
        // When a provider is linked, the picker drives it by id — don't prefill free text with its name.
        provider: repair?.serviceProviderId != null ? '' : (repair?.provider ?? ''),
        service_provider_id: repair?.serviceProviderId ?? null,
        notes: repair?.notes ?? '',
        create_expense: false,
    };
}

// The add/edit sheet (shared ResponsiveModal). Uploads new files as multipart; on edit, lets existing
// attachments be removed. On add, a costed repair can optionally be mirrored into Spending (spec 11).
function RepairFormModal({
    open,
    onOpenChange,
    vehicle,
    repair,
    canExpense,
    providers,
    t,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    vehicle: VehicleHeader;
    repair: Repair | null;
    canExpense: boolean;
    providers: ProviderOption[];
    t: TFn;
}) {
    const editing = repair !== null;
    const [form, setForm] = useState(() => initialRepairForm(repair));
    const [files, setFiles] = useState<File[]>([]);
    const [removeIds, setRemoveIds] = useState<number[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const titleRef = useRef<HTMLInputElement>(null);

    // Re-seed each time the sheet opens (it stays mounted): Add starts blank, Edit reflects the repair.
    useEffect(() => {
        if (open) {
            setForm(initialRepairForm(repair));
            setFiles([]);
            setRemoveIds([]);
            setErrors({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, repair?.id]);

    const set = (k: keyof typeof form, v: string | boolean) => setForm((p) => ({ ...p, [k]: v }));

    const submit = () => {
        setProcessing(true);
        // Built with spread conditionals so the inferred value types stay FormDataConvertible (an explicit
        // Record<string, unknown> would not be). Editing spoofs PATCH over multipart (Inertia needs POST for
        // file uploads); the Spending opt-in is add-only.
        const payload = {
            title: form.title,
            occurred_on: form.occurred_on,
            mileage: form.mileage,
            cost: form.cost,
            provider: form.provider,
            ...(form.service_provider_id != null ? { service_provider_id: form.service_provider_id } : {}),
            notes: form.notes,
            attachments: files,
            ...(!editing && canExpense ? { create_expense: form.create_expense } : {}),
            ...(editing ? { _method: 'patch', remove_attachment_ids: removeIds } : {}),
        };
        const url = editing ? `/m/vehicles/${vehicle.id}/repairs/${repair!.id}` : `/m/vehicles/${vehicle.id}/repairs`;
        router.post(url, payload, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    const keptAttachments = (repair?.attachments ?? []).filter((a) => !removeIds.includes(a.id));

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? t('vehicles.edit_repair') : t('vehicles.add_repair')}
            autoFocusRef={titleRef}
            className="sm:max-w-lg"
            footer={
                <Button type="button" onClick={submit} disabled={processing || form.title.trim() === '' || form.occurred_on === ''} className="w-full">
                    {t('vehicles.save')}
                </Button>
            }
        >
            <div className="flex flex-col gap-4 pb-1">
                <Field label={t('vehicles.repair_title_label')} error={errors.title}>
                    <Input ref={titleRef} value={form.title} onChange={(e) => set('title', e.target.value)} placeholder={t('vehicles.repair_title_placeholder')} className="text-base" />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('vehicles.completed_date')} error={errors.occurred_on}>
                        <Input type="date" value={form.occurred_on} onChange={(e) => set('occurred_on', e.target.value)} className="text-base" />
                    </Field>
                    <Field label={t('vehicles.completed_mileage')} error={errors.mileage}>
                        <Input value={form.mileage} onChange={(e) => set('mileage', e.target.value)} inputMode="numeric" placeholder={fmtMileage(vehicle.currentMileage)} className="text-base" />
                    </Field>
                    <Field label={t('vehicles.completed_cost')} error={errors.cost}>
                        <Input value={form.cost} onChange={(e) => set('cost', e.target.value)} inputMode="decimal" placeholder="0.00" className="text-base" />
                    </Field>
                    <ProviderPicker
                        providers={providers}
                        value={{ serviceProviderId: form.service_provider_id, provider: form.provider }}
                        onChange={(v) => setForm((p) => ({ ...p, service_provider_id: v.serviceProviderId, provider: v.provider }))}
                        label={t('vehicles.completed_provider')}
                        t={t}
                        error={errors.provider ?? errors.service_provider_id}
                    />
                </div>

                <Field label={t('vehicles.notes_label')} error={errors.notes}>
                    <textarea
                        value={form.notes}
                        onChange={(e) => set('notes', e.target.value)}
                        rows={2}
                        className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                    />
                </Field>

                {/* Existing attachments (edit mode): tap the X to mark for removal on save. */}
                {keptAttachments.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {keptAttachments.map((a) => (
                            <span key={a.id} className="border-border text-primary flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium">
                                <Paperclip className="size-3.5" />
                                <span className="max-w-[10rem] truncate">{a.name || t('vehicles.attachment')}</span>
                                <button type="button" onClick={() => setRemoveIds((p) => [...p, a.id])} aria-label={t('vehicles.delete')} className="text-muted-foreground hover:text-destructive">
                                    <X className="size-3.5" />
                                </button>
                            </span>
                        ))}
                    </div>
                )}

                <Field label={t('vehicles.attachments_label')} error={errors['attachments.0'] ?? errors.attachments}>
                    <input
                        type="file"
                        accept="image/*,application/pdf"
                        multiple
                        onChange={(e) => setFiles(Array.from(e.target.files ?? []))}
                        className="text-muted-foreground file:border-border file:bg-surface file:text-primary block w-full text-sm file:mr-3 file:rounded-lg file:border file:px-3 file:py-1.5 file:text-sm file:font-medium"
                    />
                    {files.length > 0 && <span className="text-muted-foreground text-xs">{t('vehicles.files_selected', { count: files.length })}</span>}
                </Field>

                {!editing && canExpense && (
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.create_expense} onChange={(e) => set('create_expense', e.target.checked)} className="size-4 rounded border" />
                        <span className="text-text-secondary">{t('vehicles.repair_add_to_spending')}</span>
                    </label>
                )}
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

function todayIso(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
