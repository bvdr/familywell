import { MODULE_ITEMS } from '@/components/mobile-tab-bar';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';
import { Bell, ChevronLeft, ChevronRight, CreditCard, Image, KeyRound, Languages, type LucideIcon, Palette, PlugZap, Trash2, User } from 'lucide-react';

// key/hintKey are translation keys resolved at render (so the nav is localized). hintKey is the short grey
// line under the title in the phone menu (Slack-style); the desktop sidebar shows the title only.
type Item = { key: string; hintKey?: string; url: string; icon: LucideIcon };

const accountItems: Item[] = [
    { key: 'settings.profile', hintKey: 'settings.hint_profile', url: '/settings/profile', icon: User },
    { key: 'settings.sign_in_methods', hintKey: 'settings.hint_sign_in', url: '/settings/sign-in', icon: KeyRound },
    { key: 'settings.appearance', hintKey: 'settings.hint_appearance', url: '/settings/appearance', icon: Palette },
    { key: 'settings.language', hintKey: 'settings.hint_language', url: '/settings/language', icon: Languages },
];

const familyItems: Item[] = [
    { key: 'settings.media', hintKey: 'settings.hint_media', url: '/settings/media', icon: Image },
    { key: 'trash.title', hintKey: 'settings.hint_trash', url: '/settings/recently-deleted', icon: Trash2 },
    { key: 'mcp.connected_apps', hintKey: 'settings.hint_connected_apps', url: '/settings/connected-apps', icon: PlugZap },
    { key: 'billing.title', hintKey: 'settings.hint_billing', url: '/settings/billing', icon: CreditCard },
];

// Modules with their own settings page, keyed by module id. Listed under a "Modules" label after the app's own
// sections, only when the family has that module enabled (`modules.enabled`, the same filter the app sidebar uses).
// A module page keeps its own URL and layout; this is just the one place to find them all. New module with a
// settings page? Add it here. (Notifications lives here, not above: it is a module, with per-module categories.)
// Icons come from the nav's MODULE_ITEMS so a module looks the same everywhere.
const moduleIcon = (id: string) => MODULE_ITEMS.find((m) => m.key === id)?.icon ?? Bell;
const moduleSettingsItems: Record<string, Item> = {
    spending: { key: 'nav.spending', url: '/m/spending/settings', icon: moduleIcon('spending') },
    shopping: { key: 'nav.shopping', url: '/m/shopping/settings', icon: moduleIcon('shopping') },
    inventory: { key: 'nav.inventory', url: '/m/inventory/settings', icon: moduleIcon('inventory') },
    vehicles: { key: 'nav.vehicles', url: '/m/vehicles/settings', icon: moduleIcon('vehicles') },
    expense_import: { key: 'nav.expense_import', url: '/m/expense-import/settings', icon: moduleIcon('expense_import') },
    notifications: { key: 'nav.notifications', hintKey: 'settings.hint_notifications', url: '/settings/notifications', icon: Bell },
};

// The settings sections this user can see, grouped. Shared by the phone menu (/settings) and the desktop sidebar,
// so both always list the same pages.
function useSettingsGroups() {
    const t = useT();
    // Billing only exists on the hosted plan (STRIPE_KEY set); self-hosted installs never see it.
    const { billingEnabled, modules } = usePage<{ billingEnabled?: boolean; modules?: { enabled?: string[] } }>().props;
    const enabled = new Set(modules?.enabled ?? []);
    return [
        { id: 'account', label: t('settings.group_account'), items: accountItems },
        { id: 'family', label: t('settings.group_family'), items: familyItems.filter((i) => billingEnabled || i.url !== '/settings/billing') },
        {
            id: 'modules',
            label: t('nav.modules'),
            items: Object.entries(moduleSettingsItems)
                .filter(([id]) => enabled.has(id))
                .map(([, item]) => item),
        },
    ].filter((g) => g.items.length > 0);
}

// The phone settings home (Slack-style): grouped rows of icon + title + hint + chevron. Tapping one opens that
// page, which shows a back link to here instead of the whole nav (see SettingsLayout).
export function SettingsMenu() {
    const t = useT();
    return (
        <div className="space-y-6">
            {useSettingsGroups().map((g) => (
                <section key={g.id} className="space-y-2">
                    <h2 id={`settings-${g.id}-label`} className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">
                        {g.label}
                    </h2>
                    <div
                        role="group"
                        aria-labelledby={`settings-${g.id}-label`}
                        className="bg-surface-card divide-border/60 divide-y overflow-hidden rounded-xl border"
                    >
                        {g.items.map(({ key, hintKey, url, icon: Icon }) => (
                            <Link key={url} href={url} prefetch className="hover:bg-muted/50 flex items-center gap-3 px-4 py-3">
                                <Icon className="text-primary size-5 shrink-0" />
                                <span className="min-w-0 flex-1">
                                    <span className="text-primary block text-sm font-medium">{t(key)}</span>
                                    {hintKey && <span className="text-muted-foreground block text-xs">{t(hintKey)}</span>}
                                </span>
                                <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                            </Link>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = window.location.pathname;
    const t = useT();
    const groups = useSettingsGroups();

    const navLink = (item: Item) => (
        <Button
            key={item.url}
            size="sm"
            variant="ghost"
            asChild
            className={cn('w-full justify-start', {
                'bg-muted': currentPath === item.url,
            })}
        >
            <Link href={item.url} prefetch>
                {t(item.key)}
            </Link>
        </Button>
    );

    return (
        <div className="px-4 py-6">
            {/* Phones drill down: the menu lives at /settings, a section page only links back to it. */}
            <Link href="/settings" className="text-primary -ml-1 mb-4 inline-flex min-h-11 items-center gap-1 text-sm font-semibold lg:hidden">
                <ChevronLeft className="size-5" />
                {t('settings.title')}
            </Link>
            <div className="hidden lg:block">
                <Heading title={t('settings.title')} description={t('settings.manage')} />
            </div>

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="hidden w-48 shrink-0 lg:block">
                    <nav className="flex flex-col space-y-1">
                        {groups.map((g, i) => (
                            <div key={g.id} className="flex flex-col space-y-1">
                                {i > 0 && (
                                    <h3 className="text-muted-foreground px-3 pt-4 pb-1 text-xs font-semibold tracking-widest uppercase">{g.label}</h3>
                                )}
                                {g.items.map(navLink)}
                            </div>
                        ))}
                    </nav>
                </aside>

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
