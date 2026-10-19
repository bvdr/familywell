import { router, useForm } from '@inertiajs/react';
import { Check, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { ToggleRow } from '@/components/toggle-row';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useLocale, useT } from '@/lib/i18n';
import { itemTone, LIST_COLOR_KEYS, listColor } from '@/lib/tasks';

// "5 minutes ago" / "now" in the app language, lower-case so it reads inside "Updated :time".
const ago = (iso: string, locale: string) => {
    const mins = Math.round((new Date(iso).getTime() - Date.now()) / 60000);
    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    return Math.abs(mins) < 60
        ? rtf.format(mins, 'minute')
        : Math.abs(mins) < 1440
          ? rtf.format(Math.round(mins / 60), 'hour')
          : rtf.format(Math.round(mins / 1440), 'day');
};

export type Subscription = {
    id: number;
    name: string;
    color: string | null;
    shared: boolean;
    enabled: boolean;
    error: 'blocked' | 'unreachable' | 'not_calendar' | null;
    syncedAt: string | null;
    mine: boolean;
    owner: string | null;
};

// The calendar page's "Calendars" sheet, two directions:
//  1. Other calendars shown here (issue #222): ICS / webcal subscriptions, read-only, refreshed hourly. Only
//     whoever added one can change it; "Show to the family" shares its events (never its link).
//  2. This calendar elsewhere: the private ICS link for Google/Apple. Shown whenever it exists (stored as-is,
//     like guest links); reset issues a new one and the old URL stops working.
export function FeedSheet({
    open,
    onOpenChange,
    url,
    subscriptions,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    url: string | null;
    subscriptions: Subscription[];
}) {
    const t = useT();
    const locale = useLocale();
    const [copied, setCopied] = useState(false);
    const [adding, setAdding] = useState(false);
    const reset = () => router.post('/m/calendar/feed', {}, { preserveScroll: true, only: ['feedUrl'] });
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url ?? '');
            setCopied(true);
        } catch {
            /* clipboard blocked: the field is selectable */
        }
    };
    const patch = (s: Subscription, data: Partial<Pick<Subscription, 'enabled' | 'shared'>>) =>
        router.patch(`/m/calendar/subscriptions/${s.id}`, data, { preserveScroll: true, preserveState: true });
    const remove = (s: Subscription) =>
        confirm(t('calendar.sub_remove_confirm', { name: s.name })) &&
        router.delete(`/m/calendar/subscriptions/${s.id}`, { preserveScroll: true, preserveState: true });

    return (
        <ResponsiveModal open={open} onOpenChange={onOpenChange} title={t('calendar.calendars_title')}>
            <div className="flex flex-col gap-6">
                <section className="flex flex-col gap-3">
                    <div>
                        <h3 className="text-primary font-medium">{t('calendar.sub_title')}</h3>
                        <p className="text-muted-foreground text-sm">{t('calendar.sub_help')}</p>
                    </div>

                    {subscriptions.map((s) => (
                        <div key={s.id} className="bg-surface-card flex flex-col gap-3 rounded-2xl border p-3">
                            <div className="flex items-center gap-3">
                                <span className={`size-3 shrink-0 rounded-full ${itemTone(s.color)}`} />
                                <span className="min-w-0 flex-1">
                                    <span className="text-primary block truncate font-medium">{s.name}</span>
                                    <span
                                        className={`block truncate text-xs ${s.error ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground'}`}
                                    >
                                        {s.error
                                            ? t(`calendar.sub_error_${s.error}`)
                                            : !s.mine
                                              ? t('calendar.sub_shared_by', { name: s.owner ?? '' })
                                              : s.syncedAt
                                                ? t('calendar.sub_updated', { time: ago(s.syncedAt, locale) })
                                                : ''}
                                    </span>
                                </span>
                                {s.mine && (
                                    <Button variant="ghost" size="icon" aria-label={t('calendar.sub_remove')} onClick={() => remove(s)}>
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </div>
                            {s.mine && (
                                <>
                                    <ToggleRow label={t('calendar.sub_show')} on={s.enabled} onToggle={() => patch(s, { enabled: !s.enabled })} />
                                    <ToggleRow
                                        label={t('calendar.sub_share')}
                                        hint={t('calendar.sub_share_hint')}
                                        on={s.shared}
                                        onToggle={() => patch(s, { shared: !s.shared })}
                                    />
                                </>
                            )}
                        </div>
                    ))}

                    {adding ? (
                        <AddSubscription onDone={() => setAdding(false)} />
                    ) : (
                        <Button variant="outline" onClick={() => setAdding(true)}>
                            <Plus className="size-4" /> {t('calendar.sub_add')}
                        </Button>
                    )}
                </section>

                <section className="flex flex-col gap-3 border-t pt-5">
                    <div>
                        <h3 className="text-primary font-medium">{t('calendar.subscribe_title')}</h3>
                        <p className="text-muted-foreground text-sm">{t('calendar.subscribe_help')}</p>
                    </div>
                    {url && (
                        <div className="flex gap-2">
                            <Input readOnly value={url} className="text-base" onFocus={(e) => e.target.select()} />
                            <Button onClick={copy}>{copied ? t('calendar.copied') : t('calendar.copy')}</Button>
                        </div>
                    )}
                    <Button variant={url ? 'outline' : 'default'} onClick={reset}>
                        {url ? t('calendar.subscribe_reset') : t('calendar.subscribe_create')}
                    </Button>
                </section>
            </div>
        </ResponsiveModal>
    );
}

// Name + link + colour + "show to the family". The server fetches the link before saving, so a wrong one is an
// error under the field instead of an empty calendar later. The browser's timezone goes along: imported times are
// shown in it (the app has no timezone setting of its own).
function AddSubscription({ onDone }: { onDone: () => void }) {
    const t = useT();
    const form = useForm({
        name: '',
        url: '',
        color: 'blue' as string | null,
        shared: false,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    });
    const submit = () => form.post('/m/calendar/subscriptions', { preserveScroll: true, onSuccess: onDone });

    return (
        <div className="flex flex-col gap-3 rounded-2xl border p-3">
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="sub-name">{t('calendar.sub_name')}</Label>
                <Input
                    id="sub-name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder={t('calendar.sub_name_placeholder')}
                    className="text-base"
                    autoFocus
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="sub-url">{t('calendar.sub_url')}</Label>
                <Input
                    id="sub-url"
                    type="url"
                    inputMode="url"
                    autoCapitalize="off"
                    autoCorrect="off"
                    value={form.data.url}
                    onChange={(e) => form.setData('url', e.target.value)}
                    placeholder="webcal://…"
                    className="text-base"
                />
                <InputError message={form.errors.url} />
            </div>
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={t('calendar.icon_color')}>
                {LIST_COLOR_KEYS.map((c) => (
                    <button
                        key={c}
                        type="button"
                        role="radio"
                        aria-checked={form.data.color === c}
                        aria-label={c}
                        onClick={() => form.setData('color', c)}
                        className={`flex size-9 items-center justify-center rounded-full ${listColor(c)} ${form.data.color === c ? 'ring-primary ring-2 ring-offset-2' : ''}`}
                    >
                        {form.data.color === c && <Check className="size-4" />}
                    </button>
                ))}
            </div>
            <ToggleRow
                label={t('calendar.sub_share')}
                hint={t('calendar.sub_share_hint')}
                on={form.data.shared}
                onToggle={() => form.setData('shared', !form.data.shared)}
            />
            <div className="flex gap-2">
                <Button variant="outline" className="flex-1" onClick={onDone}>
                    {t('common.cancel')}
                </Button>
                <Button className="flex-1" disabled={form.processing || !form.data.name.trim() || !form.data.url.trim()} onClick={submit}>
                    {form.processing ? t('calendar.sub_adding') : t('calendar.sub_add_button')}
                </Button>
            </div>
        </div>
    );
}
