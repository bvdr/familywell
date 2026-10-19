import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

import { AgendaItem } from '@/components/calendar/month-view';
import { LucideIcon } from '@/components/lucide-icon';
import { daysOf, itemKey, itemsOn, parseDay, ymd, type CalItem } from '@/lib/calendar';
import { useLocale, useT } from '@/lib/i18n';
import { itemTone } from '@/lib/tasks';

// Week view (calendar 1.1 preview): Monday..Sunday with every item of each day. Desktop: seven columns side by side;
// phones: the days stacked as a list, since seven columns of cards don't fit 390px. No hour grid on purpose: the
// family calendar is mostly all-day things (trips, birthdays, due dates), and timed ones show their time in the card.
export function WeekView({ items, range, onAdd }: { items: CalItem[]; range: { from: string; to: string }; onAdd: (day: string) => void }) {
    const t = useT();
    const locale = useLocale();
    const today = ymd(new Date());

    return (
        <div className="grid gap-3 md:grid-cols-7 md:gap-2">
            {daysOf(range.from, range.to).map((day) => {
                const dayItems = itemsOn(items, day);
                const d = parseDay(day);
                return (
                    <section key={day} className="flex min-w-0 flex-col gap-2">
                        <div className="flex items-center gap-2">
                            <span
                                className={`flex size-8 items-center justify-center rounded-full text-sm tabular-nums ${day === today ? 'bg-primary text-primary-foreground font-semibold' : 'text-primary'}`}
                            >
                                {d.getDate()}
                            </span>
                            <span className="text-muted-foreground mr-auto text-sm first-letter:uppercase">
                                {d.toLocaleDateString(locale, { weekday: 'long' })}
                            </span>
                            <button
                                type="button"
                                aria-label={t('calendar.new')}
                                onClick={() => onAdd(day)}
                                className="text-muted-foreground hover:text-primary rounded-full p-1"
                            >
                                <Plus className="size-4" />
                            </button>
                        </div>
                        {dayItems.length > 0 ? (
                            // Phones get the full agenda card (one per line, room for people); desktop's seventh-of-a-row
                            // columns get a compact card whose title wraps instead of truncating after one word.
                            dayItems.map((item) => (
                                <div key={itemKey(item)} className="contents">
                                    <AgendaItem item={item} day={day} className="md:hidden" />
                                    <WeekItem item={item} />
                                </div>
                            ))
                        ) : (
                            <p className="text-muted-foreground px-1 text-sm md:hidden">{t('calendar.nothing')}</p>
                        )}
                    </section>
                );
            })}
        </div>
    );
}

function WeekItem({ item }: { item: CalItem }) {
    const t = useT();
    return (
        <Link
            href={item.href}
            className="bg-surface-card hover:border-accent hidden items-start gap-2 rounded-xl border p-2 transition-colors md:flex"
        >
            <span className={`flex size-6 shrink-0 items-center justify-center rounded-md ${itemTone(item.color)}`}>
                <LucideIcon name={item.icon} className="size-3.5" />
            </span>
            <span className="min-w-0 flex-1">
                <span
                    className={`line-clamp-2 text-sm leading-snug font-medium break-words ${item.done ? 'text-muted-foreground line-through' : 'text-primary'}`}
                >
                    {item.title}
                </span>
                <span className="text-muted-foreground block text-xs">
                    {item.startTime ? `${item.startTime}–${item.endTime}` : t('calendar.all_day')}
                </span>
            </span>
        </Link>
    );
}
