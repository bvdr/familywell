import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Pencil, Plus, Trash2, Wrench } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT, type TFn } from '@/lib/i18n';
import type { ServiceProvider, ServiceProviderWithCount } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

type Props = { providers: ServiceProviderWithCount[] };

export default function ServiceProviders({ providers }: Props) {
    const t = useT();
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: t('vehicles.providers'), href: '/m/vehicles/providers' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('vehicles.providers')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="font-heading text-primary text-2xl">{t('vehicles.providers')}</h1>
                        <p className="text-muted-foreground text-sm">{t('vehicles.providers_sub')}</p>
                    </div>
                    {!adding && (
                        <Button type="button" onClick={() => setAdding(true)}>
                            <Plus className="size-4" /> {t('vehicles.add_provider')}
                        </Button>
                    )}
                </header>

                {adding && <ProviderForm provider={null} t={t} onDone={() => setAdding(false)} onCancel={() => setAdding(false)} />}

                {providers.length === 0 && !adding ? (
                    <EmptyState t={t} />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {providers.map((p) =>
                            editingId === p.id ? (
                                <ProviderForm key={p.id} provider={p} t={t} onDone={() => setEditingId(null)} onCancel={() => setEditingId(null)} />
                            ) : (
                                <ProviderCard key={p.id} provider={p} t={t} onEdit={() => setEditingId(p.id)} />
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
            <Wrench className="size-8 opacity-40" />
            <p className="text-primary font-medium">{t('vehicles.providers_empty_title')}</p>
            <p className="max-w-sm text-sm">{t('vehicles.providers_empty_body')}</p>
        </div>
    );
}

// One provider row: name + contact, its service count, links to the detail. Edit/delete inline.
function ProviderCard({ provider, t, onEdit }: { provider: ServiceProviderWithCount; t: TFn; onEdit: () => void }) {
    const remove = () => {
        if (confirm(t('vehicles.provider_delete_confirm'))) {
            router.delete(`/m/vehicles/providers/${provider.id}`, { preserveScroll: true });
        }
    };

    return (
        <li className="bg-surface-card flex items-center gap-3 rounded-2xl border p-4">
            <Link href={`/m/vehicles/providers/${provider.id}`} className="group min-w-0 flex-1">
                <h3 className="text-primary truncate font-medium">{provider.name}</h3>
                <p className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm">
                    {provider.phone && <span>{provider.phone}</span>}
                    {provider.address && <span className="truncate">{provider.address}</span>}
                    <span>{t('vehicles.provider_service_count', { count: provider.serviceCount })}</span>
                </p>
            </Link>
            <div className="flex shrink-0 items-center gap-1">
                <IconButton label={t('vehicles.edit')} onClick={onEdit}>
                    <Pencil className="size-4" />
                </IconButton>
                <IconButton label={t('vehicles.delete')} onClick={remove}>
                    <Trash2 className="size-4" />
                </IconButton>
                <Link
                    href={`/m/vehicles/providers/${provider.id}`}
                    aria-label={provider.name}
                    className="text-muted-foreground hover:text-primary hover:bg-surface flex size-8 items-center justify-center rounded-lg transition-colors"
                >
                    <ChevronRight className="size-4" />
                </Link>
            </div>
        </li>
    );
}

// The add/edit form: name (required) + optional contact fields and notes.
function ProviderForm({ provider, t, onDone, onCancel }: { provider: ServiceProvider | null; t: TFn; onDone: () => void; onCancel: () => void }) {
    const editing = provider !== null;
    const [form, setForm] = useState({
        name: provider?.name ?? '',
        address: provider?.address ?? '',
        phone: provider?.phone ?? '',
        website: provider?.website ?? '',
        notes: provider?.notes ?? '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const set = (k: keyof typeof form, v: string) => setForm((p) => ({ ...p, [k]: v }));

    const submit = () => {
        setProcessing(true);
        const url = editing ? `/m/vehicles/providers/${provider!.id}` : '/m/vehicles/providers';
        const method = editing ? router.patch : router.post;
        method(url, form, {
            preserveScroll: true,
            onSuccess: onDone,
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <section className="bg-surface-card flex flex-col gap-4 rounded-2xl border p-5">
            <h3 className="font-heading text-primary text-lg">{editing ? t('vehicles.edit_provider') : t('vehicles.add_provider')}</h3>

            <Field label={t('vehicles.provider_name_label')} error={errors.name}>
                <Input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder={t('vehicles.provider_name_placeholder')} className="text-base" />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('vehicles.provider_phone_label')} error={errors.phone}>
                    <Input value={form.phone} onChange={(e) => set('phone', e.target.value)} className="text-base" />
                </Field>
                <Field label={t('vehicles.provider_website_label')} error={errors.website}>
                    <Input value={form.website} onChange={(e) => set('website', e.target.value)} className="text-base" />
                </Field>
            </div>

            <Field label={t('vehicles.provider_address_label')} error={errors.address}>
                <Input value={form.address} onChange={(e) => set('address', e.target.value)} className="text-base" />
            </Field>

            <Field label={t('vehicles.notes_label')} error={errors.notes}>
                <textarea
                    value={form.notes}
                    onChange={(e) => set('notes', e.target.value)}
                    rows={2}
                    className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                />
            </Field>

            <div className="flex items-center gap-3">
                <Button type="button" onClick={submit} disabled={processing || form.name.trim() === ''} className="flex-1">
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
