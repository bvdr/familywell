import { Head, router } from '@inertiajs/react';
import { AlertTriangle, BellRing, CheckCircle2, Download, FileText, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import { documentTypeLabel, mileageText, vehicleTitle, type VehicleDocument, type VehicleHeader } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

import VehicleNav from '../vehicle-nav';

type Props = {
    vehicle: VehicleHeader;
    documents: VehicleDocument[];
    types: string[];
    canTask: boolean;
};

export default function VehicleDocuments({ vehicle, documents, types, canTask }: Props) {
    const t = useT();
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: vehicleTitle(vehicle), href: `/m/vehicles/${vehicle.id}` },
        { title: t('vehicles.documents'), href: `/m/vehicles/${vehicle.id}/documents` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${vehicleTitle(vehicle)} · ${t('vehicles.documents')}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex flex-col gap-1">
                    <h1 className="font-heading text-primary text-2xl">{vehicleTitle(vehicle)}</h1>
                    <p className="text-muted-foreground text-sm tabular-nums">{mileageText(t, vehicle.currentMileage)}</p>
                </header>

                <VehicleNav vehicleId={vehicle.id} active="documents" />

                <div className="flex items-center justify-between gap-3">
                    <h2 className="font-heading text-primary text-xl">{t('vehicles.documents')}</h2>
                    {!adding && (
                        <Button type="button" onClick={() => setAdding(true)}>
                            <Plus className="size-4" /> {t('vehicles.add_document')}
                        </Button>
                    )}
                </div>

                {adding && <DocumentForm vehicle={vehicle} document={null} types={types} t={t} onDone={() => setAdding(false)} onCancel={() => setAdding(false)} />}

                {documents.length === 0 && !adding ? (
                    <EmptyState t={t} />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {documents.map((d) =>
                            editingId === d.id ? (
                                <DocumentForm key={d.id} vehicle={vehicle} document={d} types={types} t={t} onDone={() => setEditingId(null)} onCancel={() => setEditingId(null)} />
                            ) : (
                                <DocumentCard key={d.id} vehicle={vehicle} document={d} canTask={canTask} t={t} onEdit={() => setEditingId(d.id)} />
                            ),
                        )}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}

function EmptyState({ t }: { t: TFn }) {
    return (
        <div className="text-muted-foreground bg-surface-card flex flex-col items-center gap-2 rounded-2xl border px-4 py-10 text-center">
            <FileText className="size-8 opacity-40" />
            <p className="text-primary font-medium">{t('vehicles.documents_empty_title')}</p>
            <p className="max-w-sm text-sm">{t('vehicles.documents_empty_body')}</p>
        </div>
    );
}

function DocumentCard({
    vehicle,
    document,
    canTask,
    t,
    onEdit,
}: {
    vehicle: VehicleHeader;
    document: VehicleDocument;
    canTask: boolean;
    t: TFn;
    onEdit: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const remove = () => {
        if (confirm(t('vehicles.document_delete_confirm'))) {
            router.delete(`/m/vehicles/${vehicle.id}/documents/${document.id}`, { preserveScroll: true });
        }
    };

    const createRenewalTask = () => {
        setProcessing(true);
        router.post(`/m/vehicles/${vehicle.id}/documents/${document.id}/renewal-task`, {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    return (
        <li className="bg-surface-card flex flex-col gap-2 rounded-2xl border p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-primary font-medium">{document.title}</h3>
                        <span className="bg-accent-subtle text-primary rounded-md px-1.5 py-0.5 text-xs font-medium">{documentTypeLabel(t, document.type)}</span>
                    </div>
                    {document.expiresOn && (
                        <p className={`flex items-center gap-1.5 text-sm ${document.expiring ? 'text-[var(--warning)]' : 'text-muted-foreground'}`}>
                            {document.expiring && <AlertTriangle className="size-3.5" />}
                            {t('vehicles.expires_on', { date: document.expiresOn })}
                        </p>
                    )}
                </div>
                <div className="flex shrink-0 items-center gap-1">
                    <IconButton label={t('vehicles.edit')} onClick={onEdit}>
                        <Pencil className="size-4" />
                    </IconButton>
                    <IconButton label={t('vehicles.delete')} onClick={remove}>
                        <Trash2 className="size-4" />
                    </IconButton>
                </div>
            </div>

            {document.notes && <p className="text-text-secondary text-sm whitespace-pre-wrap">{document.notes}</p>}

            <div className="flex flex-wrap items-center gap-2 pt-1">
                {document.fileUrl && (
                    <a
                        href={document.fileUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="border-border text-primary hover:bg-surface flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors"
                    >
                        <Download className="size-3.5" />
                        <span className="max-w-[12rem] truncate">{document.fileName || t('vehicles.view_file')}</span>
                    </a>
                )}
                {/* Renewal task: link into Tasks when one already exists, else offer to create it (idempotent). */}
                {canTask &&
                    (document.hasRenewalTask ? (
                        <span className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
                            <CheckCircle2 className="size-3.5" /> {t('vehicles.renewal_task_created')}
                        </span>
                    ) : (
                        <button
                            type="button"
                            onClick={createRenewalTask}
                            disabled={processing}
                            className="border-border text-primary hover:bg-surface flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors disabled:opacity-50"
                        >
                            <BellRing className="size-3.5" /> {t('vehicles.create_renewal_task')}
                        </button>
                    ))}
            </div>
        </li>
    );
}

function DocumentForm({
    vehicle,
    document,
    types,
    t,
    onDone,
    onCancel,
}: {
    vehicle: VehicleHeader;
    document: VehicleDocument | null;
    types: string[];
    t: TFn;
    onDone: () => void;
    onCancel: () => void;
}) {
    const editing = document !== null;
    const [form, setForm] = useState({
        type: document?.type ?? types[0] ?? 'other',
        title: document?.title ?? '',
        expires_on: document?.expiresOn ?? '',
        notes: document?.notes ?? '',
    });
    const [file, setFile] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const set = (k: keyof typeof form, v: string) => setForm((p) => ({ ...p, [k]: v }));

    const submit = () => {
        setProcessing(true);
        // Spread conditionals keep the inferred types FormDataConvertible; editing spoofs PATCH over multipart.
        const payload = {
            ...form,
            ...(file ? { file } : {}),
            ...(editing ? { _method: 'patch' } : {}),
        };
        const url = editing ? `/m/vehicles/${vehicle.id}/documents/${document!.id}` : `/m/vehicles/${vehicle.id}/documents`;
        router.post(url, payload, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onDone,
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <section className="bg-surface-card flex flex-col gap-4 rounded-2xl border p-5">
            <h3 className="font-heading text-primary text-lg">{editing ? t('vehicles.edit_document') : t('vehicles.add_document')}</h3>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('vehicles.document_type_label')} error={errors.type}>
                    <select
                        value={form.type}
                        onChange={(e) => set('type', e.target.value)}
                        className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                    >
                        {types.map((ty) => (
                            <option key={ty} value={ty}>
                                {documentTypeLabel(t, ty)}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label={t('vehicles.document_expiry_label')} error={errors.expires_on}>
                    <Input type="date" value={form.expires_on} onChange={(e) => set('expires_on', e.target.value)} className="text-base" />
                </Field>
            </div>

            <Field label={t('vehicles.document_title_label')} error={errors.title}>
                <Input value={form.title} onChange={(e) => set('title', e.target.value)} placeholder={t('vehicles.document_title_placeholder')} className="text-base" />
            </Field>

            <Field label={t('vehicles.notes_label')} error={errors.notes}>
                <textarea
                    value={form.notes}
                    onChange={(e) => set('notes', e.target.value)}
                    rows={2}
                    className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                />
            </Field>

            <Field label={editing && document?.hasFile ? t('vehicles.replace_file_label') : t('vehicles.document_file_label')} error={errors.file}>
                <input
                    type="file"
                    accept="image/*,application/pdf"
                    onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                    className="text-muted-foreground file:border-border file:bg-surface file:text-primary block w-full text-sm file:mr-3 file:rounded-lg file:border file:px-3 file:py-1.5 file:text-sm file:font-medium"
                />
                {editing && document?.hasFile && !file && <span className="text-muted-foreground text-xs">{t('vehicles.current_file', { name: document.fileName || t('vehicles.view_file') })}</span>}
            </Field>

            <div className="flex items-center gap-3">
                <Button type="button" onClick={submit} disabled={processing || form.title.trim() === ''} className="flex-1">
                    {t('vehicles.save')}
                </Button>
                <Button type="button" variant="outline" onClick={onCancel}>
                    {t('vehicles.cancel')}
                </Button>
            </div>
        </section>
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
