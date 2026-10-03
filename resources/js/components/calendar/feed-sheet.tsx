import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useT } from '@/lib/i18n';

// Private ICS link for Google/Apple Calendar. Shown whenever it exists (stored as-is, like guest links);
// reset issues a new one and the old URL stops working.
export function FeedSheet({ open, onOpenChange, url }: { open: boolean; onOpenChange: (v: boolean) => void; url: string | null }) {
    const t = useT();
    const [copied, setCopied] = useState(false);
    const reset = () => router.post('/m/calendar/feed', {}, { preserveScroll: true, only: ['feedUrl'] });
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url ?? '');
            setCopied(true);
        } catch {
            /* clipboard blocked: the field is selectable */
        }
    };
    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('calendar.subscribe_title')}
            footer={
                <Button variant={url ? 'outline' : 'default'} className="w-full" onClick={reset}>
                    {url ? t('calendar.subscribe_reset') : t('calendar.subscribe_create')}
                </Button>
            }
        >
            <div className="flex flex-col gap-3">
                <p className="text-muted-foreground text-sm">{t('calendar.subscribe_help')}</p>
                {url && (
                    <div className="flex gap-2">
                        <Input readOnly value={url} className="text-base" onFocus={(e) => e.target.select()} />
                        <Button onClick={copy}>{copied ? t('calendar.copied') : t('calendar.copy')}</Button>
                    </div>
                )}
            </div>
        </ResponsiveModal>
    );
}
