import { router, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { MODULES } from '@/components/your-modules';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const MAX = 4;

// The modules this person pinned (shared `pins` prop, users.pinned_modules) that are still on; null = never pinned.
export function usePinned(): string[] | null {
    const { pins, modules } = usePage().props as { pins?: string[] | null; modules?: { enabled?: string[] } };
    if (!pins) return null;
    const enabled = modules?.enabled ?? [];
    const on = pins.filter((id) => MODULES[id] && enabled.includes(id));
    return on.length ? on : null;
}

// "Change your pins anytime": up to four of the family's modules, saved per person (PinnedModulesController).
export function PinModulesModal({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const t = useT();
    const { modules } = usePage().props as { modules?: { enabled?: string[] } };
    const pinned = usePinned();
    const ids = Object.keys(MODULES).filter((id) => (modules?.enabled ?? []).includes(id));
    const [picked, setPicked] = useState<string[]>(pinned ?? []);
    const [busy, setBusy] = useState(false);
    // Start from what's pinned each time it opens, not from an earlier unsaved try.
    useEffect(() => {
        if (open) setPicked(pinned ?? []);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const toggle = (id: string) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : p.length >= MAX ? p : [...p, id]));
    const save = () =>
        router.put(
            route('pins.update'),
            { modules: picked },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) },
        );

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('dashboard.pins_title')}
            description={t('dashboard.pins_desc', { max: MAX })}
            footer={
                <Button className="w-full" disabled={busy || picked.length === 0} onClick={save}>
                    {t('common.save')}
                </Button>
            }
        >
            <div className="grid grid-cols-2 gap-2" role="group" aria-label={t('dashboard.pins_title')}>
                {ids.map((id) => {
                    const { icon: Icon } = MODULES[id];
                    const on = picked.includes(id);
                    return (
                        <button
                            key={id}
                            type="button"
                            aria-pressed={on}
                            onClick={() => toggle(id)}
                            className={cn(
                                'flex items-center gap-2.5 rounded-xl border p-3 text-left text-sm font-semibold',
                                on ? 'border-primary bg-accent-subtle' : 'bg-surface-card',
                            )}
                        >
                            <Icon className="size-4 shrink-0" />
                            <span className="min-w-0 flex-1 truncate">{t(`onboarding.module_${id}`)}</span>
                            {on && <Check className="size-4 shrink-0" />}
                        </button>
                    );
                })}
            </div>
        </ResponsiveModal>
    );
}
