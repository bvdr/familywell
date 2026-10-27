import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { PinModulesModal } from '@/components/pin-modules';
import { Bell, Blocks, Contact, FolderKanban, Import, ListChecks, type LucideIcon, Package, ShoppingCart, StickyNote, Wallet } from 'lucide-react';

type Module = { id: string; name: string; description: string; version: string; enabled: boolean };
type Props = { catalog: Module[]; canManage: boolean };

// Reuse the exact iconography the nav and dashboard use, so a module looks the same everywhere it appears.
const MODULE_ICON: Record<string, LucideIcon> = {
    spending: Wallet,
    shopping: ShoppingCart,
    tasks: ListChecks,
    projects: FolderKanban,
    notes: StickyNote,
    contacts: Contact,
    inventory: Package,
    notifications: Bell,
    expense_import: Import,
};

export default function Modules({ catalog, canManage }: Props) {
    const t = useT();
    const [pinning, setPinning] = useState(false);
    // Optimistic flip: the tile/button switch state on tap; the reload (nav links, sidebar) catches up.
    const enabled = useOptimistic<boolean>();
    const isEnabled = (m: Module) => enabled.get(m.id, m.enabled);
    const toggle = (m: Module) => {
        const on = isEnabled(m);
        enabled.set(m.id, !on);
        const opts = { preserveScroll: true, preserveState: true, onSuccess: () => enabled.clear(m.id), onError: () => enabled.clear(m.id) };
        if (on) {
            router.delete(`/family/modules/${m.id}`, opts);
        } else {
            router.post(`/family/modules/${m.id}/enable`, {}, opts);
        }
    };

    return (
        <AppLayout>
            <Head title={t('nav.modules')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div>
                    <h1 className="font-heading text-primary text-2xl">{t('nav.modules')}</h1>
                    <p className="text-muted-foreground text-sm">{t('dashboard.modules_sub')}</p>
                    {/* "Change your pins anytime in Settings → Modules" (the onboarding's Pin your top 4). */}
                    <button
                        type="button"
                        onClick={() => setPinning(true)}
                        className="text-primary mt-2 text-sm font-semibold underline underline-offset-2"
                    >
                        {t('dashboard.pins_title')}
                    </button>
                    <PinModulesModal open={pinning} onOpenChange={setPinning} />
                </div>

                <ul className="flex flex-col gap-3" data-testid="modules-list">
                    {catalog.map((m) => {
                        const Icon = MODULE_ICON[m.id] ?? Blocks;
                        const on = isEnabled(m);
                        return (
                            <li key={m.id} data-testid={`module-${m.id}`} className="bg-surface-card flex items-center gap-4 rounded-xl border p-4">
                                {/* The tile tints to accent when the module is live, greys out when it is off,
                                    so enabled/disabled reads at a glance without leaning on the label alone. */}
                                <div
                                    className={`flex size-11 shrink-0 items-center justify-center rounded-lg ${
                                        // text-primary (forest green), not text-accent: shadcn maps --accent to the
                                        // pale hover-green, which is invisible on the accent-subtle tile.
                                        on ? 'bg-accent-subtle text-primary' : 'bg-surface text-muted-foreground'
                                    }`}
                                >
                                    <Icon className="size-5" />
                                </div>

                                <div className="min-w-0 flex-1">
                                    <div className="flex items-baseline gap-2">
                                        <span className="font-medium">{m.name}</span>
                                        <span className="text-muted-foreground text-xs">v{m.version}</span>
                                    </div>
                                    <p className="text-text-secondary mt-0.5 text-sm">{m.description}</p>
                                </div>

                                {canManage ? (
                                    <button
                                        onClick={() => toggle(m)}
                                        data-testid={`toggle-${m.id}`}
                                        data-state={on ? 'enabled' : 'disabled'}
                                        className={`shrink-0 rounded-full px-4 py-1.5 text-sm font-medium ${
                                            on ? 'text-text-secondary border' : 'bg-accent-strong text-accent-strong-foreground'
                                        }`}
                                    >
                                        {on ? t('family.disable') : t('family.enable')}
                                    </button>
                                ) : (
                                    <span data-testid={`state-${m.id}`} className="text-muted-foreground shrink-0 text-xs uppercase">
                                        {on ? t('family.enabled') : t('family.disabled')}
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>
        </AppLayout>
    );
}
