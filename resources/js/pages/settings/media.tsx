import { Head } from '@inertiajs/react';
import { ImageOff, Trash2, X } from 'lucide-react';
import { useState } from 'react';

import { SettingsGroup, SettingsPage } from '@/components/settings-ui';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { type BreadcrumbItem } from '@/types';

type MediaItem = {
    type: 'cover' | 'receipt';
    id: number;
    name: string;
    thumbUrl: string;
    fullUrl: string;
};

// Settings > Media: every image the family uploaded (project covers, receipts), in the settings look: back pill, big
// title, then one mono-labelled rounded group per kind holding a thumbnail grid. Tap to preview, delete from the
// tile or the preview.
export default function Media({ items, family }: { items: MediaItem[]; family: { name: string } }) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('settings.media'), href: '/settings/media' }];
    const [preview, setPreview] = useState<MediaItem | null>(null);

    // Optimistic removal: the tile disappears on confirm and only comes back if the delete fails.
    const removed = useOptimistic<boolean>();
    const kind = (m: MediaItem) => t(`settings.media_${m.type}`);
    const key = (m: MediaItem) => `${m.type}-${m.id}`;
    const visible = items.filter((m) => !removed.get(key(m), false));
    const remove = (m: MediaItem) => {
        // One full sentence per type: splicing a lowercased label into a shared sentence broke gender and capitals (German nouns).
        if (!confirm(t(`settings.confirm_delete_${m.type}`))) return;
        removed.set(key(m), true);
        void save('delete', route('media.destroy', { type: m.type, id: m.id })).then(() => removed.clear(key(m)));
    };
    const groups = (['cover', 'receipt'] as const)
        .map((type) => ({ type, items: visible.filter((m) => m.type === type) }))
        .filter((g) => g.items.length);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.media')} />
            <SettingsPage back={{ href: '/settings/family', label: family.name }} title={t('settings.media')} lede={t('settings.media_desc')}>
                {groups.length === 0 ? (
                    <SettingsGroup>
                        <div className="text-muted-foreground flex flex-col items-center gap-2 p-10 text-center">
                            <ImageOff className="size-6" />
                            <p className="text-sm">{t('settings.no_media_empty')}</p>
                        </div>
                    </SettingsGroup>
                ) : (
                    groups.map((g) => (
                        <SettingsGroup key={g.type} label={t(`settings_data.media_group_${g.type}`)} aside={g.items.length}>
                            <div className="grid grid-cols-2 gap-3 p-3 sm:grid-cols-3">
                                {g.items.map((m) => (
                                    <div key={key(m)} className="group bg-surface relative min-w-0 overflow-hidden rounded-xl">
                                        <button type="button" onClick={() => setPreview(m)} className="block aspect-square w-full">
                                            <img src={m.thumbUrl} alt={m.name} className="h-full w-full object-cover" loading="lazy" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => remove(m)}
                                            aria-label={t('settings.delete_x', { name: m.name })}
                                            className="bg-background/80 text-muted-foreground hover:text-destructive absolute top-2 right-2 flex size-8 items-center justify-center rounded-full opacity-0 backdrop-blur transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                        <div className="text-primary truncate px-2.5 py-2 text-sm font-medium">{m.name}</div>
                                    </div>
                                ))}
                            </div>
                        </SettingsGroup>
                    ))
                )}
            </SettingsPage>

            {preview && (
                <div className="fixed inset-0 z-[60] flex flex-col bg-black/90 p-4" role="dialog" aria-modal="true" onClick={() => setPreview(null)}>
                    <div className="flex items-center justify-between text-white">
                        <div className="min-w-0">
                            <div className="truncate font-medium">{preview.name}</div>
                            <div className="text-xs text-white/60">{kind(preview)}</div>
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    remove(preview);
                                    setPreview(null);
                                }}
                                aria-label={t('common.delete')}
                                className="flex size-9 items-center justify-center rounded-full bg-white/10 hover:bg-white/20"
                            >
                                <Trash2 className="size-4.5" />
                            </button>
                            <button
                                type="button"
                                onClick={() => setPreview(null)}
                                aria-label={t('settings.close')}
                                className="flex size-9 items-center justify-center rounded-full bg-white/10 hover:bg-white/20"
                            >
                                <X className="size-5" />
                            </button>
                        </div>
                    </div>
                    <div className="flex min-h-0 flex-1 items-center justify-center py-4">
                        <img src={preview.fullUrl} alt={preview.name} className="max-h-full max-w-full rounded-lg object-contain" />
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
