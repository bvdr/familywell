import { Head, router } from '@inertiajs/react';
import { ImageOff, Trash2, X } from 'lucide-react';
import { useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';

type MediaItem = {
    type: 'cover' | 'receipt';
    id: number;
    name: string;
    kind: string;
    thumbUrl: string;
    fullUrl: string;
};

export default function Media({ items }: { items: MediaItem[] }) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('settings.media'), href: '/settings/media' }];
    const [preview, setPreview] = useState<MediaItem | null>(null);

    const remove = (m: MediaItem) => {
        if (!confirm(t('settings.confirm_delete_media', { kind: m.kind.toLowerCase() }))) return;
        router.delete(route('media.destroy', { type: m.type, id: m.id }), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.media')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('settings.media')} description={t('settings.media_desc')} />

                    {items.length === 0 ? (
                        <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                            <ImageOff className="size-6" />
                            <p className="text-sm">{t('settings.no_media_empty')}</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            {items.map((m) => (
                                <div key={`${m.type}-${m.id}`} className="group bg-surface-card relative overflow-hidden rounded-xl border">
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
                                    <div className="p-2">
                                        <div className="text-primary truncate text-xs font-medium">{m.name}</div>
                                        <div className="text-text-muted text-[11px]">{m.kind}</div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </SettingsLayout>

            {preview && (
                <div
                    className="fixed inset-0 z-[60] flex flex-col bg-black/90 p-4"
                    role="dialog"
                    aria-modal="true"
                    onClick={() => setPreview(null)}
                >
                    <div className="flex items-center justify-between text-white">
                        <div className="min-w-0">
                            <div className="truncate font-medium">{preview.name}</div>
                            <div className="text-xs text-white/60">{preview.kind}</div>
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
