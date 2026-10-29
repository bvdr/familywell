import { Head, router } from '@inertiajs/react';
import { Bot, Check, Copy, Plug, Sparkles, type LucideIcon } from 'lucide-react';
import { useState } from 'react';

import { SettingsPage } from '@/components/settings-ui';
import AppLayout from '@/layouts/app-layout';
import { useLocale, useT } from '@/lib/i18n';

type App = {
    id: string;
    name: string;
    family: string | null;
    access: Record<string, 'read' | 'write'>;
    missing: string[]; // module ids on in the family that this connection was not granted
    connectedAt: string | null;
    until: string | null;
};

// A recognisable mark for the two assistants people actually connect (design M7); anything else gets a plug.
// Matched on the OAuth client's self-registered name, so it's only a hint, never trusted for anything.
const iconFor = (name: string): LucideIcon => (/claude/i.test(name) ? Sparkles : /chatgpt|openai/i.test(name) ? Bot : Plug);

// Settings > Connected apps (design M7): AI assistants connected through the MCP server (see docs/mcp.md), which
// modules each can see, and Disconnect (revokes the token + its refresh token, so the app loses access immediately).
// The server address to connect a new one sits at the bottom with Copy.
export default function ConnectedApps({ apps, serverUrl }: { apps: App[]; serverUrl: string }) {
    const t = useT();
    const locale = useLocale();
    const [copied, setCopied] = useState(false);
    const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(locale, { day: 'numeric', month: 'short' }) : '');

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(serverUrl);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            // clipboard blocked (insecure context): the address is still visible to copy by hand
        }
    };
    const revoke = (app: App) => {
        if (!confirm(t('mcp.revoke_confirm', { app: app.name }))) return;
        router.delete(`/settings/connected-apps/${app.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: t('mcp.connected_apps'), href: '/settings/connected-apps' }]}>
            <Head title={t('mcp.connected_apps')} />
            <SettingsPage
                back={{ href: '/settings', label: t('settings_hub.me') }}
                title={t('mcp.connected_apps')}
                lede={t('mcp.connected_apps_desc')}
            >
                {apps.length === 0 && <p className="text-muted-foreground text-[15px]">{t('mcp.connected_none')}</p>}
                {apps.map((app) => {
                    const Icon = iconFor(app.name);
                    const access = Object.entries(app.access);
                    return (
                        <div key={app.id} className="bg-surface-card flex flex-col gap-3.5 rounded-[22px] border p-4">
                            <div className="flex items-center gap-3.5">
                                <span className="bg-surface text-primary flex size-11 shrink-0 items-center justify-center rounded-xl">
                                    <Icon className="size-5" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="text-primary block truncate text-[17px] leading-snug">{app.name}</span>
                                    {/* The family too: a person in two families may connect one app to each. */}
                                    <span className="text-muted-foreground block truncate text-sm">
                                        {[t('mcp.connected_on', { date: date(app.connectedAt) }), app.family ?? t('mcp.unknown_family')].join(' · ')}
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    onClick={() => revoke(app)}
                                    className="text-destructive min-h-11 shrink-0 text-base font-semibold"
                                >
                                    {t('mcp.revoke')}
                                </button>
                            </div>
                            {access.length > 0 ? (
                                <div className="flex flex-wrap gap-2">
                                    {access.map(([m, level]) => (
                                        <span key={m} className="bg-surface text-primary rounded-full px-3 py-1 text-[15px] font-medium">
                                            {t(`nav.${m}`)}
                                            {/* Chips are the modules it sees; only the less common read-only grant gets a label. */}
                                            {level === 'read' && (
                                                <span className="text-muted-foreground font-normal"> · {t('settings_me.read_only')}</span>
                                            )}
                                        </span>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-sm">{t('mcp.no_access')}</p>
                            )}
                            {app.missing.length > 0 && (
                                <p className="text-muted-foreground text-sm">
                                    {t('mcp.not_included', { modules: app.missing.map((m) => t(`nav.${m}`)).join(', ') })}
                                </p>
                            )}
                        </div>
                    );
                })}

                <div className="flex flex-col gap-2.5">
                    <h2 className="text-muted-foreground px-1 font-mono text-xs font-semibold tracking-[0.12em] uppercase">
                        {t('settings_me.connect_new_app')}
                    </h2>
                    <div className="bg-surface flex min-h-14 items-center gap-2 rounded-2xl py-2 pr-2 pl-4">
                        <code className="text-primary min-w-0 flex-1 truncate font-mono text-base">{serverUrl}</code>
                        <button
                            type="button"
                            onClick={copy}
                            className="text-primary hover:bg-muted/50 flex min-h-11 shrink-0 items-center gap-1.5 rounded-xl px-3 text-base font-semibold"
                        >
                            {copied ? <Check className="size-5" /> : <Copy className="size-5" />}
                            {copied ? t('common.copied') : t('common.copy')}
                        </button>
                    </div>
                    <p className="text-muted-foreground px-1 text-[15px]">{t('settings_me.paste_hint')}</p>
                </div>
            </SettingsPage>
        </AppLayout>
    );
}
