import { Head, router } from '@inertiajs/react';
import { ListChecks, NotebookText, RotateCcw, ShoppingCart, Trash2, Wallet, type LucideIcon } from 'lucide-react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useLocale, useT } from '@/lib/i18n';

type Item = {
    module: string;
    type: string;
    id: number;
    title: string;
    where: string | null;
    amount?: string;
    deleted_at: string;
    purged_on: string;
};

const MODULE_ICONS: Record<string, LucideIcon> = { tasks: ListChecks, shopping: ShoppingCart, spending: Wallet, notes: NotebookText };

// Settings > Recently deleted: the active family's 30-day trash (App\Support\Trash), across modules.
// Restore is optimistic: the row leaves at once and only comes back if the server refuses.
export default function RecentlyDeleted({ items, days }: { items: Item[]; days: number }) {
    const t = useT();
    const locale = useLocale();
    const restored = useOptimistic<boolean>();
    const key = (i: Item) => `${i.module}-${i.type}-${i.id}`;
    const visible = items.filter((i) => !restored.get(key(i), false));
    const date = (iso: string) => new Date(iso).toLocaleDateString(locale, { day: 'numeric', month: 'short' });

    const restore = (i: Item) => {
        restored.set(key(i), true);
        router.post(
            route('recently-deleted.restore', { module: i.module, type: i.type, id: i.id }),
            {},
            { preserveScroll: true, preserveState: true, onSuccess: () => restored.clear(key(i)), onError: () => restored.clear(key(i)) },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('trash.title'), href: '/settings/recently-deleted' }]}>
            <Head title={t('trash.title')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('trash.title')} description={t('trash.desc', { days })} />

                    {visible.length === 0 ? (
                        <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                            <Trash2 className="size-6" />
                            <p className="text-sm">{t('trash.empty', { days })}</p>
                        </div>
                    ) : (
                        <ul className="divide-border border-border divide-y rounded-xl border">
                            {visible.map((i) => {
                                const Icon = MODULE_ICONS[i.module] ?? Trash2;
                                return (
                                    <li key={key(i)} className="flex items-center gap-3 p-3">
                                        <Icon className="text-muted-foreground size-5 shrink-0" />
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate font-medium">
                                                {i.title}
                                                {i.amount && <span className="text-muted-foreground font-normal tabular-nums"> · {i.amount}</span>}
                                            </div>
                                            <div className="text-muted-foreground truncate text-xs">
                                                {[t(`trash.type_${i.type}`), i.where].filter(Boolean).join(' · ')}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {t('trash.deleted_on', { date: date(i.deleted_at) })} ·{' '}
                                                {t('trash.removed_on', { date: date(i.purged_on) })}
                                            </div>
                                        </div>
                                        <Button type="button" size="sm" variant="outline" onClick={() => restore(i)}>
                                            <RotateCcw className="size-4" /> {t('trash.restore')}
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
