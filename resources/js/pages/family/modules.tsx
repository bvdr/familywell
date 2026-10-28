import { MAX_NAV_PINS, useNavPins } from '@/hooks/use-nav-pins';
import { Head, Link, router } from '@inertiajs/react';
import { Blocks, ChevronLeft, Pin, Search, type LucideIcon } from 'lucide-react';
import { useState } from 'react';

import { MODULE_ITEMS } from '@/components/mobile-tab-bar';
import { MODULES, type ModuleSummary } from '@/components/your-modules';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Module = { id: string; name: string; description: string; version: string; enabled: boolean };
type Props = { catalog: Module[]; canManage: boolean; summaries: Record<string, ModuleSummary> };

// Every installed module needs a tile icon even if it isn't one of the dashboard's pinnable MODULES
// (notifications, expense_import, important_todos aren't — they have no /m/<id> index of their own).
const iconFor = (id: string): LucideIcon => MODULES[id]?.icon ?? Blocks;
// "Tap any row to open that module's store page": no module store/catalogue page exists yet (checked
// routes/web.php + resources/js/pages), so a row opens the module itself instead, same as the dashboard
// tiles. Modules with no own index (expense_import has one at /m/expense-import; notifications' is
// /notifications, not under MODULES) get a route built the same way the nav does.
const hrefFor = (id: string): string =>
    MODULES[id]?.href ?? (id === 'notifications' ? '/notifications' : id === 'expense_import' ? '/m/expense-import' : `/m/${id}`);

type Filter = 'all' | 'on' | 'pinned' | 'off';

export default function Modules({ catalog, canManage, summaries }: Props) {
    const t = useT();
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    const enabled = useOptimistic<boolean>();
    const isEnabled = (m: Module) => enabled.get(m.id, m.enabled);
    const turnOn = (m: Module) => {
        enabled.set(m.id, true);
        router.post(
            `/family/modules/${m.id}/enable`,
            {},
            { preserveScroll: true, preserveState: true, onSuccess: () => enabled.clear(m.id), onError: () => enabled.clear(m.id) },
        );
    };

    // "Pin to your tab bar": the same per-device pins the phone tab bar and More's long-press use (useNavPins),
    // so a pin made here shows in the bar right away. Up to three; Home is always first.
    // Only modules with their own page can sit in the bar (extensions like Important Todos have none), so only they
    // get a pin and only they are passed in: an extension can never take one of the three slots.
    const pinnable = (id: string) => MODULE_ITEMS.some((i) => i.key === id);
    const { pinned, toggle: togglePin, full: pinsFull } = useNavPins(catalog.filter((m) => isEnabled(m) && pinnable(m.id)).map((m) => m.id));

    const on = catalog.filter(isEnabled);
    const off = catalog.filter((m) => !isEnabled(m));
    const onPinnedCount = on.filter((m) => pinned.includes(m.id)).length;

    const q = search.trim().toLowerCase();
    const matches = (m: Module) => !q || m.name.toLowerCase().includes(q);
    const chip = (key: Filter, label: string, count: number) => (
        <button
            key={key}
            type="button"
            onClick={() => setFilter(key)}
            className={cn(
                'shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium',
                filter === key ? 'bg-primary text-primary-foreground' : 'bg-surface-card border',
            )}
        >
            {label} · {count}
        </button>
    );

    const visibleOn = filter === 'off' ? [] : on.filter(matches).filter((m) => filter !== 'pinned' || pinned.includes(m.id));
    const visibleOff = filter === 'on' || filter === 'pinned' ? [] : off.filter(matches);

    return (
        <AppLayout>
            <Head title={t('family.modules_all_title')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                {/* "More" is a drawer on the dashboard, not its own route (mobile-tab-bar.tsx), so the back
                    pill reads "More" (where this page was opened from) but returns to Home underneath it. */}
                <Link
                    href="/dashboard"
                    className="border-border bg-surface-card text-primary inline-flex w-fit items-center gap-1 rounded-full border py-1.5 pr-3.5 pl-2.5 text-sm font-medium"
                >
                    <ChevronLeft className="size-4" /> {t('nav.more')}
                </Link>

                <div>
                    <h1 className="font-heading text-primary text-3xl font-black">{t('family.modules_all_title')}</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {t('family.modules_on_count', { count: on.length })} · {t('family.modules_pinned_count', { count: onPinnedCount })}
                    </p>
                </div>

                <div className="relative">
                    <Search className="text-muted-foreground absolute top-1/2 left-3.5 size-4 -translate-y-1/2" />
                    <input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={t('family.modules_search')}
                        className="bg-surface-card border-border w-full rounded-full border py-2.5 pr-4 pl-10 text-[16px]"
                    />
                </div>

                <div className="-mx-4 flex gap-2 overflow-x-auto px-4" data-scroll-x>
                    {chip('all', t('family.modules_chip_all'), catalog.length)}
                    {chip('on', t('family.modules_chip_on'), on.length)}
                    {chip('pinned', t('family.modules_chip_pinned'), onPinnedCount)}
                    {chip('off', t('family.modules_chip_off'), off.length)}
                </div>

                {visibleOn.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <div className="flex items-baseline justify-between px-1">
                            <h2 className="text-muted-foreground font-mono text-[10.5px] font-semibold tracking-[0.8px] uppercase">
                                {t('family.modules_on_header')} {on.length}
                            </h2>
                            <span className="text-muted-foreground text-xs font-medium">{t('family.modules_pin_hint', { max: MAX_NAV_PINS })}</span>
                        </div>
                        <ul
                            className="bg-surface-card divide-border/60 flex flex-col divide-y overflow-hidden rounded-2xl border"
                            data-testid="modules-on"
                        >
                            {visibleOn.map((m) => {
                                const Icon = iconFor(m.id);
                                const line = summaries[m.id];
                                const isPinned = pinned.includes(m.id);
                                return (
                                    <li key={m.id} data-testid={`module-${m.id}`} className="flex items-center gap-3 px-4 py-3">
                                        <Link href={hrefFor(m.id)} className="flex min-w-0 flex-1 items-center gap-3">
                                            <span className="bg-surface text-primary flex size-10 shrink-0 items-center justify-center rounded-xl">
                                                <Icon className="size-4.5" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="text-primary block truncate text-[15px] font-bold">{m.name}</span>
                                                <span className="text-muted-foreground block truncate text-[12.5px]">
                                                    {line ? t(line.key, line.params) : m.description}
                                                </span>
                                            </span>
                                        </Link>
                                        {pinnable(m.id) && (
                                            <button
                                                type="button"
                                                onClick={() => togglePin(m.id)}
                                                disabled={!isPinned && pinsFull} // three in the bar already: unpin one first
                                                aria-pressed={isPinned}
                                                aria-label={t(isPinned ? 'family.modules_unpin_aria' : 'family.modules_pin_aria', { name: m.name })}
                                                data-testid={`pin-${m.id}`}
                                                className={cn(
                                                    'flex size-9 shrink-0 items-center justify-center rounded-full disabled:opacity-40',
                                                    isPinned ? 'bg-accent-strong text-accent-strong-foreground' : 'bg-surface text-muted-foreground',
                                                )}
                                            >
                                                <Pin className="size-4" fill={isPinned ? 'currentColor' : 'none'} />
                                            </button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                )}

                {visibleOff.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="text-muted-foreground px-1 font-mono text-[10.5px] font-semibold tracking-[0.8px] uppercase">
                            {t('family.modules_off_header')} {off.length}
                        </h2>
                        <ul
                            className="bg-surface-card divide-border/60 flex flex-col divide-y overflow-hidden rounded-2xl border"
                            data-testid="modules-off"
                        >
                            {off.filter(matches).map((m) => {
                                const Icon = iconFor(m.id);
                                return (
                                    <li key={m.id} data-testid={`module-${m.id}`} className="flex items-center gap-3 px-4 py-3">
                                        <span className="bg-surface text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-xl">
                                            <Icon className="size-4.5" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="text-primary block truncate text-[15px] font-bold">{m.name}</span>
                                            <span className="text-muted-foreground block truncate text-[12.5px]">{m.description}</span>
                                        </span>
                                        {canManage && (
                                            <button
                                                type="button"
                                                onClick={() => turnOn(m)}
                                                data-testid={`toggle-${m.id}`}
                                                className="bg-primary text-primary-foreground shrink-0 rounded-full px-4 py-1.5 text-sm font-semibold"
                                            >
                                                {t('family.modules_turn_on')}
                                            </button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                )}

                {visibleOn.length === 0 && visibleOff.length === 0 && (
                    <p className="text-muted-foreground px-1 text-sm">{t('family.modules_no_matches')}</p>
                )}
            </div>
        </AppLayout>
    );
}
