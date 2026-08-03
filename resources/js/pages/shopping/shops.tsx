import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Store } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

type Shop = { id: number; name: string; icon: string; color: string; toBuy: number };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Shopping', href: '/m/shopping' }];

// A segmented toggle to move between the two shopping views (Lists / Shops). Kept identical on both
// pages so it reads as one control.
function ViewToggle({ active }: { active: 'lists' | 'shops' }) {
    const cell = (on: boolean) =>
        `flex-1 rounded-full px-4 py-1.5 text-sm font-medium transition-colors ${on ? 'bg-primary text-primary-foreground' : 'text-primary'}`;
    return (
        <div className="border-border bg-surface flex rounded-full border p-1">
            <Link href="/m/shopping" className={cell(active === 'lists')}>
                Lists
            </Link>
            <Link href="/m/shopping/shops" className={cell(active === 'shops')}>
                Shops
            </Link>
        </div>
    );
}

export default function Shops({ shops }: { shops: Shop[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shops" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <h1 className="font-heading text-primary text-2xl">Grocery Lists</h1>
                <ViewToggle active="shops" />

                {shops.length === 0 ? (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                        <Store className="size-6" />
                        <p className="text-sm">No shops yet. Set a store on an item and it shows up here.</p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-3">
                        {shops.map((s) => (
                            <Link
                                key={s.id}
                                href={`/m/shopping/shops/${s.id}`}
                                prefetch
                                className="bg-surface-card hover:border-accent flex items-center gap-3 rounded-2xl border p-4 text-left transition-colors"
                            >
                                <span className="bg-accent-subtle text-primary flex size-11 shrink-0 items-center justify-center rounded-xl">
                                    <Store className="size-5.5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="text-primary truncate font-medium">{s.name}</div>
                                    <div className="text-muted-foreground mt-0.5 text-xs">
                                        {s.toBuy > 0 ? `${s.toBuy} to buy` : 'nothing to buy'}
                                    </div>
                                </div>
                                <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
