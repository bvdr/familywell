import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';

import { SettingsGroup } from '@/components/settings-ui';
import { useOptimistic } from '@/hooks/use-optimistic';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Swatch = { bg: string; card: string; accent: string; ink: string };
export type FamilySkin = { id: string; name: string; swatch: Swatch; swatchDark: Swatch };

/**
 * A mini screen in a skin's own colours (the design's caption: "previews are mini screens, not swatches"): page
 * ground, an ink title bar, two cards and the accent dot. The colours are the skin's theme.json tokens (data from
 * ThemeTokens::available), not hard-coded hex. Also drawn by the Light / Dark / System cards on Appearance. Pass the
 * padding in `className`: a % padding resolves against the containing block's WIDTH, so it only suits a box that
 * fills its parent (the mode cards), not the small tile in a row.
 */
export function SkinScreen({ s, className }: { s: Swatch; className?: string }) {
    return (
        <span className={cn('flex flex-col gap-[9%]', className)} style={{ background: s.bg }} aria-hidden>
            <span className="h-[7%] w-1/2 rounded-full" style={{ background: s.ink }} />
            <span className="h-[20%] rounded-[4px]" style={{ background: s.card }} />
            <span className="h-[20%] rounded-[4px]" style={{ background: s.card }} />
            <span className="aspect-square h-[14%] self-start rounded-full" style={{ background: s.accent }} />
        </span>
    );
}

/**
 * "FAMILY SKIN · EVERYONE" (boards M4/Y5): the family's skin, the same for everyone. Admins pick (PATCH family/theme,
 * optimistic tick; the colours change when the reload brings the new skin CSS, applied by the page's useSkinCss);
 * everyone else sees it read-only with a note. Used by Appearance and Family name & look.
 */
export function FamilySkinList({
    themes,
    value,
    defaultTheme,
    canManage,
}: {
    themes: FamilySkin[];
    value: string;
    defaultTheme: string;
    canManage: boolean;
}) {
    const t = useT();
    const picked = useOptimistic<string>();
    const current = picked.get('theme', value);
    const pick = (id: string) => {
        if (id === current) return;
        picked.set('theme', id);
        router.patch(
            route('family.theme'),
            { theme: id },
            { preserveScroll: true, preserveState: true, onSuccess: () => picked.clear('theme'), onError: () => picked.clear('theme') },
        );
    };
    // The default skin first, the rest as ThemeTokens lists them.
    const ordered = [...themes].sort((a, b) => Number(b.id === defaultTheme) - Number(a.id === defaultTheme));

    return (
        <div className="flex flex-col gap-2.5">
            <SettingsGroup label={t('settings_look.skin_label')}>
                {ordered.map((s) => {
                    const on = s.id === current;
                    // A skin without its own line (a newly added one) just shows its name.
                    const desc = t(`settings_look.skin_${s.id}`);
                    const sub = [s.id === defaultTheme && t('settings_look.default_tag'), desc !== `settings_look.skin_${s.id}` && desc]
                        .filter(Boolean)
                        .join(' · ');
                    return (
                        <button
                            key={s.id}
                            type="button"
                            aria-pressed={on}
                            disabled={!canManage}
                            onClick={() => pick(s.id)}
                            className="enabled:hover:bg-muted/50 flex min-h-14 w-full items-center gap-3.5 border-b px-4 py-3 text-left last:border-b-0"
                        >
                            <SkinScreen s={s.swatch} className="size-11 shrink-0 overflow-hidden rounded-xl border p-1.5" />
                            <span className="min-w-0 flex-1">
                                <span className="text-primary block truncate text-[17px] leading-snug">{s.name}</span>
                                {sub && <span className="text-muted-foreground block text-sm leading-snug">{sub}</span>}
                            </span>
                            {on && <Check className="text-primary size-5 shrink-0" />}
                        </button>
                    );
                })}
            </SettingsGroup>
            {!canManage && <p className="text-muted-foreground px-1 text-sm">{t('settings_look.skin_admin_only')}</p>}
        </div>
    );
}
