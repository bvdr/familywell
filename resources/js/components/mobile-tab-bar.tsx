import { JustSayIt, type SubView } from '@/components/add-just-say-it';
import { Segmented } from '@/components/segmented';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useNavPins } from '@/hooks/use-nav-pins';
import { hasAddAction, runAddAction } from '@/lib/add-action';
import { useT } from '@/lib/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Cake,
    CalendarDays,
    Car,
    ChefHat,
    ChevronRight,
    Contact,
    FolderKanban,
    Import,
    LayoutGrid,
    ListChecks,
    Loader2,
    type LucideIcon,
    MoreHorizontal,
    Package,
    Pin,
    Plus,
    Settings,
    ShoppingCart,
    StickyNote,
    Trash2,
    Users,
    Wallet,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

// The DS floating mobile navigation (design XMkKm): a translucent pill with Home, the three modules this device
// pinned, and More; the sun "+" sits apart on the right. More opens a sheet with every module and its live status
// (sun pins mark the three in the bar; long-press a tile to pin or unpin it) plus the family's own places. "+"
// runs the current page's add action, else opens the Add sheet. Desktop uses the sidebar.
// `method: 'post'` marks a quick-add that creates server-side on click (a new note is made immediately and you land
// in its editor) rather than deep-linking to an index that opens a sheet. labelKey is a translation key.
type Item = { key: string; labelKey: string; url: string; icon: LucideIcon; method?: 'post' };

const HOME: Item = { key: 'dashboard', labelKey: 'nav.home', url: '/dashboard', icon: LayoutGrid };
// Page-modules earn a nav entry when the family has enabled them (order = preferred display order).
export const MODULE_ITEMS: Item[] = [
    { key: 'shopping', labelKey: 'nav.shopping', url: '/m/shopping', icon: ShoppingCart },
    { key: 'tasks', labelKey: 'nav.tasks', url: '/m/tasks', icon: ListChecks },
    { key: 'calendar', labelKey: 'nav.calendar', url: '/m/calendar', icon: CalendarDays },
    { key: 'spending', labelKey: 'nav.spending', url: '/m/spending', icon: Wallet },
    { key: 'projects', labelKey: 'nav.projects', url: '/m/projects', icon: FolderKanban },
    { key: 'recipes', labelKey: 'nav.recipes', url: '/m/recipes', icon: ChefHat },
    { key: 'inventory', labelKey: 'nav.inventory', url: '/m/inventory', icon: Package },
    { key: 'vehicles', labelKey: 'nav.vehicles', url: '/m/vehicles', icon: Car },
    { key: 'notes', labelKey: 'nav.notes', url: '/m/notes', icon: StickyNote },
    { key: 'contacts', labelKey: 'nav.contacts', url: '/m/contacts', icon: Contact },
    { key: 'birthdays', labelKey: 'nav.birthdays', url: '/m/birthdays', icon: Cake },
    { key: 'expense_import', labelKey: 'nav.expense_import', url: '/m/expense-import', icon: Import },
];
// The family's own places, under the modules in More.
const FAMILY_ITEMS: Item[] = [
    { key: 'members', labelKey: 'nav.members', url: '/family/members', icon: Users },
    { key: 'alerts', labelKey: 'nav.alerts', url: '/notifications', icon: Bell },
    { key: 'deleted', labelKey: 'nav.deleted', url: '/settings/recently-deleted', icon: Trash2 },
    { key: 'settings', labelKey: 'nav.settings', url: '/settings', icon: Settings },
];

// The "+" quick-add (Classic add): each enabled module's top-level create, deep-linked with ?new=1 so the target
// index opens its create sheet on arrival. Ranked by what the family added most in 30 days (/add/usage).
const CREATE: Item[] = [
    { key: 'shopping', labelKey: 'create.shopping', url: '/m/shopping?new=1', icon: ShoppingCart },
    { key: 'calendar', labelKey: 'create.calendar', url: '/m/calendar?new=1', icon: CalendarDays },
    { key: 'tasks', labelKey: 'create.tasks', url: '/m/tasks?new=1', icon: ListChecks },
    { key: 'spending', labelKey: 'create.spending', url: '/m/spending?new=1', icon: Wallet },
    { key: 'projects', labelKey: 'create.projects', url: '/m/projects?new=1', icon: FolderKanban },
    // A note has no create sheet: POST straight to notes.store, which redirects into the new editor.
    { key: 'notes', labelKey: 'create.notes', url: '/m/notes', method: 'post', icon: StickyNote },
    // A recipe has a full editor (no sheet): deep-link straight to it.
    { key: 'recipes', labelKey: 'create.recipes', url: '/m/recipes/new', icon: ChefHat },
    // A vehicle has a full add form (no sheet): deep-link straight to it.
    { key: 'vehicles', labelKey: 'create.vehicles', url: '/m/vehicles/new', icon: Car },
    { key: 'inventory', labelKey: 'create.inventory', url: '/m/inventory?new=1', icon: Package },
];
// Classic add shows this many as rows (with their 30-day count); the rest are chips under "Everything else".
const TOP = 4;

const LONG_PRESS_MS = 500;

// Long-press = a second action on a tappable tile/row (pin or unpin), shared by More and Classic add. The timer fires
// the action; the click that follows the release is swallowed (`swallow`) so a long-press doesn't also navigate.
function useLongPress(onLong: (key: string) => void) {
    const timer = useRef<number | null>(null);
    const longPressed = useRef(false);
    const end = () => {
        if (timer.current) window.clearTimeout(timer.current);
        timer.current = null;
    };
    return {
        bind: (key: string) => ({
            onPointerDown: () => {
                longPressed.current = false;
                timer.current = window.setTimeout(() => {
                    longPressed.current = true;
                    onLong(key);
                }, LONG_PRESS_MS);
            },
            onPointerUp: end,
            onPointerLeave: end,
            onPointerCancel: end,
            onContextMenu: (e: React.MouseEvent) => e.preventDefault(),
        }),
        // Call from onClick: true (and the click cancelled) when this click is the end of a long-press.
        swallow: (e: React.MouseEvent) => {
            if (!longPressed.current) return false;
            e.preventDefault();
            return true;
        },
    };
}

// A JSON GET for the sheets' live data, fetched when a sheet opens (not shared on every page).
function useOnOpen<T>(open: boolean, url: string): T | null {
    const [data, setData] = useState<T | null>(null);
    useEffect(() => {
        if (!open) return;
        const ctrl = new AbortController();
        fetch(url, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => d && setData(d))
            .catch(() => {}); // offline / aborted: the sheet still works without counts
        return () => ctrl.abort();
    }, [open, url]);
    return data;
}

const sectionLabel = 'text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.14em] uppercase';
const iconBox = 'bg-surface-card text-primary flex size-11 items-center justify-center rounded-xl border';

export function MobileTabBar() {
    const page = usePage<{ modules?: { enabled?: string[] }; aiAdd?: boolean }>();
    const aiAdd = !!page.props.aiAdd;
    const t = useT();
    const enabled = page.props.modules?.enabled ?? [];
    const modules = MODULE_ITEMS.filter((m) => enabled.includes(m.key));
    const byKey = new Map(modules.map((i) => [i.key, i]));

    const { pinned: pinnedKeysList, toggle: togglePin, full } = useNavPins(modules.map((m) => m.key));
    const pinned = pinnedKeysList.map((k) => byKey.get(k)!).filter(Boolean);
    const pinnedKeys = new Set(pinnedKeysList);

    const [moreOpen, setMoreOpen] = useState(false);
    const [addOpen, setAddOpen] = useState(false);
    const [pending, setPending] = useState<string | null>(null);
    useEffect(() => {
        const offFinish = router.on('finish', (e) => {
            if (!e.detail.visit.prefetch) setPending(null);
        });
        return offFinish;
    }, []);

    const currentPath = pending ?? page.url;
    const isActive = (url: string) => currentPath === url || currentPath.startsWith(url + '/') || currentPath.startsWith(url + '?');
    // "More" lights up when the current page is a module or family place that isn't in the bar.
    const moreActive = [...modules, ...FAMILY_ITEMS].some((i) => !pinnedKeys.has(i.key) && isActive(i.url));

    // Cells share the pill width evenly (flex-1) so the bar looks the same regardless of item count.
    // py-2 + 22px icon + 11px label = a ~50px tap target; 11px (not 10) is the smallest label that reads on a phone
    // and still fits "Inventory"/"Spending" in a fifth of the pill at 390px.
    const cell = 'flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-full py-2 text-[11px] font-medium transition-colors';

    return (
        <>
            {/* translateZ(0) promotes the bar to its own layer so iOS standalone PWAs keep it pinned to
                the viewport bottom instead of letting it scroll with the body (a known iOS fixed bug). */}
            <nav className="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex [transform:translateZ(0)] items-center gap-2.5 px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] md:hidden [@media(display-mode:standalone)]:pb-[max(0.5rem,calc(env(safe-area-inset-bottom)/2))]">
                {/* Pill fills the width; the + stays a fixed circle on the right. */}
                <div className="border-border/60 bg-surface-card/75 pointer-events-auto flex flex-1 items-center justify-around gap-0.5 rounded-full border p-1.5 shadow-[var(--shadow-fw-md)] backdrop-blur-xl">
                    {[HOME, ...pinned].map((item) => {
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
                                {/* A span, not a bare text node, so a label too long for the bar (Dutch "Boodschappen" did) truncates inside its
                                    min-w-0 cell instead of spilling over the next tab. */}
                                <span className="max-w-full truncate">{t(item.labelKey)}</span>
                            </Link>
                        );
                    })}
                    <button
                        type="button"
                        onClick={() => setMoreOpen(true)}
                        className={`${cell} ${moreActive ? 'bg-accent-subtle text-primary font-semibold' : 'text-text-muted'}`}
                    >
                        <MoreHorizontal className="size-5.5" />
                        {t('nav.more')}
                    </button>
                </div>

                {/* Detached sun add button on the right (Slack-style separate action). Context-aware:
                    if the current page claimed it (useAddAction) run that; else open the Add sheet. */}
                <button
                    type="button"
                    onClick={() => {
                        // With "Just say it" on, + always opens the sheet (the page is its context); the page's own
                        // add is the first row of Classic. Without it, the page's add runs as before.
                        if (aiAdd || !runAddAction()) setAddOpen(true);
                    }}
                    aria-label={t('create.title')}
                    data-tour="add"
                    className="bg-accent-strong text-accent-strong-foreground pointer-events-auto flex size-14 shrink-0 items-center justify-center rounded-full shadow-[var(--shadow-fw-md)] transition-transform active:scale-95"
                >
                    <Plus className="size-6" />
                </button>
            </nav>

            <AddSheet open={addOpen} onOpenChange={setAddOpen} enabled={enabled} />
            <MoreSheet
                open={moreOpen}
                onOpenChange={setMoreOpen}
                modules={modules}
                pinnedKeys={pinnedKeys}
                canPin={!full}
                togglePin={togglePin}
                isActive={isActive}
                onGo={(url) => {
                    setMoreOpen(false);
                    setPending(url);
                }}
            />
        </>
    );
}

// The Add sheet (design XMkKm H2 / H2d): "Just say it" (the AI box, add-just-say-it.tsx) when a reader is configured
// and the family has points, else / or by choice Classic: the family's most-added kinds as rows with their 30-day
// count, everything else as chips. The last mode used is remembered on this device.
const MODE_KEY = 'familywell:addMode';
type Usage = {
    usage: Record<string, number>;
    pins: string[]; // the person's pinned shortcut(s), kept on top (users.add_pins)
    last: Record<string, string>; // module => the last thing this person added there ("bananas")
    ai: { available: boolean; pointsLeft: number | null; limit: number | null };
};

function AddSheet({ open, onOpenChange, enabled }: { open: boolean; onOpenChange: (open: boolean) => void; enabled: string[] }) {
    const t = useT();
    const data = useOnOpen<Usage>(open, '/add/usage');
    const [mode, setModeState] = useState<'say' | 'classic'>(() => {
        try {
            return localStorage.getItem(MODE_KEY) === 'classic' ? 'classic' : 'say';
        } catch {
            return 'say';
        }
    });
    const setMode = (m: 'say' | 'classic') => {
        setModeState(m);
        try {
            localStorage.setItem(MODE_KEY, m);
        } catch {
            /* private mode: this session only */
        }
    };
    // A card's "Add details" (design H2f) is a level down inside this same sheet: Just say it hands up its back pill
    // and "TO-DO · 3 OF 3", and the sheet's close gestures step back to the cards instead (ResponsiveModal onBack).
    const [sub, setSub] = useState<SubView | null>(null);
    useEffect(() => {
        if (!open) setSub(null);
    }, [open]);
    const ai = data?.ai.available ?? false;
    // Read when the sheet renders: the page that claimed the + (only reachable here when "Just say it" is on).
    const pageAction = open && hasAddAction();
    const usage = data?.usage ?? null;
    const last = data?.last ?? {};
    // Pins (design H2d): per person, saved on the server (users.add_pins, like home_layout) so a pin follows the person
    // to every device; the mode stays per device (localStorage) since it's about this screen. Shown at once, saved behind.
    const [pins, setPins] = useState<string[]>([]);
    useEffect(() => {
        if (data) setPins(data.pins);
    }, [data]);
    const togglePin = (key: string) => {
        const before = pins;
        // One pin (AddUsageController::MAX_PINS): pinning another row moves it.
        const next = pins.includes(key) ? [] : [key];
        setPins(next);
        navigator.vibrate?.(10);
        const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
        fetch('/add/pins', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
            body: JSON.stringify({ pins: next }),
        })
            .then((r) => r.ok || setPins(before))
            .catch(() => setPins(before)); // offline: undo, so the row isn't shown pinned when it isn't
    };
    const press = useLongPress(togglePin);
    const items = CREATE.filter((c) => enabled.includes(c.key));
    // Pinned first, then most added; ties (and the moment before the counts arrive) keep the CREATE order.
    const score = (key: string) => (pins.includes(key) ? 1e9 : 0) + (usage?.[key] ?? 0);
    const ranked = [...items].sort((a, b) => score(b.key) - score(a.key));
    const top = ranked.slice(0, TOP);
    const rest = ranked.slice(TOP);
    const close = () => onOpenChange(false);
    const linkProps = (item: Item) => ({
        href: item.url,
        method: item.method ?? ('get' as const),
        as: item.method === 'post' ? ('button' as const) : undefined,
        onClick: close,
    });

    const classic =
        items.length === 0 ? (
            <Link href="/family/modules" onClick={close} className="text-primary hover:bg-surface flex items-center gap-3 rounded-xl p-3">
                <LayoutGrid className="size-5.5" />
                <span className="text-base font-medium">{t('create.turn_on')}</span>
            </Link>
        ) : (
            <div className="flex flex-col gap-5">
                {pageAction && (
                    <button
                        type="button"
                        onClick={() => {
                            close();
                            runAddAction();
                        }}
                        className="bg-accent-subtle text-primary flex w-full items-center gap-3 rounded-2xl border px-3 py-3 text-left"
                    >
                        <span className={iconBox}>
                            <Plus className="size-5.5" />
                        </span>
                        <span className="min-w-0 flex-1 truncate text-base font-semibold">{t('add.on_this_page')}</span>
                        <ChevronRight className="text-muted-foreground size-5 shrink-0" />
                    </button>
                )}
                <section className="flex flex-col gap-2">
                    <h3 className={sectionLabel}>{t('create.most_used')}</h3>
                    <div className="bg-surface divide-border overflow-hidden rounded-2xl border">
                        {top.map((item) => {
                            const isPinned = pins.includes(item.key);
                            // "Pinned · last: bananas" (design H2d); either half alone when the other is missing.
                            const sub = last[item.key]
                                ? t(isPinned ? 'create.pinned_last' : 'create.last', { name: last[item.key] })
                                : isPinned
                                  ? t('create.pinned')
                                  : null;
                            return (
                                // The row opens the shortcut; the pin button beside it (not inside: no button in a link)
                                // pins or unpins it, and so does a long-press on the row, as on More's tiles.
                                <div key={item.key} className="hover:bg-accent-subtle flex items-center border-b last:border-b-0">
                                    <Link
                                        {...linkProps(item)}
                                        {...press.bind(item.key)}
                                        onClick={(e: React.MouseEvent) => press.swallow(e) || close()}
                                        className="flex min-w-0 flex-1 items-center gap-3 py-3 pl-3 text-left select-none [-webkit-touch-callout:none]"
                                    >
                                        <span className={iconBox}>
                                            <item.icon className="size-5.5" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="text-primary block truncate text-base font-semibold">{t(item.labelKey)}</span>
                                            {sub && <span className="text-muted-foreground block truncate text-sm">{sub}</span>}
                                        </span>
                                        {usage && (usage[item.key] ?? 0) > 0 && (
                                            <span className="text-muted-foreground shrink-0 font-mono text-sm tabular-nums">
                                                {t('create.times', { n: usage[item.key] })}
                                            </span>
                                        )}
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={() => togglePin(item.key)}
                                        aria-pressed={isPinned}
                                        aria-label={t(isPinned ? 'create.unpin' : 'create.pin', { name: t(item.labelKey) })}
                                        className={`mr-1 flex size-11 shrink-0 items-center justify-center rounded-full ${isPinned ? 'text-primary' : 'text-muted-foreground/60'}`}
                                    >
                                        <Pin className={`size-5 ${isPinned ? 'fill-current' : ''}`} />
                                    </button>
                                </div>
                            );
                        })}
                    </div>
                </section>
                {rest.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h3 className={sectionLabel}>
                            {t('create.everything_else')}
                            <span className="text-primary ml-2">{rest.length}</span>
                        </h3>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            {rest.map((item) => (
                                <Link
                                    key={item.key}
                                    {...linkProps(item)}
                                    className="bg-surface-card text-primary hover:border-primary/40 flex w-full min-w-0 items-center gap-2 rounded-xl border px-3 py-3 text-left text-sm font-semibold"
                                >
                                    <item.icon className="size-4.5 shrink-0" />
                                    <span className="truncate">{t(item.labelKey)}</span>
                                </Link>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        );

    return (
        // No subtitle under "Add": "Create something new" said the same thing twice (the user, 2026-10-28; the design has none).
        <ResponsiveModal
            open={open}
            onOpenChange={onOpenChange}
            title={t('create.title')}
            onBack={sub?.back}
            backLabel={sub?.label}
            aside={sub && <span className="text-muted-foreground font-mono text-xs font-semibold tracking-[0.14em] uppercase">{sub.aside}</span>}
        >
            <div className="flex flex-col gap-4">
                {ai && !sub && (
                    <Segmented
                        ariaLabel={t('create.title')}
                        value={mode}
                        onChange={setMode}
                        options={[
                            { value: 'say', label: t('add.say_it') },
                            { value: 'classic', label: t('add.classic') },
                        ]}
                    />
                )}
                {ai && mode === 'say' ? (
                    <JustSayIt onClose={close} onClassic={() => setMode('classic')} pointsLeft={data?.ai.pointsLeft ?? null} onSubView={setSub} />
                ) : (
                    classic
                )}
            </div>
        </ResponsiveModal>
    );
}

type ModuleStatus = { key: string; params?: Record<string, string | number> };

// H3 · More: every module that's on with its live status line, sun pins on the three in the tab bar (long-press any
// tile to pin or unpin it), an "All modules" tile, then the family's own places.
function MoreSheet({
    open,
    onOpenChange,
    modules,
    pinnedKeys,
    canPin,
    togglePin,
    isActive,
    onGo,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    modules: Item[];
    pinnedKeys: Set<string>;
    canPin: boolean;
    togglePin: (key: string) => void;
    isActive: (url: string) => boolean;
    onGo: (url: string) => void;
}) {
    const t = useT();
    const data = useOnOpen<{ family: string | null; summaries: Record<string, ModuleStatus> }>(open, '/nav/more');

    // Long-press = pin/unpin; a short tap navigates as normal.
    const press = useLongPress((key) => {
        if (pinnedKeys.has(key) || canPin) {
            togglePin(key);
            navigator.vibrate?.(10);
        }
    });

    const tile = 'relative flex min-h-28 flex-col justify-between gap-3 rounded-2xl p-3 transition-colors select-none';

    return (
        <ResponsiveModal open={open} onOpenChange={onOpenChange} title={t('nav.more')} description={t('nav.more_desc')}>
            <div className="flex flex-col gap-5">
                <section className="flex flex-col gap-2">
                    <h3 className={sectionLabel}>{t('nav.modules')}</h3>
                    <div className="grid grid-cols-3 gap-2">
                        {modules.map((item) => {
                            const isPinned = pinnedKeys.has(item.key);
                            const line = data?.summaries[item.key];
                            return (
                                <Link
                                    key={item.key}
                                    href={item.url}
                                    {...press.bind(item.key)}
                                    onClick={(e) => press.swallow(e) || onGo(item.url)}
                                    aria-label={isPinned ? `${t(item.labelKey)} · ${t('nav.pinned')}` : undefined}
                                    className={`${tile} ${isActive(item.url) ? 'bg-accent-subtle ring-primary ring-1' : 'bg-surface'} [-webkit-touch-callout:none]`}
                                >
                                    <span className={iconBox}>
                                        <item.icon className="size-5.5" />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="text-primary block text-sm leading-tight font-semibold break-words">{t(item.labelKey)}</span>
                                        <span className="text-muted-foreground block truncate text-xs">{line ? t(line.key, line.params) : ' '}</span>
                                    </span>
                                    {isPinned && (
                                        <span className="bg-accent-strong text-accent-strong-foreground absolute top-2.5 right-2.5 flex size-6 items-center justify-center rounded-full">
                                            <Pin className="size-3.5 fill-current" />
                                        </span>
                                    )}
                                </Link>
                            );
                        })}
                        <Link
                            href="/family/modules"
                            onClick={() => onGo('/family/modules')}
                            className={`${tile} bg-surface-card border-border border`}
                        >
                            <span className="bg-surface text-primary flex size-11 items-center justify-center rounded-xl">
                                <LayoutGrid className="size-5.5" />
                            </span>
                            <span className="min-w-0">
                                <span className="text-primary block text-sm leading-tight font-semibold break-words">{t('nav.all_modules')}</span>
                                <span className="text-muted-foreground block truncate text-xs">{t('nav.modules_on', { count: modules.length })}</span>
                            </span>
                        </Link>
                    </div>
                    <p className="text-muted-foreground px-1 text-xs">{t('nav.pin_hint')}</p>
                </section>

                <section className="flex flex-col gap-2">
                    <h3 className={`${sectionLabel} truncate`}>{data?.family ?? t('nav.family')}</h3>
                    <div className="grid grid-cols-4 gap-2">
                        {FAMILY_ITEMS.map((item) => (
                            <Link
                                key={item.key}
                                href={item.url}
                                onClick={() => onGo(item.url)}
                                className={`flex min-w-0 flex-col items-center gap-2 rounded-2xl px-1 py-3 ${isActive(item.url) ? 'bg-accent-subtle' : 'bg-surface'}`}
                            >
                                <span className={iconBox}>
                                    <item.icon className="size-5" />
                                </span>
                                <span className="text-primary max-w-full truncate text-xs font-semibold">{t(item.labelKey)}</span>
                            </Link>
                        ))}
                    </div>
                </section>
            </div>
        </ResponsiveModal>
    );
}
