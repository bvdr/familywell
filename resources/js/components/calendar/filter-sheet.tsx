import { Button } from '@/components/ui/button';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { type CalFilter } from '@/lib/calendar';
import { useT } from '@/lib/i18n';

type Named = { id: number; name: string };

// People + source chips. Empty selection = show everything. "Bogdan + Timeline" is the PTO view.
export function FilterSheet({
    open,
    onOpenChange,
    value,
    onChange,
    sources,
    members,
    contacts,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    value: CalFilter;
    onChange: (f: CalFilter) => void;
    sources: string[];
    members: Named[];
    contacts: Named[];
}) {
    const t = useT();
    const toggle = (key: 'people' | 'sources', v: string) =>
        onChange({ ...value, [key]: value[key].includes(v) ? value[key].filter((x) => x !== v) : [...value[key], v] });
    // Same chip look as EventSheet's people picker (border-primary/bg-accent-subtle when on).
    const chip = (on: boolean) =>
        `rounded-full border px-3 py-1.5 text-sm transition-colors ${on ? 'border-primary bg-accent-subtle text-primary' : 'border-border text-muted-foreground'}`;

    return (
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('calendar.filters')}
            footer={
                <Button variant="outline" className="w-full" onClick={() => onChange({ people: [], sources: [] })}>
                    {t('calendar.clear')}
                </Button>
            }
        >
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <span className="text-muted-foreground text-sm">{t('calendar.sources')}</span>
                    <div className="flex flex-wrap gap-2">
                        {sources.map((s) => (
                            <button
                                key={s}
                                type="button"
                                aria-pressed={value.sources.includes(s)}
                                className={chip(value.sources.includes(s))}
                                onClick={() => toggle('sources', s)}
                            >
                                {t(`calendar.source_${s}`)}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="flex flex-col gap-2">
                    <span className="text-muted-foreground text-sm">{t('calendar.members')}</span>
                    <div className="flex flex-wrap gap-2">
                        {members.map((m) => (
                            <button
                                key={m.id}
                                type="button"
                                aria-pressed={value.people.includes(`user:${m.id}`)}
                                className={chip(value.people.includes(`user:${m.id}`))}
                                onClick={() => toggle('people', `user:${m.id}`)}
                            >
                                {m.name}
                            </button>
                        ))}
                    </div>
                </div>
                {contacts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <span className="text-muted-foreground text-sm">{t('calendar.contacts')}</span>
                        <div className="flex flex-wrap gap-2">
                            {contacts.map((c) => (
                                <button
                                    key={c.id}
                                    type="button"
                                    aria-pressed={value.people.includes(`person:${c.id}`)}
                                    className={chip(value.people.includes(`person:${c.id}`))}
                                    onClick={() => toggle('people', `person:${c.id}`)}
                                >
                                    {c.name}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </ResponsiveModal>
    );
}
