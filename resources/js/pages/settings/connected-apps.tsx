import { Head, router } from '@inertiajs/react';
import { Check, Copy, Plug } from 'lucide-react';
import { useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
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

// Settings > Connected apps: AI assistants connected through the MCP server (see docs/mcp.md), what each
// may do, and Disconnect (revokes the token + its refresh token, so the app loses access immediately).
export default function ConnectedApps({ apps, serverUrl }: { apps: App[]; serverUrl: string }) {
    const t = useT();
    const locale = useLocale();
    const [copied, setCopied] = useState(false);
    const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' }) : '');

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
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('mcp.connected_apps')} description={t('mcp.connected_apps_desc')} />

                    <div className="grid gap-2">
                        <span className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{t('mcp.server_url')}</span>
                        <div className="border-border flex items-center gap-2 rounded-lg border p-2 pl-3">
                            <code className="min-w-0 flex-1 truncate text-sm">{serverUrl}</code>
                            <Button type="button" size="sm" variant="ghost" onClick={copy} aria-label={t('common.copy')}>
                                {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                                {copied ? t('common.copied') : t('common.copy')}
                            </Button>
                        </div>
                    </div>

                    {apps.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('mcp.connected_none')}</p>
                    ) : (
                        <ul className="divide-border border-border divide-y rounded-xl border">
                            {apps.map((app) => {
                                const access = Object.entries(app.access);
                                return (
                                    <li key={app.id} className="flex items-start gap-3 p-4">
                                        <Plug className="text-muted-foreground mt-0.5 size-5 shrink-0" />
                                        <div className="min-w-0 flex-1 space-y-1">
                                            <div className="font-medium">{app.name}</div>
                                            <div className="text-muted-foreground text-sm">{app.family ?? t('mcp.unknown_family')}</div>
                                            <div className="text-sm">
                                                {access.length
                                                    ? access.map(([m, level]) => `${t(`nav.${m}`)}: ${t(`mcp.level_${level}`)}`).join(' · ')
                                                    : t('mcp.no_access')}
                                            </div>
                                            {app.missing.length > 0 && (
                                                <div className="text-muted-foreground text-sm">
                                                    {t('mcp.not_included', { modules: app.missing.map((m) => t(`nav.${m}`)).join(', ') })}
                                                </div>
                                            )}
                                            <div className="text-muted-foreground text-xs">
                                                {t('mcp.connected_on', { date: date(app.connectedAt) })}
                                                {app.until && ` · ${t('mcp.expires_on', { date: date(app.until) })}`}
                                            </div>
                                        </div>
                                        <Button type="button" size="sm" variant="outline" onClick={() => revoke(app)}>
                                            {t('mcp.revoke')}
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
