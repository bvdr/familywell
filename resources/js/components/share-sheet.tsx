import { MemberAvatar } from '@/components/member-avatar';
import { Segmented } from '@/components/segmented';
import { DropdownMenu, DropdownMenuContent, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Sheet, SheetClose, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useT } from '@/lib/i18n';
import { save } from '@/lib/save';
import { router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Copy, Link2, Lock, UserPlus, Users, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export type ShareMember = { id: number; name: string; role: 'owner' | 'editor' | 'viewer' | null; isOwner: boolean };
export type ShareState = {
    type: string;
    id: number;
    visibility: 'family' | 'invited' | 'private';
    canManage: boolean;
    members: ShareMember[];
    link: { id: number; token: string; url: string } | null;
};

const VISIBILITY = [
    { key: 'family', icon: Users, labelKey: 'sharing.vis_family', descKey: 'sharing.vis_family_desc' },
    { key: 'invited', icon: UserPlus, labelKey: 'sharing.vis_invited', descKey: 'sharing.vis_invited_desc' },
    { key: 'private', icon: Lock, labelKey: 'sharing.vis_private', descKey: 'sharing.vis_private_desc' },
] as const;

type Role = 'editor' | 'viewer' | 'none';

export function ShareSheet({
    open,
    onOpenChange,
    share,
    eventName,
    withLink = true,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    share: ShareState;
    eventName: string;
    // false hides the read-only invite link, for entities that go public through the guest link instead.
    withLink?: boolean;
}) {
    const [copied, setCopied] = useState(false);
    const t = useT();
    const authId = usePage<{ auth: { user: { id: number } } }>().props.auth.user.id;
    // Mirror the server prop locally so toggles react instantly. The effect re-syncs whenever the
    // server confirms (props change) or, if the request fails, reverts to the unchanged prop.
    const [state, setState] = useState(share);
    useEffect(() => setState(share), [share]);

    const base = `/shares/${share.type}/${share.id}`;
    const opts = { preserveScroll: true, preserveState: true } as const;

    // On failure the server props don't change, so revert the optimistic state to the last-confirmed prop.
    const revert = () => setState(share);
    const setVisibility = (visibility: ShareState['visibility']) => {
        setState((s) => ({ ...s, visibility })); // optimistic
        void save('patch', `${base}/visibility`, { visibility }).then((r) => r !== 'ok' && revert());
    };
    const setRole = (userId: number, role: ShareMember['role']) => {
        setState((s) => ({ ...s, members: s.members.map((m) => (m.id === userId ? { ...m, role } : m)) })); // optimistic
        void save('put', `${base}/members/${userId}`, { role }).then((r) => r !== 'ok' && revert());
    };
    // Revoke a person's access (deletes their grant). In "invited"/"private" mode they lose access;
    // in "family" mode everyone can add regardless, so it only bites once you narrow visibility.
    const removeMember = (userId: number) => {
        setState((s) => ({ ...s, members: s.members.map((m) => (m.id === userId ? { ...m, role: null } : m)) })); // optimistic
        void save('delete', `${base}/members/${userId}`).then((r) => r !== 'ok' && revert());
    };
    const createLink = () => router.post(`${base}/link`, {}, opts);
    const copy = () => {
        if (!state.link) return;
        navigator.clipboard.writeText(state.link.url).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1600);
        });
    };

    const roleLabel = (r: Role) => (r === 'editor' ? t('sharing.can_edit') : r === 'viewer' ? t('sharing.can_view') : t('sharing.no_access'));
    // Removing a person only sticks once visibility is narrowed; in "family" mode everyone can add regardless.
    const roles: Role[] = state.visibility === 'family' ? ['editor', 'viewer'] : ['editor', 'viewer', 'none'];
    const current = VISIBILITY.find((v) => v.key === state.visibility) ?? VISIBILITY[0];

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            {/* [&>button:last-child]:hidden drops SheetContent's built-in tiny X; the header has the round one. */}
            <SheetContent
                side="bottom"
                className="max-h-[88vh] overflow-y-auto rounded-t-3xl px-4 pb-8 sm:mx-auto sm:max-w-md [&>button:last-child]:hidden"
            >
                <SheetHeader className="flex-row items-center justify-between gap-3 space-y-0 px-0 text-left">
                    <SheetTitle className="font-heading text-primary min-w-0 text-2xl leading-tight break-words">
                        {t('sharing.title_named', { name: eventName })}
                    </SheetTitle>
                    <SheetClose
                        aria-label={t('sharing.close')}
                        className="bg-surface text-primary hover:bg-accent flex size-10 shrink-0 items-center justify-center rounded-full transition-colors"
                    >
                        <X className="size-5" />
                    </SheetClose>
                </SheetHeader>

                {/* Who can access */}
                <div className="mt-3 flex flex-col gap-2">
                    <p className="text-primary text-sm font-semibold">{t('sharing.access_title')}</p>
                    <Segmented<ShareState['visibility']>
                        ariaLabel={t('sharing.access_title')}
                        disabled={!state.canManage}
                        options={VISIBILITY.map((v) => ({ value: v.key, label: t(v.labelKey), icon: v.icon }))}
                        value={state.visibility}
                        onChange={(v) => v !== state.visibility && setVisibility(v)}
                    />
                    <p className="text-muted-foreground text-sm">{t(current.descKey)}</p>
                </div>

                {/* People */}
                <ul className="bg-surface-card mt-4 divide-y rounded-2xl border">
                    {state.members.map((m) => {
                        // Private = owner only, so everyone else is dimmed and locked out.
                        const excluded = state.visibility === 'private' && !m.isOwner;
                        const role: Role = m.role === 'editor' || m.role === 'viewer' ? m.role : 'none';
                        return (
                            <li key={m.id} className={`flex items-center gap-3 px-4 py-3 transition-opacity ${excluded ? 'opacity-40' : ''}`}>
                                <MemberAvatar userId={m.id} name={m.name} className="size-9 text-sm" />
                                <span className="text-primary min-w-0 flex-1 truncate font-medium">
                                    {m.name}
                                    {m.id === authId && ` ${t('sharing.you')}`}
                                </span>
                                {m.isOwner ? (
                                    <span className="text-muted-foreground shrink-0 text-sm">{t('sharing.owner')}</span>
                                ) : excluded || !state.canManage ? (
                                    <span className="text-muted-foreground shrink-0 text-sm">
                                        {excluded ? t('sharing.no_access') : roleLabel(role)}
                                    </span>
                                ) : (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger
                                            aria-label={`${m.name}: ${roleLabel(role)}`}
                                            className="text-primary hover:bg-accent -mr-2 inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-sm font-medium transition-colors"
                                        >
                                            {roleLabel(role)}
                                            <ChevronDown className="size-4 opacity-70" />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuRadioGroup
                                                value={role}
                                                // Re-picking the current role is a no-op (a native select never fired for it).
                                                onValueChange={(v) => {
                                                    if (v === role) return;
                                                    if (v === 'none') removeMember(m.id);
                                                    else setRole(m.id, v as 'editor' | 'viewer');
                                                }}
                                            >
                                                {roles.map((r) => (
                                                    <DropdownMenuRadioItem key={r} value={r}>
                                                        {roleLabel(r)}
                                                    </DropdownMenuRadioItem>
                                                ))}
                                            </DropdownMenuRadioGroup>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </li>
                        );
                    })}
                </ul>

                {/* View-only link */}
                {withLink && (
                    <div className="bg-surface mt-4 flex flex-col gap-3 rounded-2xl p-4">
                        <div className="flex items-center gap-3">
                            <Link2 className="text-primary size-5 shrink-0" />
                            <div className="min-w-0">
                                <p className="text-primary font-semibold">{t('sharing.link_title')}</p>
                                <p className="text-muted-foreground text-sm">{t('sharing.link_view_desc')}</p>
                            </div>
                        </div>
                        {state.link ? (
                            <div className="flex items-center gap-2">
                                <span className="bg-background text-primary min-w-0 flex-1 truncate rounded-xl border px-3 py-2.5 font-mono text-sm">
                                    {state.link.url.replace(/^https?:\/\//, '')}
                                </span>
                                <button
                                    type="button"
                                    onClick={copy}
                                    className="bg-accent-strong text-accent-strong-foreground inline-flex shrink-0 items-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-semibold"
                                >
                                    {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                                    {copied ? t('common.copied') : t('common.copy')}
                                </button>
                            </div>
                        ) : state.canManage ? (
                            <button
                                type="button"
                                onClick={createLink}
                                className="bg-background hover:border-accent text-primary inline-flex items-center justify-center gap-2 rounded-xl border p-3 text-sm font-medium transition-colors"
                            >
                                <Link2 className="size-4" /> {t('sharing.create_link')}
                            </button>
                        ) : (
                            <p className="text-muted-foreground text-sm">{t('sharing.no_link')}</p>
                        )}
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}
