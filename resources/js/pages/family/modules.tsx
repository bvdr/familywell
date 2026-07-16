import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';

type Module = { id: string; name: string; version: string; enabled: boolean };
type Props = { modules: Module[]; canManage: boolean };

export default function Modules({ modules, canManage }: Props) {
    const toggle = (m: Module) => {
        if (m.enabled) {
            router.delete(`/family/modules/${m.id}`, { preserveScroll: true });
        } else {
            router.post(`/family/modules/${m.id}/enable`, {}, { preserveScroll: true });
        }
    };

    return (
        <AppLayout>
            <Head title="Modules" />
            <div className="p-6">
                <h1 className="mb-4 text-xl font-semibold">Modules</h1>
                <ul className="divide-y" data-testid="modules-list">
                    {modules.map((m) => (
                        <li key={m.id} className="flex items-center justify-between py-3" data-testid={`module-${m.id}`}>
                            <span>
                                {m.name} <span className="text-muted-foreground text-sm">v{m.version}</span>
                                <span className="ml-2 text-xs uppercase" data-testid={`state-${m.id}`}>
                                    {m.enabled ? 'enabled' : 'disabled'}
                                </span>
                            </span>
                            {canManage && (
                                <button
                                    onClick={() => toggle(m)}
                                    data-testid={`toggle-${m.id}`}
                                    className="bg-primary text-primary-foreground rounded px-3 py-1 text-sm"
                                >
                                    {m.enabled ? 'Disable' : 'Enable'}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
