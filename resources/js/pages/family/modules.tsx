import { Head, router } from '@inertiajs/react';
import { Bell, Blocks, Repeat, Star, type LucideIcon } from 'lucide-react';

import { MODULE_ITEMS } from '@/components/mobile-tab-bar';
import { SettingsGroup, SettingsPage, SettingsRow, SettingsSwitch } from '@/components/settings-ui';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Module = { id: string; name: string; tagline: string; requires: string[]; enabled: boolean };
type Props = { catalog: Module[]; canManage: boolean; familyName: string };

// Icons come from the nav's MODULE_ITEMS so a module looks the same here as in the tab bar and More. Add-ons and
// notifications have no nav entry (no page of their own), so they get theirs here.
const EXTRA_ICONS: Record<string, LucideIcon> = { important_todos: Star, recurring_todos: Repeat, notifications: Bell };
const iconFor = (id: string): LucideIcon => MODULE_ITEMS.find((i) => i.key === id)?.icon ?? EXTRA_ICONS[id] ?? Blocks;
// Nav order (Shopping, Tasks, Calendar, ...) rather than the server's alphabetical one; anything not in the nav last.
const rank = (id: string) => {
    const i = MODULE_ITEMS.findIndex((m) => m.key === id);
    return i === -1 ? MODULE_ITEMS.length : i;
};

// Modules (design Y2): one plain switch per module with a one-line "what it's for". A module that requires exactly
// one other (module.json `requires`, e.g. Recurring to-dos -> Tasks) is an add-on and sits indented under it, so
// "needs Tasks" explains itself. Pinning to the tab bar lives in More (long-press), not here.
export default function Modules({ catalog, canManage, familyName }: Props) {
    const t = useT();
    const enabled = useOptimistic<boolean>();
    const stored = (m: Module) => enabled.get(m.id, m.enabled);
    const byId = new Map(catalog.map((m) => [m.id, m]));
    const parentOf = (m: Module) => (m.requires.length === 1 ? byId.get(m.requires[0]) : undefined);

    const toggle = (m: Module) => {
        const next = !stored(m);
        enabled.set(m.id, next);
        const opts = { preserveScroll: true, preserveState: true, onSuccess: () => enabled.clear(m.id), onError: () => enabled.clear(m.id) };
        if (next) router.post(`/family/modules/${m.id}/enable`, {}, opts);
        else router.delete(`/family/modules/${m.id}`, opts);
    };

    const sorted = [...catalog].sort((a, b) => rank(a.id) - rank(b.id) || a.name.localeCompare(b.name));
    const tops = sorted.filter((m) => !parentOf(m));
    const addonsOf = (p: Module) => sorted.filter((m) => parentOf(m)?.id === p.id);

    const row = (m: Module, parent?: Module) => {
        // An add-on whose module is off does nothing (its hooks hang off the parent), so it reads as off and can't be
        // switched until the parent is on. Its own stored state is kept and comes back with the parent.
        const blocked = !!parent && !stored(parent);
        const on = stored(m) && !blocked;
        const Icon = iconFor(m.id);
        return (
            <SettingsRow
                key={m.id}
                testId={`module-${m.id}`}
                className={cn(parent && 'bg-surface/60 pl-12')}
                lead={
                    // The tile picks up the sun when on (accent-strong is the skin's sun), stays plain when off.
                    <span
                        className={cn(
                            'flex shrink-0 items-center justify-center rounded-xl',
                            parent ? 'size-9' : 'size-11',
                            on ? 'bg-accent-strong/25 text-primary' : 'bg-surface text-muted-foreground',
                        )}
                    >
                        <Icon className={parent ? 'size-4' : 'size-5'} />
                    </span>
                }
                title={m.name}
                sub={blocked && parent ? t('settings_family.modules_needs', { name: parent.name }) : m.tagline}
                muted={blocked}
                right={<SettingsSwitch on={on} onToggle={() => toggle(m)} label={m.name} disabled={!canManage || blocked} />}
            />
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('settings_hub.modules'), href: '/family/modules' }]}>
            <Head title={t('settings_hub.modules')} />
            <SettingsPage
                back={{ href: '/settings/family', label: familyName }}
                title={t('settings_hub.modules')}
                lede={t('settings_family.modules_lede')}
            >
                {!canManage && (
                    <p className="bg-surface text-muted-foreground rounded-2xl px-4 py-3 text-sm" data-testid="modules-admin-note">
                        {t('settings_family.modules_admin_note')}
                    </p>
                )}
                <SettingsGroup>{tops.flatMap((m) => [row(m), ...addonsOf(m).map((a) => row(a, m))])}</SettingsGroup>
            </SettingsPage>
        </AppLayout>
    );
}
