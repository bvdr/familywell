import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, ChevronRight, Pencil, Plus, Trash2, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import { LucideIcon } from '@/components/lucide-icon';
import { IconPicker } from '@/components/icon-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { byKey, locLabel, type InvLocation } from '@/lib/inventory';
import type { BreadcrumbItem } from '@/types';

// A location card carries its item tallies plus the DB id used by the manage endpoints.
type LocRow = InvLocation & { id: number; count: number; low: number; empty: number };

type Props = { locations: LocRow[]; canManage: boolean };

export default function InventoryLocations({ locations, canManage }: Props) {
    const t = useT();
    const locMap = byKey(locations);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('inventory.title'), href: '/m/inventory' },
        { title: t('inventory.locations_title'), href: '/m/inventory/locations' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('inventory.locations_title')} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <Link href="/m/inventory" className="text-muted-foreground flex items-center gap-1.5 text-sm">
                    <ArrowLeft className="size-4" /> {t('inventory.title')}
                </Link>

                <header>
                    <h1 className="font-heading text-primary text-2xl">{t('inventory.locations_title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('inventory.locations_subtitle')}</p>
                </header>

                {locations.length === 0 ? (
                    <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-10 text-center text-sm">{t('inventory.no_locations')}</p>
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {locations.map((l) => (
                            <LocationRow key={l.key} l={l} canManage={canManage} locMap={locMap} />
                        ))}
                    </div>
                )}

                {canManage ? (
                    <AddForm />
                ) : (
                    <p className="text-muted-foreground text-xs">{t('inventory.manage_hint_admins')}</p>
                )}
            </div>
        </AppLayout>
    );
}

// One location card. Admins can rename it inline, change its icon (via the IconPicker tile) and delete it
// — this now covers the seeded defaults (Kitchen/Pantry/…) too, not just custom locations. A delete is
// blocked while any item still uses the location; we surface the exact count inline instead of orphaning.
function LocationRow({ l, canManage, locMap }: { l: LocRow; canManage: boolean; locMap: Record<string, InvLocation> }) {
    const t = useT();
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState('');
    const [blocked, setBlocked] = useState(false); // set when a delete is attempted while the location is in use

    const changeIcon = (icon: string | null) => router.patch(route('inventory.locations.update', l.id), { icon }, { preserveScroll: true });

    const startEdit = () => {
        setName(locLabel(t, l.key, locMap)); // seed with the current display label (localised for defaults)
        setEditing(true);
    };
    const saveEdit = () => {
        const trimmed = name.trim();
        if (trimmed === '') return;
        router.patch(route('inventory.locations.update', l.id), { name: trimmed }, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const remove = () => {
        if (l.count > 0) {
            setBlocked(true); // in-use locations can't be deleted (they'd orphan items) — show the count instead
            return;
        }
        router.delete(route('inventory.locations.destroy', l.id), { preserveScroll: true });
    };

    // Compact summary: "N items · L low · E empty" (only the non-zero problem parts).
    const subtitle = [
        t('inventory.items_count', { count: l.count }),
        l.low > 0 ? t('inventory.low_count', { count: l.low }) : null,
        l.empty > 0 ? t('inventory.empty_count', { count: l.empty }) : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="bg-surface-card flex flex-col gap-1 rounded-xl border px-3 py-3">
            <div className="flex items-center gap-3">
                {canManage ? (
                    <IconPicker
                        value={l.icon}
                        onChange={changeIcon}
                        className="size-10 rounded-lg"
                        iconClassName="size-5"
                        ariaLabel={t('inventory.change_icon')}
                    />
                ) : (
                    <span className="bg-accent-subtle text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                        <LucideIcon name={l.icon} className="size-5" />
                    </span>
                )}

                {editing ? (
                    <div className="flex min-w-0 flex-1 items-center gap-2">
                        <Input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') saveEdit();
                                if (e.key === 'Escape') setEditing(false);
                            }}
                            maxLength={40}
                            autoFocus
                            aria-label={t('inventory.edit_location')}
                            className="text-base md:text-sm"
                        />
                        <button
                            type="button"
                            onClick={saveEdit}
                            disabled={name.trim() === ''}
                            aria-label={t('inventory.save')}
                            className="text-primary hover:bg-surface flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors disabled:opacity-40"
                        >
                            <Check className="size-4" />
                        </button>
                        <button
                            type="button"
                            onClick={() => setEditing(false)}
                            aria-label={t('inventory.cancel')}
                            className="text-muted-foreground hover:bg-surface flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                ) : (
                    <>
                        <Link href={`/m/inventory/items?location=${encodeURIComponent(l.key)}`} className="flex min-w-0 flex-1 items-center gap-2">
                            <span className="min-w-0 flex-1">
                                <span className="text-primary block truncate text-sm font-medium">{locLabel(t, l.key, locMap)}</span>
                                <span className="text-muted-foreground block truncate text-xs">{subtitle}</span>
                            </span>
                            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                        </Link>
                        {canManage && (
                            <>
                                <button
                                    type="button"
                                    onClick={startEdit}
                                    title={t('inventory.edit_location')}
                                    aria-label={t('inventory.edit_location')}
                                    className="text-muted-foreground hover:text-primary flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors"
                                >
                                    <Pencil className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={remove}
                                    title={t('inventory.delete_location')}
                                    aria-label={t('inventory.delete_location')}
                                    className="text-muted-foreground hover:text-destructive flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors"
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </>
                        )}
                    </>
                )}
            </div>

            {blocked && l.count > 0 && (
                <p className="text-destructive pl-[3.25rem] text-xs">{t('inventory.location_in_use_n', { count: l.count })}</p>
            )}
        </div>
    );
}

// Add a custom location: an optional icon (defaults to the map-pin) + a name.
function AddForm() {
    const t = useT();
    const form = useForm<{ name: string; icon: string | null }>({ name: '', icon: null });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('inventory.locations.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };
    return (
        <form onSubmit={submit} className="bg-surface-card flex flex-col gap-2 rounded-xl border p-4">
            <Label htmlFor="inv-loc-name">{t('inventory.add_location')}</Label>
            <div className="flex items-start gap-2">
                <IconPicker value={form.data.icon} onChange={(icon) => form.setData('icon', icon)} ariaLabel={t('inventory.change_icon')} />
                <div className="flex-1">
                    <Input
                        id="inv-loc-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder={t('inventory.location_name_placeholder')}
                        maxLength={40}
                        className="text-base md:text-sm"
                    />
                    <InputError message={form.errors.name} className="mt-1" />
                </div>
                <Button type="submit" disabled={form.processing || form.data.name.trim() === ''}>
                    <Plus className="size-4" /> {t('common.add')}
                </Button>
            </div>
        </form>
    );
}
