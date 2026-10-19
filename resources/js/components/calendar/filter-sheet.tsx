import { Contact, UserRound } from 'lucide-react';

import { Picker } from '@/components/picker';
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
                {/* People: one searchable dropdown for family + contacts (a family with 40 contacts filled the sheet with chips). */}
                <div className="flex flex-col gap-2">
                    <span className="text-muted-foreground text-sm">{t('calendar.people')}</span>
                    <Picker
                        multi
                        ariaLabel={t('calendar.people')}
                        placeholder={t('calendar.everyone')}
                        selected={value.people}
                        onChange={(people) => onChange({ ...value, people })}
                        options={[
                            ...members.map((m) => ({ value: `user:${m.id}`, label: m.name, hint: t('calendar.members'), icon: UserRound })),
                            ...contacts.map((c) => ({ value: `person:${c.id}`, label: c.name, hint: t('calendar.contacts'), icon: Contact })),
                        ]}
                    />
                </div>
            </div>
        </ResponsiveModal>
    );
}
