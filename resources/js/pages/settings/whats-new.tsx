import { Head, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import { SettingsPage } from '@/components/settings-ui';
import AppLayout from '@/layouts/app-layout';
import { appLocale, useT } from '@/lib/i18n';

type Release = {
    version: string;
    date: string;
    items: { module: string; title: string; text: string; href: string | null }[];
    fixes: string[];
};

// What's new (design ntvzN W3): every release, newest first, one plain line per thing; fixes on a single line.
// Fed by resources/changelog.json (App\Support\Changelog); opening it marks the newest as seen.
export default function WhatsNew({ releases }: { releases: Release[] }) {
    const t = useT();
    const day = (d: string) => new Date(`${d}T12:00`).toLocaleDateString(appLocale(), { month: 'short', day: 'numeric' });
    return (
        <AppLayout hideMobileHeader breadcrumbs={[{ title: t('settings_hub.whats_new'), href: '/settings/whats-new' }]}>
            <Head title={t('settings_hub.whats_new')} />
            <SettingsPage back={{ href: '/settings', label: t('settings_hub.me') }} title={t('settings_hub.whats_new')}>
                {releases.map((r, k) => (
                    <section key={r.version} className="flex flex-col gap-2">
                        <h2 className="flex items-baseline gap-2 px-1">
                            <span className="text-primary text-lg font-bold">{t('settings_hub.version', { version: r.version })}</span>
                            <span className="text-muted-foreground text-sm">{day(r.date)}</span>
                            {k === 0 && (
                                <span className="bg-accent-strong text-accent-strong-foreground rounded px-1.5 font-mono text-[10px] font-bold uppercase">
                                    {t('calendar.new_badge')}
                                </span>
                            )}
                        </h2>
                        <div className="bg-surface-card divide-border flex flex-col divide-y overflow-hidden rounded-[22px] border">
                            {r.items.map((i) => (
                                <div key={i.title} className="flex gap-4 px-4 py-3.5">
                                    <span className="text-muted-foreground w-20 shrink-0 pt-0.5 font-mono text-[11px] font-semibold tracking-[0.1em] uppercase">
                                        {i.module === 'app' ? 'FamilyWell' : i.module}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="text-primary block text-base font-semibold">{i.title}</span>
                                        <span className="text-muted-foreground block text-sm">{i.text}</span>
                                        {i.href && (
                                            <Link href={i.href} className="text-primary mt-1 inline-flex items-center gap-1 text-sm font-semibold">
                                                {t('settings_hub.show_me')} <ArrowRight className="size-3.5" />
                                            </Link>
                                        )}
                                    </span>
                                </div>
                            ))}
                            {r.fixes.length > 0 && (
                                <div className="flex gap-4 px-4 py-3.5">
                                    <span className="text-muted-foreground w-20 shrink-0 pt-0.5 font-mono text-[11px] font-semibold tracking-[0.1em] uppercase">
                                        {t('settings_hub.fixes')}
                                    </span>
                                    <span className="text-primary min-w-0 flex-1 text-sm">{r.fixes.join(' · ')}</span>
                                </div>
                            )}
                        </div>
                    </section>
                ))}
            </SettingsPage>
        </AppLayout>
    );
}
