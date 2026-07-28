import { MemberAvatar } from '@/components/member-avatar';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
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
    { key: 'family', icon: Users, title: 'Anyone in the family', sub: 'All family members can add spends' },
    { key: 'invited', icon: UserCheck, title: 'Only invited people', sub: 'Just the people you add below' },
    { key: 'private', icon: Lock, title: 'Private', sub: 'Visible only to you' },
] as const;


export function ShareSheet({
    open,
    onOpenChange,
    share,
    eventName,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    share: ShareState;
    eventName: string;
}) {
    const [copied, setCopied] = useState(false);
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
                    <SheetTitle className="font-heading text-primary text-2xl">Share event</SheetTitle>
                    <p className="text-muted-foreground -mt-1 text-sm">{eventName}</p>
                </SheetHeader>

                {/* Visibility */}
                <div className="mt-2 flex flex-col gap-2">
                    <p className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Who can add spends</p>
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
                                    className={`flex size-9 items-center justify-center rounded-lg ${active ? 'bg-primary text-primary-foreground' : 'bg-accent-subtle text-primary'}`}
                                >
                                    <v.icon className="size-4.5" />
                                </span>
                                <span className="flex-1">
                                    <span className="text-primary block font-medium">{v.title}</span>
                                    <span className="text-muted-foreground block text-xs">{v.sub}</span>
                                </span>
                                <span
                                    className={`flex size-5 items-center justify-center rounded-full border ${active ? 'border-primary bg-primary text-primary-foreground' : 'border-border'}`}
                                >
                                    {active && <Check className="size-3.5" />}
                                </span>
                            </button>
                        );
                    })}
                </div>

                {/* People */}
                <div className="mt-5 flex flex-col gap-1">
                    <p className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Can add spends</p>
                    {state.members.map((m) => {
                        // Private = owner only, so everyone else is dimmed and locked out.
                        const excluded = state.visibility === 'private' && !m.isOwner;
                        return (
                            <div key={m.id} className={`flex items-center gap-3 py-2 transition-opacity ${excluded ? 'opacity-40' : ''}`}>
                                <MemberAvatar userId={m.id} name={m.name} className="size-9 text-sm" />
                                <div className="flex-1">
                                    <div className="text-primary text-sm font-medium">{m.name}</div>
                                    {m.isOwner && <div className="text-muted-foreground text-xs">Owner</div>}
                                </div>
                                {m.isOwner ? (
                                    <span className="text-muted-foreground text-sm">Owner</span>
                                ) : excluded ? (
                                    <span className="text-muted-foreground text-sm">No access</span>
                                ) : state.canManage ? (
                                    <select
                                        value={m.role ?? 'none'}
                                        onChange={(e) =>
                                            e.target.value === 'none' ? removeMember(m.id) : setRole(m.id, e.target.value as ShareMember['role'])
                                        }
                                        className="border-border bg-surface-card text-primary rounded-lg border py-1.5 pr-7 pl-3 text-sm"
                                    >
                                        <option value="editor">Can add</option>
                                        <option value="viewer">Can view</option>
                                        {/* Removing a person only sticks once visibility is narrowed; in
                                        "family" mode everyone can add regardless. */}
                                        {state.visibility !== 'family' && <option value="none">No access</option>}
                                    </select>
                                ) : (
                                    <span className="text-muted-foreground text-sm">
                                        {m.role === 'editor' ? 'Can add' : m.role === 'viewer' ? 'Can view' : 'No access'}
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* Invite link */}
                <div className="mt-5 flex flex-col gap-2">
                    <p className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Invite link</p>
                    {state.link ? (
                        <>
                            <div className="bg-surface flex items-center gap-2 rounded-xl border p-2 pl-3">
                                <Link2 className="text-muted-foreground size-4 shrink-0" />
                                <span className="text-primary flex-1 truncate text-sm">{state.link.url}</span>
                                <button
                                    onClick={copy}
                                    className="bg-primary text-primary-foreground inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium"
                                >
                                    {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                                    {copied ? 'Copied' : 'Copy'}
                                </button>
                            </div>
                            <p className="text-muted-foreground text-xs">Anyone with the link can view this event.</p>
                        </>
                    ) : state.canManage ? (
                        <button
                            onClick={createLink}
                            className="bg-surface-card hover:border-accent text-primary inline-flex items-center justify-center gap-2 rounded-xl border p-3 text-sm font-medium transition-colors"
                        >
                            <Link2 className="size-4" /> Create a view-only link
                        </button>
                    ) : (
                        <p className="text-muted-foreground text-sm">No invite link.</p>
                    )}
                </div>

                <button
                    onClick={() => onOpenChange(false)}
                    className="bg-primary text-primary-foreground mt-6 w-full rounded-xl py-3 text-sm font-semibold"
                >
                    Done
                </button>
            </SheetContent>
        </Sheet>
    );
}
