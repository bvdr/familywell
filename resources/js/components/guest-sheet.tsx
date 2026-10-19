import { Segmented } from '@/components/segmented';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { save } from '@/lib/save';
import { router } from '@inertiajs/react';
import { Check, Copy, Link2, Lock, LockOpen, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

// Reusable, module-agnostic guest-sharing manager. Any entity that uses the core GuestShareable trait
// and is registered in GuestableRegistry can drop this in: it posts to the generic
// /guest-links/{type}/{id} endpoints. Guests are named, account-less participants; the link is an
// unguessable URL that can optionally be password-gated.
export type GuestPerson = { id: number; name: string; role: 'viewer' | 'editor' };
// Guest roles for the segmented control (this sheet's copy is plain English, not i18n keys).
const ROLE_OPTIONS: { value: GuestPerson['role']; label: string }[] = [
    { value: 'editor', label: 'Can add' },
    { value: 'viewer', label: 'View only' },
];

export type GuestShareState = {
    type: string;
    id: number;
    canManage: boolean;
    enabled: boolean;
    hasPassword: boolean;
    url: string | null;
    guests: GuestPerson[];
};

const initial = (name: string) => name.charAt(0).toUpperCase();

export function GuestSheet({
    open,
    onOpenChange,
    guestShare,
    entityName,
    people = true,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    guestShare: GuestShareState;
    entityName: string;
    // false = a read-only public link (recipes): no named guests, just the link + optional password.
    people?: boolean;
}) {
    const [copied, setCopied] = useState(false);
    const [state, setState] = useState(guestShare);
    // Re-sync whenever the server confirms (props change), or revert an optimistic change on failure.
    useEffect(() => setState(guestShare), [guestShare]);

    const [newName, setNewName] = useState('');
    const [newRole, setNewRole] = useState<GuestPerson['role']>('editor');
    const [password, setPassword] = useState('');

    const base = `/guest-links/${state.type}/${state.id}`;
    const opts = { preserveScroll: true, preserveState: true } as const;
    const revert = () => setState(guestShare);

    const toggle = (enabled: boolean) => {
        setState((s) => ({ ...s, enabled })); // optimistic
        void save('patch', base, { enabled }).then((r) => r !== 'ok' && revert());
    };
    const savePassword = () => {
        router.patch(`${base}/password`, { password }, { ...opts, onSuccess: () => setPassword('') });
    };
    const clearPassword = () => router.patch(`${base}/password`, { password: '' }, opts);
    const addGuest = () => {
        const name = newName.trim();
        if (!name) return;
        router.post(`${base}/guests`, { name, role: newRole }, { ...opts, onSuccess: () => setNewName('') });
    };
    const setRole = (id: number, role: GuestPerson['role']) => {
        setState((s) => ({ ...s, guests: s.guests.map((g) => (g.id === id ? { ...g, role } : g)) })); // optimistic
        void save('patch', `${base}/guests/${id}`, { role }).then((r) => r !== 'ok' && revert());
    };
    const removeGuest = (id: number) => {
        setState((s) => ({ ...s, guests: s.guests.filter((g) => g.id !== id) })); // optimistic
        void save('delete', `${base}/guests/${id}`).then((r) => r !== 'ok' && revert());
    };
    const copy = () => {
        if (!state.url) return;
        navigator.clipboard.writeText(state.url).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1600);
        });
    };

    return (
        // Keyboard-safe drawer (pinned header + footer, scrolling body) so the password / guest-name
        // inputs stay usable with the iOS keyboard open. See ResponsiveModal.
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title="Guest link"
            description={people ? `Let people without an account join ${entityName}` : `Anyone with the link can view ${entityName}`}
            footer={
                <button
                    onClick={() => onOpenChange(false)}
                    className="bg-accent-strong text-accent-strong-foreground w-full rounded-xl py-3 text-sm font-semibold"
                >
                    Done
                </button>
            }
        >
            <div className="flex flex-col pt-1">
                {/* On / off */}
                <button
                    type="button"
                    disabled={!state.canManage}
                    onClick={() => toggle(!state.enabled)}
                    className={`flex items-center gap-3 rounded-xl border p-3 text-left transition-colors ${
                        state.enabled ? 'border-primary bg-accent-subtle' : 'bg-surface-card hover:border-accent'
                    } ${state.canManage ? '' : 'opacity-70'}`}
                >
                    <span
                        className={`flex size-9 items-center justify-center rounded-lg ${state.enabled ? 'bg-tile text-tile-foreground' : 'bg-accent-subtle text-primary'}`}
                    >
                        <Link2 className="size-4.5" />
                    </span>
                    <span className="flex-1">
                        <span className="text-primary block font-medium">Guest link {state.enabled ? 'on' : 'off'}</span>
                        <span className="text-muted-foreground block text-xs">
                            {state.enabled ? 'Anyone with the link can open this' : 'Turn on to share with guests'}
                        </span>
                    </span>
                    <span className={`relative h-6 w-10 rounded-full transition-colors ${state.enabled ? 'bg-accent-strong' : 'bg-border'}`}>
                        <span
                            className={`absolute top-0.5 size-5 rounded-full bg-white transition-all ${state.enabled ? 'left-[18px]' : 'left-0.5'}`}
                        />
                    </span>
                </button>

                {state.enabled && state.url && (
                    <>
                        {/* Link + copy */}
                        <div className="bg-surface mt-3 flex items-center gap-2 rounded-xl border p-2 pl-3">
                            <Link2 className="text-muted-foreground size-4 shrink-0" />
                            <span className="text-primary flex-1 truncate text-sm">{state.url}</span>
                            <button
                                onClick={copy}
                                className="bg-accent-strong text-accent-strong-foreground inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium"
                            >
                                {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                                {copied ? 'Copied' : 'Copy'}
                            </button>
                        </div>

                        {/* Password gate (optional) */}
                        {state.canManage && (
                            <div className="mt-3 flex flex-col gap-2">
                                <p className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Password</p>
                                <div className="flex items-center gap-2">
                                    <span className="text-muted-foreground flex size-9 shrink-0 items-center justify-center">
                                        {state.hasPassword ? <Lock className="text-primary size-4.5" /> : <LockOpen className="size-4.5" />}
                                    </span>
                                    <input
                                        type="password"
                                        value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && savePassword()}
                                        placeholder={state.hasPassword ? 'Change password…' : 'Add a password (optional)'}
                                        className="border-border bg-surface-card text-primary focus:border-accent flex-1 rounded-lg border px-3 py-2 text-base outline-none md:text-sm"
                                    />
                                    <button
                                        onClick={savePassword}
                                        disabled={!password.trim()}
                                        className="bg-accent-strong text-accent-strong-foreground rounded-lg px-3 py-2 text-sm font-medium disabled:opacity-40"
                                    >
                                        Save
                                    </button>
                                </div>
                                {state.hasPassword && (
                                    <button onClick={clearPassword} className="text-muted-foreground hover:text-primary self-start text-xs underline">
                                        Remove password
                                    </button>
                                )}
                            </div>
                        )}

                        {/* Guest list */}
                        {people && (
                            <div className="mt-5 flex flex-col gap-1">
                                <p className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">People</p>
                                {state.guests.length === 0 && <p className="text-muted-foreground py-2 text-sm">No guests yet. Add someone below.</p>}
                                {state.guests.map((g) => (
                                    <div key={g.id} className="flex items-center gap-3 py-2">
                                        <span className="bg-accent-subtle text-primary flex size-9 items-center justify-center rounded-full text-sm font-medium">
                                            {initial(g.name)}
                                        </span>
                                        <div className="text-primary flex-1 text-sm font-medium">{g.name}</div>
                                        {state.canManage ? (
                                            <>
                                                <Segmented
                                                    ariaLabel={g.name}
                                                    options={ROLE_OPTIONS}
                                                    value={g.role}
                                                    // Re-clicking the checked option is a no-op (a native select never fired for it).
                                                    onChange={(v) => v !== g.role && setRole(g.id, v)}
                                                />
                                                <button
                                                    onClick={() => removeGuest(g.id)}
                                                    aria-label={`Remove ${g.name}`}
                                                    className="text-muted-foreground flex size-8 items-center justify-center hover:text-[var(--warning)]"
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground text-sm">{g.role === 'editor' ? 'Can add' : 'View only'}</span>
                                        )}
                                    </div>
                                ))}

                                {/* Add guest. Name on its own row, then role + an explicit "Add person" button
                                so the action is obvious (a lone icon crammed on the input row got clipped
                                off the drawer edge, leaving Enter as the only non-obvious way to add). */}
                                {state.canManage && (
                                    <div className="border-border/60 mt-2 flex flex-col gap-2 border-t pt-3">
                                        <input
                                            value={newName}
                                            onChange={(e) => setNewName(e.target.value)}
                                            onKeyDown={(e) => e.key === 'Enter' && addGuest()}
                                            placeholder="Guest name"
                                            className="border-border bg-surface-card text-primary focus:border-accent w-full rounded-lg border px-3 py-2 text-base outline-none md:text-sm"
                                        />
                                        <div className="flex gap-2">
                                            <Segmented ariaLabel="Role" options={ROLE_OPTIONS} value={newRole} onChange={setNewRole} />
                                            <button
                                                onClick={addGuest}
                                                disabled={!newName.trim()}
                                                className="bg-accent-strong text-accent-strong-foreground flex flex-1 items-center justify-center gap-1.5 rounded-lg py-2 text-sm font-semibold disabled:opacity-40"
                                            >
                                                <Plus className="size-4.5" /> Add person
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </>
                )}
            </div>
        </ResponsiveModal>
    );
}
