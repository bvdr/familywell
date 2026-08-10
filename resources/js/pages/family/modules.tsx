import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';

type Module = { id: string; name: string; version: string; enabled: boolean };
type Props = { catalog: Module[]; canManage: boolean };

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
            <div className="p-6">
                <h1 className="mb-4 text-xl font-semibold">{t('nav.modules')}</h1>
                <ul className="divide-y" data-testid="modules-list">
                    {catalog.map((m) => (
                        <li key={m.id} className="flex items-center justify-between py-3" data-testid={`module-${m.id}`}>
                            <span>
                                {m.name} <span className="text-muted-foreground text-sm">v{m.version}</span>
                                <span className="ml-2 text-xs uppercase" data-testid={`state-${m.id}`}>
                                    {m.enabled ? t('family.enabled') : t('family.disabled')}
                                </span>
                            </span>
                            {canManage && (
                                <button
                                    onClick={() => toggle(m)}
                                    data-testid={`toggle-${m.id}`}
                                    className="bg-primary text-primary-foreground rounded px-3 py-1 text-sm"
                                >
                                    {m.enabled ? t('family.disable') : t('family.enable')}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
