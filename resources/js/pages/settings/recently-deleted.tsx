import { Head } from '@inertiajs/react';
import {
    Cake,
    CalendarDays,
    Car,
    ChefHat,
    Contact,
    FolderKanban,
    ListChecks,
    NotebookText,
    Package,
    ShoppingCart,
    Trash2,
    Wallet,
    type LucideIcon,
} from 'lucide-react';

import { SettingsGroup, SettingsPage, SettingsRow } from '@/components/settings-ui';
import { Button } from '@/components/ui/button';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { cn } from '@/lib/utils';

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

// Same icons as the sidebar (app-sidebar.tsx), so a trashed row reads as its module at a glance.
const MODULE_ICONS: Record<string, LucideIcon> = {
    tasks: ListChecks,
    shopping: ShoppingCart,
    spending: Wallet,
    notes: NotebookText,
    projects: FolderKanban,
    calendar: CalendarDays,
    recipes: ChefHat,
    contacts: Contact,
    birthdays: Cake,
    inventory: Package,
    vehicles: Car,
};

// Settings > Recently deleted (design Y4): the active family's 30-day trash (App\Support\Trash), across modules.
// Each row: the module's icon in a tile, what it was, "Kind · where", and how long until it's gone for good (in mono,
// red in the last 3 days), with Restore. Restore is optimistic: the row leaves at once and only comes back if the
// server refuses.
export default function RecentlyDeleted({ items, days, family }: { items: Item[]; days: number; family: { name: string } }) {
    const t = useT();
    const restored = useOptimistic<boolean>();
    const key = (i: Item) => `${i.module}-${i.type}-${i.id}`;
    const visible = items.filter((i) => !restored.get(key(i), false));
    // purged_on is a plain date (Trash::row); count whole days from today's local midnight to it.
    const today = new Date().setHours(0, 0, 0, 0);
    const daysLeft = (purgedOn: string) => Math.max(0, Math.round((new Date(`${purgedOn}T00:00`).getTime() - today) / 86_400_000));

    const restore = (i: Item) => {
        restored.set(key(i), true);
        void save('post', route('recently-deleted.restore', { module: i.module, type: i.type, id: i.id }), {}).then(() => restored.clear(key(i)));
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('trash.title'), href: '/settings/recently-deleted' }]}>
            <Head title={t('trash.title')} />
            <SettingsPage back={{ href: '/settings/family', label: family.name }} title={t('trash.title')} lede={t('trash.kept', { days })}>
                {visible.length === 0 ? (
                    <SettingsGroup>
                        <div className="text-muted-foreground flex flex-col items-center gap-2 p-10 text-center">
                            <Trash2 className="size-6" />
                            <p className="text-sm">{t('trash.empty', { days })}</p>
                        </div>
                    </SettingsGroup>
                ) : (
                    <SettingsGroup>
                        {visible.map((i) => {
                            const left = daysLeft(i.purged_on);
                            return (
                                <SettingsRow
                                    key={key(i)}
                                    icon={MODULE_ICONS[i.module] ?? Trash2}
                                    tile
                                    title={i.title}
                                    sub={
                                        <>
                                            {/* The amount rides in the sub line: on the title it was the first thing truncated away. */}
                                            {[t(`trash.type_${i.type}`), i.where, i.amount].filter(Boolean).join(' · ')}
                                            <span
                                                className={cn(
                                                    'block font-mono text-[13px] font-medium',
                                                    left <= 3 ? 'text-destructive' : 'text-primary',
                                                )}
                                            >
                                                {t('trash.days_left', { count: left })}
                                            </span>
                                        </>
                                    }
                                    right={
                                        <Button
                                            type="button"
                                            size="sm"
                                            className="bg-primary text-primary-foreground hover:bg-primary/90 shrink-0 rounded-full px-4 font-bold"
                                            onClick={() => restore(i)}
                                        >
                                            {t('trash.restore')}
                                        </Button>
                                    }
                                />
                            );
                        })}
                    </SettingsGroup>
                )}
            </SettingsPage>
        </AppLayout>
    );
}
