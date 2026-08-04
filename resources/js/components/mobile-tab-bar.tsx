import { runAddAction } from '@/lib/add-action';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Blocks,
    FolderKanban,
    LayoutGrid,
    ListChecks,
    Loader2,
    type LucideIcon,
    MoreHorizontal,
    Pin,
    Plus,
    Settings,
    ShoppingCart,
    StickyNote,
    Users,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';

// The DS floating mobile navigation: a translucent, blurred pill above the content. It shows up to
// four PINNED destinations plus a "More" button that opens a drawer with everything else; the pinned
// set is user-selectable (pin/unpin in the More drawer, saved per device). Desktop uses the sidebar.
// `method: 'post'` marks a quick-add that creates server-side on click (e.g. a new note is made
// immediately and you land in its editor) rather than deep-linking to an index that opens a sheet.
type Item = { key: string; title: string; url: string; icon: LucideIcon; method?: 'post' };

const HOME: Item = { key: 'dashboard', title: 'Home', url: '/dashboard', icon: LayoutGrid };
// Page-modules earn a nav entry when the family has enabled them (order = preferred display order).
const MODULE_ITEMS: Item[] = [
    { key: 'projects', title: 'Projects', url: '/m/projects', icon: FolderKanban },
    { key: 'tasks', title: 'Tasks', url: '/m/tasks', icon: ListChecks },
    { key: 'shopping', title: 'Shopping', url: '/m/shopping', icon: ShoppingCart },
    { key: 'spending', title: 'Spending', url: '/m/spending', icon: Wallet },
    { key: 'notes', title: 'Notes', url: '/m/notes', icon: StickyNote },
];
const SECONDARY: Item[] = [
    { key: 'members', title: 'Members', url: '/family/members', icon: Users },
    { key: 'modules', title: 'Modules', url: '/family/modules', icon: Blocks },
    { key: 'settings', title: 'Settings', url: '/settings/profile', icon: Settings },
];

// The green "+" quick-add: each enabled module's top-level create, deep-linked with ?new=1 so the
// target index opens its create sheet on arrival. Order mirrors the nav.
const CREATE: Item[] = [
    { key: 'shopping', title: 'New shopping list', url: '/m/shopping?new=1', icon: ShoppingCart },
    { key: 'tasks', title: 'New to-do list', url: '/m/tasks?new=1', icon: ListChecks },
    { key: 'projects', title: 'New project', url: '/m/projects?new=1', icon: FolderKanban },
    { key: 'spending', title: 'New event', url: '/m/spending?new=1', icon: Wallet },
    // A note has no create sheet: POST straight to notes.store, which redirects into the new editor.
    { key: 'notes', title: 'New note', url: '/m/notes', method: 'post', icon: StickyNote },
];

const PINS_KEY = 'familywell:navPins';
const MAX_PINS = 4;
// Default pins: this preferred order, intersected with what's actually available, first four.
const DEFAULT_ORDER = ['dashboard', 'projects', 'shopping', 'spending', 'members', 'modules', 'settings'];

export function MobileTabBar() {
    const page = usePage<{ modules?: { enabled?: string[] } }>();
    const enabled = page.props.modules?.enabled ?? [];

    // Everything reachable from the mobile nav, in display order.
    const allItems: Item[] = [HOME, ...MODULE_ITEMS.filter((m) => enabled.includes(m.key)), ...SECONDARY];
    const byKey = new Map(allItems.map((i) => [i.key, i]));

    const [pins, setPins] = useState<string[]>(() => {
        if (typeof window !== 'undefined') {
            try {
                const saved = JSON.parse(localStorage.getItem(PINS_KEY) || 'null');
                if (Array.isArray(saved)) return saved;
            } catch {
                /* fall through to default */
            }
        }
        return [];
    });
    const savePins = (next: string[]) => {
        setPins(next);
        try {
            localStorage.setItem(PINS_KEY, JSON.stringify(next));
        } catch {
            /* private mode: session-only */
        }
    };

    // Resolve the pinned bar items: saved pins (that still exist), else the default set.
    const savedPinned = pins.map((k) => byKey.get(k)).filter((i): i is Item => !!i);
    const pinned = (savedPinned.length ? savedPinned : DEFAULT_ORDER.map((k) => byKey.get(k)).filter((i): i is Item => !!i)).slice(0, MAX_PINS);
    const pinnedKeys = new Set(pinned.map((i) => i.key));

    const [moreOpen, setMoreOpen] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const createItems = CREATE.filter((c) => enabled.includes(c.key));
    const [pending, setPending] = useState<string | null>(null);
    useEffect(() => {
        const offFinish = router.on('finish', (e) => {
            if (!e.detail.visit.prefetch) setPending(null);
        });
        return offFinish;
    }, []);

    const currentPath = pending ?? page.url;
    const isActive = (url: string) => currentPath === url || currentPath.startsWith(url + '/');
    // "More" lights up when the current page is a destination that isn't pinned to the bar.
    const moreActive = allItems.some((i) => !pinnedKeys.has(i.key) && isActive(i.url));

    const togglePin = (key: string) => {
        if (pins.length === 0) savePins([...pinned.map((i) => i.key)]); // materialize defaults before first edit
        if (pinnedKeys.has(key)) {
            savePins((pins.length ? pins : pinned.map((i) => i.key)).filter((k) => k !== key));
        } else if (pinned.length < MAX_PINS) {
            savePins([...(pins.length ? pins : pinned.map((i) => i.key)), key]);
        }
    };

    // Cells share the pill width evenly (flex-1) so the bar looks the same regardless of item count.
    const cell = 'flex flex-1 flex-col items-center gap-0.5 rounded-full py-1.5 text-[10px] font-medium transition-colors';

    return (
        <>
            {/* translateZ(0) promotes the bar to its own layer so iOS standalone PWAs keep it pinned to
                the viewport bottom instead of letting it scroll with the body (a known iOS fixed bug). */}
            <nav className="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex items-center gap-2.5 px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] [transform:translateZ(0)] [@media(display-mode:standalone)]:pb-[max(0.5rem,calc(env(safe-area-inset-bottom)/2))] md:hidden">
                {/* Pill fills the width; the + stays a fixed circle on the right. */}
                <div className="border-border/60 pointer-events-auto flex flex-1 items-center justify-around gap-0.5 rounded-full border bg-white/75 p-1.5 shadow-[var(--shadow-fw-md)] backdrop-blur-xl">
                    {pinned.map((item) => {
                        const active = isActive(item.url);
                        const loading = pending === item.url;
                        return (
                            <Link
                                key={item.key}
                                href={item.url}
                                onClick={() => setPending(item.url)}
                                prefetch="mount"
                                cacheFor="30s"
                                className={`${cell} ${active ? 'bg-accent-subtle text-primary font-semibold' : 'text-text-muted'}`}
                            >
                                {loading ? <Loader2 className="size-5.5 animate-spin" /> : <item.icon className="size-5.5" />}
                                {item.title}
                            </Link>
                        );
                    })}
                    <button
                        type="button"
                        onClick={() => setMoreOpen(true)}
                        className={`${cell} ${moreActive ? 'bg-accent-subtle text-primary font-semibold' : 'text-text-muted'}`}
                    >
                        <MoreHorizontal className="size-5.5" />
                        More
                    </button>
                </div>

                {/* Detached green add button on the right (Slack-style separate action). Context-aware:
                    if the current page claimed it (useAddAction) run that; else open the quick-add sheet. */}
                <button
                    type="button"
                    onClick={() => {
                        if (!runAddAction()) setAddOpen(true);
                    }}
                    aria-label="Add"
                    className="bg-primary text-primary-foreground pointer-events-auto flex size-14 shrink-0 items-center justify-center rounded-full shadow-[var(--shadow-fw-md)] transition-transform active:scale-95"
                >
                    <Plus className="size-6" />
                </button>
            </nav>

            <ResponsiveModal open={addOpen} onOpenChange={setAddOpen} title="Add" description="Create something new">
                <div className="flex flex-col gap-1">
                    {createItems.length === 0 ? (
                        <Link
                            href="/family/modules"
                            onClick={() => setAddOpen(false)}
                            className="text-primary hover:bg-surface flex items-center gap-3 rounded-xl p-3 transition-colors"
                        >
                            <Blocks className="size-5" />
                            <span className="text-sm font-medium">Turn on a module to add things</span>
                        </Link>
                    ) : (
                        createItems.map((item) => (
                            <Link
                                key={item.key}
                                href={item.url}
                                method={item.method ?? 'get'}
                                as={item.method === 'post' ? 'button' : undefined}
                                onClick={() => setAddOpen(false)}
                                className="text-primary hover:bg-surface flex w-full items-center gap-3 rounded-xl p-3 text-left transition-colors"
                            >
                                <span className="bg-primary text-primary-foreground flex size-9 items-center justify-center rounded-lg">
                                    <item.icon className="size-4.5" />
                                </span>
                                <span className="text-sm font-medium">{item.title}</span>
                            </Link>
                        ))
                    )}
                </div>
            </ResponsiveModal>

            <ResponsiveModal open={moreOpen} onOpenChange={setMoreOpen} title="More" description="Tap a pin to choose your four bottom-bar shortcuts">
                <div className="flex flex-col gap-1">
                    {allItems.map((item) => {
                        const isPinned = pinnedKeys.has(item.key);
                        const atMax = !isPinned && pinned.length >= MAX_PINS;
                        return (
                            <div key={item.key} className="flex items-center gap-1">
                                <Link
                                    href={item.url}
                                    onClick={() => {
                                        setMoreOpen(false);
                                        setPending(item.url);
                                    }}
                                    className={`flex flex-1 items-center gap-3 rounded-xl p-3 transition-colors ${
                                        isActive(item.url) ? 'bg-accent-subtle text-primary' : 'text-primary hover:bg-surface'
                                    }`}
                                >
                                    <item.icon className="size-5" />
                                    <span className="text-sm font-medium">{item.title}</span>
                                </Link>
                                <button
                                    type="button"
                                    onClick={() => togglePin(item.key)}
                                    disabled={atMax}
                                    aria-label={isPinned ? `Unpin ${item.title}` : `Pin ${item.title} to the bar`}
                                    aria-pressed={isPinned}
                                    className={`flex size-9 items-center justify-center rounded-lg transition-colors ${
                                        isPinned ? 'text-primary' : atMax ? 'text-text-muted/40' : 'text-muted-foreground hover:bg-surface'
                                    }`}
                                >
                                    <Pin className={`size-4.5 ${isPinned ? 'fill-current' : ''}`} />
                                </button>
                            </div>
                        );
                    })}
                </div>
            </ResponsiveModal>
        </>
    );
}
