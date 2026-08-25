import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';
import { Bell, Blocks, FolderKanban, ListChecks, type LucideIcon, ShoppingCart, Sparkles, StickyNote, Wallet } from 'lucide-react';

type Module = { id: string; name: string; description: string; version: string; enabled: boolean };
type Props = { catalog: Module[]; canManage: boolean };

// Reuse the exact iconography the nav and dashboard use, so a module looks the same everywhere it appears.
const MODULE_ICON: Record<string, LucideIcon> = {
    spending: Wallet,
    shopping: ShoppingCart,
    tasks: ListChecks,
    projects: FolderKanban,
    notes: StickyNote,
    notifications: Bell,
    hello: Sparkles,
};

export default function Modules({ catalog, canManage }: Props) {
    const t = useT();
    const toggle = (m: Module) => {
        if (m.enabled) {
            router.delete(`/family/modules/${m.id}`, { preserveScroll: true });
        } else {
            router.post(`/family/modules/${m.id}/enable`, {}, { preserveScroll: true });
        }
    };

    return (
        <AppLayout>
            <Head title={t('nav.modules')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div>
                    <h1 className="font-heading text-primary text-2xl">{t('nav.modules')}</h1>
                    <p className="text-muted-foreground text-sm">{t('dashboard.modules_sub')}</p>
                </div>

                <ul className="flex flex-col gap-3" data-testid="modules-list">
                    {catalog.map((m) => {
                        const Icon = MODULE_ICON[m.id] ?? Blocks;
                        return (
                            <li key={m.id} data-testid={`module-${m.id}`} className="bg-surface-card flex items-center gap-4 rounded-xl border p-4">
                                {/* The tile tints to accent when the module is live, greys out when it is off,
                                    so enabled/disabled reads at a glance without leaning on the label alone. */}
                                <div
                                    className={`flex size-11 shrink-0 items-center justify-center rounded-lg ${
                                        // text-primary (forest green), not text-accent: shadcn maps --accent to the
                                        // pale hover-green, which is invisible on the accent-subtle tile.
                                        m.enabled ? 'bg-accent-subtle text-primary' : 'bg-surface text-muted-foreground'
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
                                        data-state={m.enabled ? 'enabled' : 'disabled'}
                                        className={`shrink-0 rounded-full px-4 py-1.5 text-sm font-medium ${
                                            m.enabled ? 'text-text-secondary border' : 'bg-accent-strong text-accent-strong-foreground'
                                        }`}
                                    >
                                        {m.enabled ? t('family.disable') : t('family.enable')}
                                    </button>
                                ) : (
                                    <span data-testid={`state-${m.id}`} className="text-muted-foreground shrink-0 text-xs uppercase">
                                        {m.enabled ? t('family.enabled') : t('family.disabled')}
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
