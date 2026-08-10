import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Mail, Plus, Trash2, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { MemberAvatar } from '@/components/member-avatar';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';

type Member = { id: number; name: string; email: string; role: string };
type Invitation = { id: number; email: string; role: string };
type Family = { id: number; name: string; description: string | null; color: string; emoji: string; owner_id: number };
type Props = {
    family: Family;
    members: Member[];
    invitations: Invitation[];
    canManage: boolean;
    palette: { colors: string[]; emojis: string[] };
};

// Keep only the last emoji the user typed (one grapheme, so ZWJ sequences like 👨‍👩‍👧‍👦 stay intact).
const lastEmoji = (v: string): string => {
    if (!v) return '';
    try {
        const parts = [...new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(v)];
        return parts.length ? parts[parts.length - 1].segment : '';
    } catch {
        return [...v].pop() ?? '';
    }
};

function RoleBadge({ role }: { role: string }) {
    const admin = role.toLowerCase() === 'admin' || role.toLowerCase() === 'owner';
    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${
                admin ? 'bg-accent-subtle text-primary' : 'bg-surface text-text-secondary'
            }`}
        >
            {role}
        </span>
    );
}

export default function Members({ family, members, invitations, canManage, palette }: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.members'), href: '/family/members' }];
    const authId = (usePage().props as { auth: { user: { id: number } } }).auth.user.id;
    const [inviteOpen, setInviteOpen] = useState(false);

    const settings = useForm({ name: family.name, description: family.description ?? '', color: family.color, emoji: family.emoji });
    const customColor = !palette.colors.includes(settings.data.color);
    const customEmoji = !palette.emojis.includes(settings.data.emoji);
    const invite = useForm({ email: '', role: 'member' });

    const saveSettings: FormEventHandler = (e) => {
        e.preventDefault();
        settings.patch(route('family.update'), { preserveScroll: true });
    };
    const sendInvite: FormEventHandler = (e) => {
        e.preventDefault();
        invite.post(route('family.invitations.store'), {
            preserveScroll: true,
            onSuccess: () => {
                invite.reset();
                setInviteOpen(false);
            },
        });
    };
    const removeMember = (id: number) => router.delete(route('family.members.destroy', id), { preserveScroll: true });
    const revokeInvite = (id: number) => router.delete(route('family.invitations.destroy', id), { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${family.name} · ${t('nav.members')}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 md:p-6">
                {/* Family identity + settings */}
                {canManage ? (
                    <form onSubmit={saveSettings} className="bg-surface-card flex flex-col gap-5 rounded-2xl border p-6">
                        <div className="flex items-center gap-4">
                            <span
                                className="flex size-14 shrink-0 items-center justify-center rounded-2xl text-3xl"
                                style={{ backgroundColor: settings.data.color }}
                            >
                                {settings.data.emoji}
                            </span>
                            <div className="flex-1">
                                <h1 className="font-heading text-primary text-2xl leading-tight">{t('family.settings_title')}</h1>
                                <p className="text-muted-foreground text-sm">{t('family.settings_desc')}</p>
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="name">{t('family.name_label')}</Label>
                                <Input id="name" value={settings.data.name} onChange={(e) => settings.setData('name', e.target.value)} />
                                <InputError message={settings.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">{t('family.description_label')}</Label>
                                <Input
                                    id="description"
                                    value={settings.data.description}
                                    onChange={(e) => settings.setData('description', e.target.value)}
                                    placeholder={t('family.description_placeholder')}
                                />
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-6">
                            <div className="flex flex-col gap-2">
                                <span className="text-xs font-medium">{t('family.color')}</span>
                                <div className="flex flex-wrap items-center gap-2">
                                    {palette.colors.map((c) => (
                                        <button
                                            key={c}
                                            type="button"
                                            aria-label={t('family.color_aria', { color: c })}
                                            onClick={() => settings.setData('color', c)}
                                            className="flex size-7 items-center justify-center rounded-md transition-transform hover:scale-110"
                                            style={{ backgroundColor: c }}
                                        >
                                            {settings.data.color === c && <Check className="text-text-inverse size-4" />}
                                        </button>
                                    ))}
                                    {/* Any color: the native OS color picker, styled as a swatch. */}
                                    <label
                                        title={t('family.pick_any_color')}
                                        className="relative flex size-7 cursor-pointer items-center justify-center overflow-hidden rounded-md"
                                        style={
                                            customColor
                                                ? { backgroundColor: settings.data.color }
                                                : { background: 'conic-gradient(from 0deg, #c2453a, #d4943a, #7a9a80, #2d5e3a, #4a6b52, #c2453a)' }
                                        }
                                    >
                                        <input
                                            type="color"
                                            value={settings.data.color}
                                            onChange={(e) => settings.setData('color', e.target.value)}
                                            aria-label={t('family.custom_color')}
                                            className="absolute inset-0 size-full cursor-pointer opacity-0"
                                        />
                                        {customColor ? <Check className="text-text-inverse size-4" /> : <Plus className="size-3.5 text-white" />}
                                    </label>
                                </div>
                                <InputError message={settings.errors.color} />
                            </div>
                            <div className="flex flex-col gap-2">
                                <span className="text-xs font-medium">{t('family.emoji')}</span>
                                <div className="flex flex-wrap items-center gap-2">
                                    {palette.emojis.map((em) => (
                                        <button
                                            key={em}
                                            type="button"
                                            aria-label={t('family.emoji_aria', { emoji: em })}
                                            onClick={() => settings.setData('emoji', em)}
                                            className={`flex size-7 items-center justify-center rounded-md border text-base transition-transform hover:scale-110 ${
                                                settings.data.emoji === em ? 'border-accent bg-accent-subtle' : 'border-border bg-surface-card'
                                            }`}
                                        >
                                            {em}
                                        </button>
                                    ))}
                                    {/* Any emoji: tap the box and use the OS emoji keyboard. */}
                                    <input
                                        type="text"
                                        value={settings.data.emoji}
                                        onChange={(e) => settings.setData('emoji', lastEmoji(e.target.value))}
                                        aria-label={t('family.type_any_emoji')}
                                        title={t('family.type_any_emoji_title')}
                                        className={`flex size-7 items-center justify-center rounded-md border border-dashed text-center text-base ${
                                            customEmoji ? 'border-accent bg-accent-subtle' : 'border-border bg-surface-card'
                                        }`}
                                    />
                                </div>
                                <p className="text-muted-foreground text-xs">{t('family.emoji_hint')}</p>
                                <InputError message={settings.errors.emoji} />
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <Button type="submit" disabled={settings.processing}>
                                {t('family.save_changes')}
                            </Button>
                            {settings.recentlySuccessful && <span className="text-accent text-sm">{t('family.saved')}</span>}
                        </div>
                    </form>
                ) : (
                    <div className="flex items-center gap-4">
                        <span className="flex size-14 items-center justify-center rounded-2xl text-3xl" style={{ backgroundColor: family.color }}>
                            {family.emoji}
                        </span>
                        <div>
                            <h1 className="font-heading text-primary text-2xl">{family.name}</h1>
                            {family.description && <p className="text-muted-foreground text-sm">{family.description}</p>}
                        </div>
                    </div>
                )}

                {/* Members */}
                <section className="flex flex-col gap-4">
                    <div className="flex items-end justify-between">
                        <div>
                            <h2 className="font-heading text-primary text-xl">{t('nav.members')}</h2>
                            <p className="text-muted-foreground text-sm">{t('family.members_desc')}</p>
                        </div>
                        {canManage && (
                            <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
                                <DialogTrigger asChild>
                                    <Button>
                                        <Plus className="size-4" /> {t('family.invite_member')}
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle className="font-heading text-xl">{t('family.invite_to', { name: family.name })}</DialogTitle>
                                        <DialogDescription>{t('family.invite_desc')}</DialogDescription>
                                    </DialogHeader>
                                    <form onSubmit={sendInvite} className="flex flex-col gap-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="email">{t('family.email')}</Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                autoFocus
                                                value={invite.data.email}
                                                onChange={(e) => invite.setData('email', e.target.value)}
                                                placeholder={t('family.email_placeholder')}
                                            />
                                            <InputError message={invite.errors.email} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label>{t('family.role')}</Label>
                                            <Select value={invite.data.role} onValueChange={(v) => invite.setData('role', v)}>
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="member">{t('family.role_member')}</SelectItem>
                                                    <SelectItem value="admin">{t('family.role_admin')}</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <Button type="submit" disabled={invite.processing} className="w-full">
                                            <Mail className="size-4" /> {t('family.send_invite')}
                                        </Button>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>

                    <div className="bg-surface-card overflow-hidden rounded-2xl border">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-surface hover:bg-surface">
                                    <TableHead className="text-text-muted text-xs tracking-wider uppercase">{t('family.name_header')}</TableHead>
                                    <TableHead className="text-text-muted hidden text-xs tracking-wider uppercase sm:table-cell">
                                        {t('family.email')}
                                    </TableHead>
                                    <TableHead className="text-text-muted text-xs tracking-wider uppercase">{t('family.role')}</TableHead>
                                    {canManage && <TableHead className="w-10"></TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {members.map((m) => {
                                    const isOwner = m.id === family.owner_id;
                                    return (
                                        <TableRow key={m.id}>
                                            <TableCell>
                                                <div className="flex items-center gap-3">
                                                    <MemberAvatar userId={m.id} name={m.name} className="size-9 text-sm" />
                                                    <div>
                                                        <div className="text-primary font-medium">
                                                            {m.name}
                                                            {m.id === authId && (
                                                                <span className="text-muted-foreground font-normal"> · {t('family.you_suffix')}</span>
                                                            )}
                                                        </div>
                                                        <div className="text-muted-foreground text-xs sm:hidden">{m.email}</div>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-text-secondary hidden sm:table-cell">{m.email}</TableCell>
                                            <TableCell>
                                                <RoleBadge role={isOwner ? 'owner' : m.role} />
                                            </TableCell>
                                            {canManage && (
                                                <TableCell>
                                                    {!isOwner && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="text-muted-foreground hover:text-destructive size-8"
                                                            aria-label={t('family.remove_member', { name: m.name })}
                                                            onClick={() => removeMember(m.id)}
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Pending invitations */}
                    {canManage && invitations.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <h3 className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                                {t('family.pending_invitations')}
                            </h3>
                            {invitations.map((i) => (
                                <div key={i.id} className="flex items-center justify-between rounded-xl border border-dashed p-3">
                                    <div className="flex items-center gap-3">
                                        <span className="bg-surface text-text-muted flex size-9 items-center justify-center rounded-full">
                                            <Mail className="size-4" />
                                        </span>
                                        <div className="text-sm">
                                            <div className="text-primary">{i.email}</div>
                                            <div className="text-muted-foreground text-xs">{t('family.invited_as', { role: i.role })}</div>
                                        </div>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="text-muted-foreground hover:text-destructive size-8"
                                        aria-label={t('family.revoke_invite', { email: i.email })}
                                        onClick={() => revokeInvite(i.id)}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
