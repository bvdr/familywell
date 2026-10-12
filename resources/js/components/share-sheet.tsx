import { MemberAvatar } from '@/components/member-avatar';
import { Segmented } from '@/components/segmented';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { Check, Copy, Link2, Lock, UserCheck, Users } from 'lucide-react';
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
    { key: 'family', icon: Users, titleKey: 'sharing.vis_family_title', subKey: 'sharing.vis_family_sub' },
    { key: 'invited', icon: UserCheck, titleKey: 'sharing.vis_invited_title', subKey: 'sharing.vis_invited_sub' },
    { key: 'private', icon: Lock, titleKey: 'sharing.vis_private_title', subKey: 'sharing.vis_private_sub' },
] as const;

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
        router.patch(`${base}/visibility`, { visibility }, { ...opts, onError: revert });
    };
    const setRole = (userId: number, role: ShareMember['role']) => {
        setState((s) => ({ ...s, members: s.members.map((m) => (m.id === userId ? { ...m, role } : m)) })); // optimistic
        router.put(`${base}/members/${userId}`, { role }, { ...opts, onError: revert });
    };
    // Revoke a person's access (deletes their grant). In "invited"/"private" mode they lose access;
    // in "family" mode everyone can add regardless, so it only bites once you narrow visibility.
    const removeMember = (userId: number) => {
        setState((s) => ({ ...s, members: s.members.map((m) => (m.id === userId ? { ...m, role: null } : m)) })); // optimistic
        router.delete(`${base}/members/${userId}`, { ...opts, onError: revert });
    };
    const createLink = () => router.post(`${base}/link`, {}, opts);
    const copy = () => {
        if (!state.link) return;
        navigator.clipboard.writeText(state.link.url).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1600);
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="bottom" className="max-h-[88vh] overflow-y-auto rounded-t-2xl px-4 pb-8 sm:mx-auto sm:max-w-md">
                <SheetHeader className="px-0 text-left">
                    <SheetTitle className="font-heading text-primary text-2xl">{t('sharing.title')}</SheetTitle>
                    <p className="text-muted-foreground -mt-1 text-sm">{eventName}</p>
                </SheetHeader>

                {/* Visibility */}
                <div className="mt-2 flex flex-col gap-2">
                    <p className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('sharing.access_title')}</p>
                    {VISIBILITY.map((v) => {
                        const active = state.visibility === v.key;
                        return (
                            <button
                                key={v.key}
                                type="button"
                                disabled={!state.canManage}
                                onClick={() => setVisibility(v.key)}
                                className={`flex items-center gap-3 rounded-xl border p-3 text-left transition-colors ${
                                    active ? 'border-primary bg-accent-subtle' : 'bg-surface-card hover:border-accent'
                                } ${state.canManage ? '' : 'opacity-70'}`}
                            >
                                <span
                                    className={`flex size-9 items-center justify-center rounded-lg ${active ? 'bg-tile text-tile-foreground' : 'bg-accent-subtle text-primary'}`}
                                >
                                    <v.icon className="size-4.5" />
                                </span>
                                <span className="flex-1">
                                    <span className="text-primary block font-medium">{t(v.titleKey)}</span>
                                    <span className="text-muted-foreground block text-xs">{t(v.subKey)}</span>
                                </span>
                                <span
                                    className={`flex size-5 items-center justify-center rounded-full border ${active ? 'border-primary bg-accent-strong text-accent-strong-foreground' : 'border-border'}`}
                                >
                                    {active && <Check className="size-3.5" />}
                                </span>
                            </button>
                        );
                    })}
                </div>

                {/* People */}
                <div className="mt-5 flex flex-col gap-1">
                    <p className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('sharing.people_title')}</p>
                    {state.members.map((m) => {
                        // Private = owner only, so everyone else is dimmed and locked out.
                        const excluded = state.visibility === 'private' && !m.isOwner;
                        return (
                            <div key={m.id} className={`flex items-center gap-3 py-2 transition-opacity ${excluded ? 'opacity-40' : ''}`}>
                                <MemberAvatar userId={m.id} name={m.name} className="size-9 text-sm" />
                                <div className="flex-1">
                                    <div className="text-primary text-sm font-medium">{m.name}</div>
                                    {m.isOwner && <div className="text-muted-foreground text-xs">{t('sharing.owner')}</div>}
                                </div>
                                {m.isOwner ? (
                                    <span className="text-muted-foreground text-sm">{t('sharing.owner')}</span>
                                ) : excluded ? (
                                    <span className="text-muted-foreground text-sm">{t('sharing.no_access')}</span>
                                ) : state.canManage ? (
                                    <Segmented<'editor' | 'viewer' | 'none'>
                                        ariaLabel={m.name}
                                        options={[
                                            { value: 'editor', label: t('sharing.can_edit') },
                                            { value: 'viewer', label: t('sharing.can_view') },
                                            // Removing a person only sticks once visibility is narrowed; in
                                            // "family" mode everyone can add regardless.
                                            ...(state.visibility !== 'family' ? [{ value: 'none' as const, label: t('sharing.no_access') }] : []),
                                        ]}
                                        value={m.role === 'editor' || m.role === 'viewer' ? m.role : 'none'}
                                        // Re-clicking the checked option is a no-op (a native select never fired for it).
                                        onChange={(v) => {
                                            if (v === (m.role ?? 'none')) return;
                                            if (v === 'none') removeMember(m.id);
                                            else setRole(m.id, v);
                                        }}
                                    />
                                ) : (
                                    <span className="text-muted-foreground text-sm">
                                        {m.role === 'editor'
                                            ? t('sharing.can_edit')
                                            : m.role === 'viewer'
                                              ? t('sharing.can_view')
                                              : t('sharing.no_access')}
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* Invite link */}
                {withLink && (
                    <div className="mt-5 flex flex-col gap-2">
                        <p className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('sharing.invite_link')}</p>
                        {state.link ? (
                            <>
                                <div className="bg-surface flex items-center gap-2 rounded-xl border p-2 pl-3">
                                    <Link2 className="text-muted-foreground size-4 shrink-0" />
                                    <span className="text-primary flex-1 truncate text-sm">{state.link.url}</span>
                                    <button
                                        onClick={copy}
                                        className="bg-accent-strong text-accent-strong-foreground inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium"
                                    >
                                        {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                                        {copied ? t('common.copied') : t('common.copy')}
                                    </button>
                                </div>
                                <p className="text-muted-foreground text-xs">{t('sharing.link_view_desc')}</p>
                            </>
                        ) : state.canManage ? (
                            <button
                                onClick={createLink}
                                className="bg-surface-card hover:border-accent text-primary inline-flex items-center justify-center gap-2 rounded-xl border p-3 text-sm font-medium transition-colors"
                            >
                                <Link2 className="size-4" /> {t('sharing.create_link')}
                            </button>
                        ) : (
                            <p className="text-muted-foreground text-sm">{t('sharing.no_link')}</p>
                        )}
                    </div>
                )}

                <button
                    onClick={() => onOpenChange(false)}
                    className="bg-accent-strong text-accent-strong-foreground mt-6 w-full rounded-xl py-3 text-sm font-semibold"
                >
                    Done
                </button>
            </SheetContent>
        </Sheet>
    );
}
