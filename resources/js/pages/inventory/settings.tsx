import { Head, Link, router } from '@inertiajs/react';
import { LayoutGrid, MapPin } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

// Mirrors InventorySetting::toPayload() (camelCase). Family-level policy — the per-user push mute lives
// on the account Notifications screen; these decide whether an alert is generated at all.
type Settings = {
    unitSystem: string;
    autoAddLow: boolean;
    autoAddEmpty: boolean;
    notifyLow: boolean;
    notifyEmpty: boolean;
    notifyExpired: boolean;
    notifyStale: boolean;
};

type Props = {
    settings: Settings;
    unitSystems: string[];
    canManage: boolean; // family admin? gates every write on this screen (server enforces it too)
};

export default function InventorySettings({ settings, unitSystems, canManage }: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('inventory.title'), href: '/m/inventory' },
        { title: t('inventory.settings'), href: route('inventory.settings') },
    ];

    // Instant-save a single field (each control patches only what it changed). Keys are the server's
    // request field names (snake_case); the server ignores absent fields, so one toggle never clobbers
    // another.
    type Patch = Partial<{
        unit_system: string;
        auto_add_low: boolean;
        auto_add_empty: boolean;
        notify_low: boolean;
        notify_empty: boolean;
        notify_expired: boolean;
        notify_stale: boolean;
    }>;
    const save = (patch: Patch) => router.patch(route('inventory.settings.update'), patch, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('inventory.settings')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div>
                    <h1 className="font-heading text-primary text-2xl">{t('inventory.settings')}</h1>
                    <p className="text-muted-foreground text-sm">{t('inventory.settings_sub')}</p>
                </div>

                {/* Default unit system. No app-wide unit setting exists, so this is the module-level default. */}
                <section className="bg-surface-card flex flex-col gap-3 rounded-xl border p-5">
                    <div>
                        <h2 className="font-heading text-primary text-lg">{t('inventory.unit_system')}</h2>
                        <p className="text-muted-foreground text-sm">{t('inventory.unit_system_desc')}</p>
                    </div>
                    <select
                        value={settings.unitSystem}
                        onChange={(e) => save({ unit_system: e.target.value })}
                        disabled={!canManage}
                        className="border-border bg-surface-card text-primary w-full rounded-lg border px-3 py-2 text-base disabled:opacity-60 md:max-w-xs md:text-sm"
                        aria-label={t('inventory.unit_system')}
                    >
                        {unitSystems.map((u) => (
                            <option key={u} value={u}>
                                {t(`inventory.unit_system_${u}`)}
                            </option>
                        ))}
                    </select>
                </section>

                {/* Replenishment: whether the daily check auto-surfaces short stock onto Shopping. */}
                <section className="bg-surface-card flex flex-col gap-1 rounded-xl border p-5">
                    <div className="mb-2">
                        <h2 className="font-heading text-primary text-lg">{t('inventory.replenishment_settings')}</h2>
                        <p className="text-muted-foreground text-sm">{t('inventory.replenishment_settings_desc')}</p>
                    </div>
                    <ToggleRow
                        label={t('inventory.auto_add_low')}
                        hint={t('inventory.auto_add_low_hint')}
                        on={settings.autoAddLow}
                        disabled={!canManage}
                        onToggle={() => save({ auto_add_low: !settings.autoAddLow })}
                    />
                    <ToggleRow
                        label={t('inventory.auto_add_empty')}
                        hint={t('inventory.auto_add_empty_hint')}
                        on={settings.autoAddEmpty}
                        disabled={!canManage}
                        onToggle={() => save({ auto_add_empty: !settings.autoAddEmpty })}
                    />
                </section>

                {/* Notifications: which conditions may raise an alert for the family. */}
                <section className="bg-surface-card flex flex-col gap-1 rounded-xl border p-5">
                    <div className="mb-2">
                        <h2 className="font-heading text-primary text-lg">{t('inventory.notifications_settings')}</h2>
                        <p className="text-muted-foreground text-sm">{t('inventory.notifications_settings_desc')}</p>
                    </div>
                    <ToggleRow
                        label={t('inventory.notify_low')}
                        on={settings.notifyLow}
                        disabled={!canManage}
                        onToggle={() => save({ notify_low: !settings.notifyLow })}
                    />
                    <ToggleRow
                        label={t('inventory.notify_empty')}
                        on={settings.notifyEmpty}
                        disabled={!canManage}
                        onToggle={() => save({ notify_empty: !settings.notifyEmpty })}
                    />
                    <ToggleRow
                        label={t('inventory.notify_expired')}
                        on={settings.notifyExpired}
                        disabled={!canManage}
                        onToggle={() => save({ notify_expired: !settings.notifyExpired })}
                    />
                    <ToggleRow
                        label={t('inventory.notify_stale')}
                        on={settings.notifyStale}
                        disabled={!canManage}
                        onToggle={() => save({ notify_stale: !settings.notifyStale })}
                    />
                    <p className="text-muted-foreground mt-2 text-xs">{t('inventory.notify_footnote')}</p>
                </section>

                {/* Manage the family's categories/locations (defined elsewhere; linked here per the spec). */}
                <section className="bg-surface-card flex flex-col gap-2 rounded-xl border p-5">
                    <h2 className="font-heading text-primary text-lg">{t('inventory.taxonomy_settings')}</h2>
                    <ManageLink href="/m/inventory/categories" label={t('inventory.view_categories')} icon={LayoutGrid} />
                    <ManageLink href="/m/inventory/locations" label={t('inventory.view_locations')} icon={MapPin} />
                </section>

                {!canManage && <p className="text-muted-foreground px-1 text-xs">{t('inventory.settings_admin_only')}</p>}
            </div>
        </AppLayout>
    );
}

// A label (+ optional hint) with a switch on the right. Disabled (non-admin) dims the whole row.
function ToggleRow({ label, hint, on, disabled, onToggle }: { label: string; hint?: string; on: boolean; disabled?: boolean; onToggle: () => void }) {
    return (
        <div className={`flex items-center justify-between gap-3 py-2.5 ${disabled ? 'opacity-60' : ''}`}>
            <span className="min-w-0">
                <span className="text-primary block text-sm font-medium">{label}</span>
                {hint && <span className="text-muted-foreground block text-xs">{hint}</span>}
            </span>
            <button
                type="button"
                role="switch"
                aria-checked={on}
                aria-label={label}
                disabled={disabled}
                onClick={onToggle}
                className={`relative h-6 w-10 shrink-0 rounded-full transition-colors disabled:cursor-not-allowed ${on ? 'bg-accent-strong' : 'bg-border'}`}
            >
                <span className={`absolute top-0.5 size-5 rounded-full bg-white shadow-sm transition-all ${on ? 'left-[18px]' : 'left-0.5'}`} />
            </button>
        </div>
    );
}

function ManageLink({ href, label, icon: Icon }: { href: string; label: string; icon: typeof LayoutGrid }) {
    return (
        <Link href={href} className="hover:bg-surface flex items-center gap-3 rounded-lg border px-3 py-2.5 transition-colors">
            <Icon className="text-primary size-4 shrink-0" />
            <span className="text-primary text-sm">{label}</span>
        </Link>
    );
}
