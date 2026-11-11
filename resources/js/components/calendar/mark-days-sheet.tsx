import { Check } from 'lucide-react';
import { useState } from 'react';

import { DayGrid, type DayMark, type EventDays } from '@/components/calendar/event-days';
import { IconPicker } from '@/components/icon-picker';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { addDays, daysBetween, parseDay } from '@/lib/calendar';
import { appLocale, useT } from '@/lib/i18n';
import { save } from '@/lib/save';

// Weekday sets for the pattern chips (JS getDay: 0 = Sunday). "Every Sat & Sun", "Wed & Fri": the two patterns the
// design offers; the grid is how anything else is picked.
const WEEKENDS = [6, 0];
const WED_FRI = [3, 5];

// Mark days (design C17): name the mark once, pick a pattern, then tap any day to add or drop it. One save marks
// every day, so a 40-day fast takes seconds instead of a trip into each day. With `mark` it edits that mark instead
// (rename, change its days, delete); `preselect` starts a new mark with one day already picked (from a day's "+ Mark").
export function MarkDaysSheet({
    open,
    onOpenChange,
    eventId,
    days,
    mark,
    preselect,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    eventId: number;
    days: EventDays;
    mark?: DayMark | null;
    preselect?: number | null;
}) {
    const t = useT();
    const total = daysBetween(days.start, days.end) + 1;
    const initial = () => ({
        name: mark?.name ?? '',
        icon: mark?.icon ?? 'tag',
        picked: new Set(mark ? mark.days.filter((i) => i < total) : preselect != null ? [preselect] : []),
    });
    const [form, setForm] = useState(initial);
    const [saving, setSaving] = useState(false);
    const [failed, setFailed] = useState(false);

    // Start fresh each time the sheet opens (the "adjust state on prop change" pattern the event sheet uses).
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setForm(initial());
            setSaving(false);
            setFailed(false);
        }
    }

    const toggle = (idx: number) =>
        setForm((f) => {
            const picked = new Set(f.picked);
            if (!picked.delete(idx)) picked.add(idx);
            return { ...f, picked };
        });
    // Every day index of the event that falls on one of these weekdays.
    const matching = (weekdays: number[]) =>
        Array.from({ length: total }, (_, k) => k).filter((k) => weekdays.includes(parseDay(addDays(days.start, k)).getDay()));
    // A pattern replaces the picks with those days (the chip lights up while the picks are exactly them).
    const pattern = (weekdays: number[]) => setForm((f) => ({ ...f, picked: new Set(matching(weekdays)) }));
    const sameDays = (weekdays: number[]) => {
        const want = matching(weekdays);
        return want.length > 0 && want.length === form.picked.size && want.every((k) => form.picked.has(k));
    };

    const url = `/m/calendar/events/${eventId}/marks`;
    const finish = (p: Promise<'ok' | 'rejected' | 'offline'>) => {
        setSaving(true);
        setFailed(false);
        void p.then((r) => {
            setSaving(false);
            if (r === 'ok') onOpenChange(false);
            else setFailed(true);
        });
    };
    const body = () => ({ name: form.name.trim(), icon: form.icon, days: [...form.picked].sort((a, b) => a - b) });
    const submit = () => finish(mark ? save('patch', `${url}/${mark.id}`, body()) : save('post', url, body()));
    const remove = () => mark && confirm(t('calendar.mark_delete_confirm')) && finish(save('delete', `${url}/${mark.id}`));

    const chip = (on: boolean) =>
        `min-h-9 rounded-full border px-3.5 text-sm font-semibold transition-colors ${on ? 'border-primary bg-accent-subtle text-primary' : 'border-border text-muted-foreground'}`;

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={mark ? t('calendar.mark_edit') : t('calendar.mark_days')}
            footer={
                <button
                    type="button"
                    disabled={saving || !form.name.trim() || (!mark && form.picked.size === 0)}
                    onClick={submit}
                    className="bg-accent-strong text-accent-strong-foreground border-primary w-full rounded-full border-2 py-3.5 text-lg font-bold disabled:opacity-60"
                >
                    {mark ? t('calendar.save') : t('calendar.mark_save', { count: form.picked.size })}
                </button>
            }
        >
            <div className="flex flex-col gap-5">
                <div className="flex items-center gap-3">
                    <IconPicker
                        value={form.icon}
                        onChange={(icon) => setForm((f) => ({ ...f, icon: icon ?? 'tag' }))}
                        ariaLabel={t('calendar.mark_icon')}
                        className="bg-sun-soft text-ink size-14 rounded-2xl"
                        iconClassName="size-6"
                        allowClear={false}
                    />
                    <input
                        value={form.name}
                        onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                        maxLength={60}
                        placeholder={t('calendar.mark_name_placeholder')}
                        aria-label={t('calendar.mark_name')}
                        className="bg-surface text-primary placeholder:text-muted-foreground min-w-0 flex-1 rounded-2xl px-4 py-3.5 text-lg font-bold outline-none"
                    />
                </div>

                <section className="flex flex-col gap-2">
                    <h3 className="text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                        {t('calendar.which_days')}
                    </h3>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => pattern(WEEKENDS)}
                            aria-pressed={sameDays(WEEKENDS)}
                            className={chip(sameDays(WEEKENDS))}
                        >
                            {t('calendar.pat_weekends')}
                        </button>
                        <button type="button" onClick={() => pattern(WED_FRI)} aria-pressed={sameDays(WED_FRI)} className={chip(sameDays(WED_FRI))}>
                            {t('calendar.pat_wed_fri')}
                        </button>
                        <button type="button" onClick={() => setForm((f) => ({ ...f, picked: new Set() }))} className={chip(false)}>
                            {t('calendar.pat_clear')}
                        </button>
                    </div>
                    <DayGrid
                        start={days.start}
                        end={days.end}
                        onPick={(idx) => toggle(idx)}
                        label={(idx, date) => parseDay(date).toLocaleDateString(appLocale(), { weekday: 'long', day: 'numeric', month: 'long' })}
                        cell={(idx) => {
                            const on = form.picked.has(idx);
                            return { on, body: <span className="flex h-4 items-center">{on && <Check className="size-3.5" />}</span> };
                        }}
                    />
                    <p className="text-muted-foreground px-1 text-sm">{t('calendar.mark_hint')}</p>
                </section>

                {failed && <p className="text-destructive px-1 text-sm">{t('calendar.mark_failed')}</p>}
                {mark && (
                    <button
                        type="button"
                        onClick={remove}
                        disabled={saving}
                        className="text-destructive self-start px-1 text-sm font-semibold underline underline-offset-4"
                    >
                        {t('calendar.mark_delete')}
                    </button>
                )}
            </div>
        </ResponsiveModal>
    );
}
