import { Head } from '@inertiajs/react';
import { Globe, MapPin, Phone, Wrench } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { fmtMileage, type ProviderServiceEntry, type ServiceProvider } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

type Props = {
    provider: ServiceProvider;
    history: ProviderServiceEntry[];
    lastService: string | null;
};

export default function ServiceProviderDetail({ provider, history, lastService }: Props) {
    const t = useT();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: t('vehicles.providers'), href: '/m/vehicles/providers' },
        { title: provider.name, href: `/m/vehicles/providers/${provider.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={provider.name} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4">
                <header className="flex flex-col gap-1">
                    <h1 className="font-heading text-primary text-2xl">{provider.name}</h1>
                    {lastService && <p className="text-muted-foreground text-sm">{t('vehicles.provider_last_service', { date: lastService })}</p>}
                </header>

                {/* Contact card — only the rows that are filled in. */}
                <ContactCard provider={provider} />

                <section className="flex flex-col gap-2">
                    <h2 className="font-heading text-primary text-lg">{t('vehicles.provider_service_history')}</h2>
                    {history.length === 0 ? (
                        <p className="text-muted-foreground bg-surface-card rounded-xl border px-4 py-6 text-center text-sm">{t('vehicles.provider_no_services')}</p>
                    ) : (
                        <ul className="bg-border grid grid-cols-1 gap-px overflow-hidden rounded-2xl border">
                            {history.map((h) => (
                                <li key={h.id} className="bg-surface-card flex items-center gap-3 px-4 py-3">
                                    <span className="bg-accent-subtle text-primary flex size-8 shrink-0 items-center justify-center rounded-lg">
                                        <Wrench className="size-4" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <span className="text-primary block truncate text-sm font-medium">{h.title}</span>
                                        <span className="text-muted-foreground block truncate text-xs">
                                            {[h.date, h.vehicle, h.mileage != null ? `${fmtMileage(h.mileage)} km` : null].filter(Boolean).join(' · ')}
                                        </span>
                                    </div>
                                    {h.cost != null && (
                                        <span className="text-primary shrink-0 text-sm tabular-nums">{t('vehicles.cost_value', { value: h.cost.toFixed(2) })}</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {provider.notes && <p className="text-text-secondary bg-surface-card rounded-xl border px-4 py-3 text-sm whitespace-pre-wrap">{provider.notes}</p>}
            </div>
        </AppLayout>
    );
}

function ContactCard({ provider }: { provider: ServiceProvider }) {
    const rows: { icon: React.ReactNode; value: React.ReactNode }[] = [];
    if (provider.phone) rows.push({ icon: <Phone className="size-4" />, value: <a href={`tel:${provider.phone}`} className="hover:text-primary">{provider.phone}</a> });
    if (provider.website) {
        const href = provider.website.startsWith('http') ? provider.website : `https://${provider.website}`;
        rows.push({ icon: <Globe className="size-4" />, value: <a href={href} target="_blank" rel="noreferrer" className="hover:text-primary truncate">{provider.website}</a> });
    }
    if (provider.address) rows.push({ icon: <MapPin className="size-4" />, value: provider.address });

    if (rows.length === 0) return null;

    return (
        <section className="bg-surface-card flex flex-col gap-2 rounded-2xl border p-5">
            {rows.map((r, i) => (
                <div key={i} className="text-text-secondary flex items-center gap-2.5 text-sm">
                    <span className="text-muted-foreground shrink-0">{r.icon}</span>
                    <span className="min-w-0 truncate">{r.value}</span>
                </div>
            ))}
        </section>
    );
}
