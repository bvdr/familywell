import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Link2, Mail, Plus, Trash2, X } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { MemberAvatar } from '@/components/member-avatar';
import { SettingsLeadRow } from '@/components/settings-lead-row';
import { SettingsGroup, SettingsPage } from '@/components/settings-ui';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';

type Member = { id: number; name: string; email: string; role: string };
type Invitation = { id: number; email: string; role: string };
type Props = {
    family: { name: string; owner_id: number };
    members: Member[];
    invitations: Invitation[];
    canManage: boolean;
    /** The family's shareable join link; admins only, never in the demo. */
    link: string | null;
};

// Family::ROLES. Teen = their lists, tasks and calendar only (Family::TEEN_MODULES).
const ROLES = ['member', 'admin', 'teen'] as const;

const iconButton = 'text-muted-foreground hover:text-destructive flex size-10 shrink-0 items-center justify-center rounded-full';

// Members, in the settings look (no own design board, so it borrows Family settings' rows and onboarding's invite
// step): who's in, who's invited, and an inline invite. The family's name/colour/symbol and skin moved to
// Settings > Family > Name & look, so nothing about the family's look lives here any more.
export default function Members({ family, members, invitations, canManage, link }: Props) {
    const t = useT();
    const authId = usePage<{ auth: { user: { id: number } } }>().props.auth.user.id;
    const roleLabel = (role: string) => t(`family.role_${role}`);

    const invite = useForm({ email: '', role: 'member' });
    const sendInvite: FormEventHandler = (e) => {
        e.preventDefault();
        invite.post(route('family.invitations.store'), { preserveScroll: true, onSuccess: () => invite.reset() });
    };

    // Optimistic: a removed member or revoked invite vanishes on tap; cleared when the request settles so the
    // reloaded props are authoritative again (it snaps back on error).
    const gone = useOptimistic<boolean>();
    const settle = (id: string) => ({ preserveScroll: true, preserveState: true, onSuccess: () => gone.clear(id), onError: () => gone.clear(id) });
    const visibleMembers = members.filter((m) => !gone.get(`m${m.id}`, false));
    const visibleInvitations = invitations.filter((i) => !gone.get(`i${i.id}`, false));
    const removeMember = (id: number) => {
        gone.set(`m${id}`, true);
        router.delete(route('family.members.destroy', id), settle(`m${id}`));
    };
    const revokeInvite = (id: number) => {
        gone.set(`i${id}`, true);
        router.delete(route('family.invitations.destroy', id), settle(`i${id}`));
    };

    const [copied, setCopied] = useState(false);
    const copy = async () => {
        if (!link) return;
        await navigator.clipboard?.writeText(link);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('nav.members'), href: '/family/members' }]}>
            <Head title={`${family.name} · ${t('nav.members')}`} />
            <SettingsPage
                back={{ href: '/settings/family', label: family.name }}
                title={t('settings_hub.members')}
                lede={t('settings_family.members_lede')}
            >
                <SettingsGroup label={t('settings_family.members_group')}>
                    {visibleMembers.map((m) => {
                        const isOwner = m.id === family.owner_id;
                        return (
                            <SettingsLeadRow
                                key={m.id}
                                testId={`member-${m.id}`}
                                lead={<MemberAvatar userId={m.id} name={m.name} className="size-11 text-base" />}
                                title={
                                    <>
                                        {m.name}
                                        {m.id === authId && <span className="text-muted-foreground"> · {t('family.you_suffix')}</span>}
                                    </>
                                }
                                // truncate: a long email must ellipsize, not widen the row past a phone screen.
                                sub={
                                    <span className="block truncate">
                                        {isOwner ? t('settings_family.members_role_owner') : roleLabel(m.role)} · {m.email}
                                    </span>
                                }
                                right={
                                    // The owner anchors the family and can't be removed (FamilyMemberController::destroy).
                                    canManage &&
                                    !isOwner && (
                                        <button
                                            type="button"
                                            className={iconButton}
                                            aria-label={t('family.remove_member', { name: m.name })}
                                            onClick={() => removeMember(m.id)}
                                        >
                                            <Trash2 className="size-[18px]" />
                                        </button>
                                    )
                                }
                            />
                        );
                    })}
                </SettingsGroup>

                {canManage && visibleInvitations.length > 0 && (
                    <SettingsGroup label={t('family.pending_invitations')}>
                        {visibleInvitations.map((i) => (
                            <SettingsLeadRow
                                key={i.id}
                                testId={`invitation-${i.id}`}
                                lead={
                                    <span className="bg-surface text-muted-foreground flex size-11 shrink-0 items-center justify-center rounded-full">
                                        <Mail className="size-5" />
                                    </span>
                                }
                                title={i.email}
                                sub={t('family.invited_as', { role: roleLabel(i.role) })}
                                right={
                                    <button
                                        type="button"
                                        className={iconButton}
                                        aria-label={t('family.revoke_invite', { email: i.email })}
                                        onClick={() => revokeInvite(i.id)}
                                    >
                                        <X className="size-[18px]" />
                                    </button>
                                }
                            />
                        ))}
                    </SettingsGroup>
                )}

                {canManage && (
                    <section className="flex flex-col gap-2.5">
                        <h2 className="text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                            {t('settings_family.members_invite_group')}
                        </h2>
                        {/* Onboarding's invite step: email box + role chip + a square add button, the join link under it. */}
                        <form onSubmit={sendInvite} className="flex gap-2">
                            <div className="relative min-w-0 flex-1">
                                <Mail className="text-muted-foreground pointer-events-none absolute top-1/2 left-4 size-[18px] -translate-y-1/2" />
                                <Input
                                    type="email"
                                    inputMode="email"
                                    autoComplete="off"
                                    value={invite.data.email}
                                    onChange={(e) => invite.setData('email', e.target.value)}
                                    placeholder={t('onboarding.invite_placeholder')}
                                    aria-label={t('family.email')}
                                    // 16px+ text: iOS zooms into smaller inputs on focus.
                                    className="bg-surface-card h-[52px] rounded-[14px] pr-[92px] pl-11 text-base"
                                />
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        aria-label={t('family.role')}
                                        className="bg-surface absolute top-1/2 right-2 inline-flex -translate-y-1/2 items-center gap-1 rounded-full py-[5px] pr-2 pl-2.5 text-xs font-semibold"
                                    >
                                        {roleLabel(invite.data.role)}
                                        <ChevronDown className="text-muted-foreground size-[13px]" />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuRadioGroup value={invite.data.role} onValueChange={(r) => invite.setData('role', r)}>
                                            {ROLES.map((r) => (
                                                <DropdownMenuRadioItem key={r} value={r}>
                                                    {roleLabel(r)}
                                                </DropdownMenuRadioItem>
                                            ))}
                                        </DropdownMenuRadioGroup>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                            <Button
                                type="submit"
                                disabled={invite.processing}
                                aria-label={t('family.send_invite')}
                                className="size-[52px] shrink-0 rounded-[14px] p-0 [&_svg]:size-[22px]"
                            >
                                <Plus />
                            </Button>
                        </form>
                        <InputError message={invite.errors.email ?? invite.errors.role} />

                        {link && (
                            <div className="bg-surface-card flex items-center gap-3 rounded-[22px] border px-4 py-3">
                                <span className="bg-surface text-primary flex size-11 shrink-0 items-center justify-center rounded-xl">
                                    <Link2 className="size-5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-primary text-[15px] font-semibold">{t('onboarding.invite_link')}</p>
                                    <p className="text-muted-foreground truncate font-mono text-xs">{link.replace(/^https?:\/\//, '')}</p>
                                </div>
                                <Button type="button" size="sm" onClick={copy} className="shrink-0 rounded-full px-3.5">
                                    {copied ? <Check /> : t('onboarding.copy')}
                                </Button>
                            </div>
                        )}
                    </section>
                )}
            </SettingsPage>
        </AppLayout>
    );
}
