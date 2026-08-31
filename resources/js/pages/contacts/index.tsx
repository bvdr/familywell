import { Head, router } from '@inertiajs/react';
import { Link as LinkIcon, Mail, MapPin, Phone, Plus, Trash2, User } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useT } from '@/lib/i18n';
import { Slot } from '@/lib/modules';
import type { BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';

// A contact method (phone/email/…). `id` is present for existing rows loaded from the server.
type ContactMethod = { id?: number; type: string; label: string | null; value: string };

// The `person` primitive as the contacts module serializes it. Extension fields (birthdate, age,
// nextBirthday, daysUntilBirthday) are added by the Birthdays module via the person.serialize hook —
// optional here because they only exist when that module is enabled.
type Person = {
    id: number;
    name: string;
    contactMethods: ContactMethod[];
    birthdate?: string | null;
    age?: number | null;
    nextBirthday?: string | null;
    daysUntilBirthday?: number | null;
};

const METHOD_ICON: Record<string, typeof Phone> = { phone: Phone, email: Mail, address: MapPin, url: LinkIcon };

export default function ContactsIndex({ people, contactTypes }: { people: Person[]; contactTypes: string[] }) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.contacts'), href: '/m/contacts' }];
    const [newName, setNewName] = useState('');
    const [editing, setEditing] = useState<Person | null>(null);

    const addPerson = () => {
        if (!newName.trim()) return;
        router.post(route('contacts.people.store'), { name: newName.trim() }, { preserveScroll: true, onSuccess: () => setNewName('') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('nav.contacts')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <h1 className="font-heading text-primary text-2xl">{t('nav.contacts')}</h1>

                {/* Quick add: name only. Contact methods + birthday are added by tapping the contact. */}
                <div className="flex items-center gap-2">
                    <Input
                        value={newName}
                        onChange={(e) => setNewName(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && addPerson()}
                        placeholder={t('contacts.add_placeholder')}
                        className="text-base md:text-sm"
                    />
                    <Button size="icon" className="shrink-0 rounded-lg" onClick={addPerson} aria-label={t('contacts.add')}>
                        <Plus className="size-5" />
                    </Button>
                </div>

                {people.length === 0 && <p className="text-muted-foreground py-6 text-center text-sm">{t('contacts.empty')}</p>}

                <div className="flex flex-col gap-2">
                    {people.map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            onClick={() => setEditing(p)}
                            className="bg-surface-card flex items-center gap-3 rounded-xl border px-3 py-2.5 text-left"
                        >
                            <span className="bg-accent-strong text-accent-strong-foreground flex size-9 shrink-0 items-center justify-center rounded-full">
                                <User className="size-4.5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="text-primary block truncate text-[15px]">{p.name}</span>
                                {p.contactMethods.length > 0 && (
                                    <span className="text-muted-foreground block truncate text-xs">
                                        {p.contactMethods.map((m) => m.value).join(' · ')}
                                    </span>
                                )}
                            </span>
                            {/* Extension point: modules render read-only badges for this person (Birthdays → 🎂). */}
                            <Slot name="person.row.badges" ctx={{ person: p }} />
                        </button>
                    ))}
                </div>
            </div>

            {editing && <PersonModal person={editing} contactTypes={contactTypes} onClose={() => setEditing(null)} />}
        </AppLayout>
    );
}

function PersonModal({ person, contactTypes, onClose }: { person: Person; contactTypes: string[]; onClose: () => void }) {
    const t = useT();
    const [name, setName] = useState(person.name);
    const [methods, setMethods] = useState<ContactMethod[]>(person.contactMethods.map((m) => ({ ...m })));

    const save = () => {
        router.patch(
            route('contacts.people.update', person.id),
            { name: name.trim() || person.name, contact_methods: methods.filter((m) => m.value.trim()) },
            { preserveScroll: true, preserveState: true, onSuccess: onClose },
        );
    };

    const remove = () => {
        router.delete(route('contacts.people.destroy', person.id), { preserveScroll: true, onSuccess: onClose });
    };

    const setMethod = (i: number, patch: Partial<ContactMethod>) =>
        setMethods((ms) => ms.map((m, idx) => (idx === i ? { ...m, ...patch } : m)));

    return (
        <ResponsiveModal open onOpenChange={(v) => !v && onClose()} title={t('contacts.edit')}>
            <div className="flex flex-col gap-4">
                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('contacts.name')}</Label>
                    <Input autoFocus value={name} onChange={(e) => setName(e.target.value)} className="text-base md:text-sm" />
                </div>

                <div className="grid gap-2">
                    <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('contacts.methods')}</Label>
                    <div className="flex flex-col gap-2">
                        {methods.map((m, i) => {
                            const Icon = METHOD_ICON[m.type] ?? Phone;
                            return (
                                <div key={i} className="flex items-center gap-2">
                                    <Icon className="text-muted-foreground size-4 shrink-0" />
                                    <select
                                        value={m.type}
                                        onChange={(e) => setMethod(i, { type: e.target.value })}
                                        className="border-border text-primary h-9 shrink-0 rounded-lg border bg-transparent px-2 text-sm"
                                    >
                                        {contactTypes.map((ty) => (
                                            <option key={ty} value={ty}>
                                                {t('contacts.type_' + ty)}
                                            </option>
                                        ))}
                                    </select>
                                    <Input
                                        value={m.value}
                                        onChange={(e) => setMethod(i, { value: e.target.value })}
                                        placeholder={t('contacts.value_placeholder')}
                                        className="text-base md:text-sm"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setMethods((ms) => ms.filter((_, idx) => idx !== i))}
                                        className="text-muted-foreground hover:text-destructive shrink-0"
                                        aria-label={t('contacts.remove_method')}
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            );
                        })}
                        <button
                            type="button"
                            onClick={() => setMethods((ms) => [...ms, { type: contactTypes[0] ?? 'phone', label: null, value: '' }])}
                            className="text-primary flex items-center gap-1.5 self-start text-sm"
                        >
                            <Plus className="size-4" /> {t('contacts.add_method')}
                        </button>
                    </div>
                </div>

                {/* Extension point: modules inject extra editor fields for this person (Birthdays → a
                    birthdate picker). onPatch persists via the same contacts endpoint; the person.saved
                    hook hands the field to the owning module. Renders nothing with no module. */}
                <Slot
                    name="person.editor.fields"
                    ctx={{
                        person,
                        onPatch: (data: Record<string, string | number | boolean | null>) =>
                            router.patch(route('contacts.people.update', person.id), data, { preserveScroll: true, preserveState: true }),
                    }}
                />

                <div className="flex items-center justify-between pt-1">
                    <button type="button" onClick={remove} className="text-destructive flex items-center gap-2 text-sm font-medium">
                        <Trash2 className="size-4" /> {t('contacts.delete')}
                    </button>
                    <Button onClick={save}>{t('contacts.save')}</Button>
                </div>
            </div>
        </ResponsiveModal>
    );
}
