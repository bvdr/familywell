import { Head, Link, router } from '@inertiajs/react';
import { Check, ChevronLeft, Settings, Store } from 'lucide-react';
import { useState } from 'react';

import { AisleEditor } from '@/components/aisle-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ResponsiveModal } from '@/components/ui/responsive-modal';
import { useOptimistic } from '@/hooks/use-optimistic';
import AppLayout from '@/layouts/app-layout';
import { aisleIcon } from '@/lib/shopping';
import { type BreadcrumbItem } from '@/types';

type Aisle = { slug: string; name: string; icon: string };
type Item = { id: number; name: string; quantity: string | null; department: string; bought: boolean; listName: string };
type Shop = { id: number; name: string; icon: string; color: string };

export default function ShopDetail({ shop, aisles, items }: { shop: Shop; aisles: Aisle[]; items: Item[] }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Shopping', href: '/m/shopping' },
        { title: 'Shops', href: '/m/shopping/shops' },
        { title: shop.name, href: `/m/shopping/shops/${shop.id}` },
    ];

    const boughtOpt = useOptimistic<boolean>();
    const isBought = (i: Item) => boughtOpt.get(i.id, i.bought);
    const toggle = (i: Item) => {
        boughtOpt.set(i.id, !isBought(i));
        // Empty-body PATCH flips `bought` on the item in its own list.
        router.patch(route('shopping.items.update', i.id), {}, { preserveScroll: true, preserveState: true, onSuccess: () => boughtOpt.clear(i.id), onError: () => boughtOpt.clear(i.id) });
    };

    const [settingsOpen, setSettingsOpen] = useState(false);
    const [aislesOpen, setAislesOpen] = useState(false);
    const [name, setName] = useState(shop.name);
    const saveName = () => {
        const n = name.trim();
        if (n && n !== shop.name) router.patch(route('shopping.shops.update', shop.id), { name: n }, { preserveScroll: true });
    };

    // Split into "to buy" (grouped by the shop's aisles, walk order) and a bought pile at the bottom.
    // Computed each render (not memoised) so an optimistic check moves the item immediately.
    const toBuy = items.filter((i) => !isBought(i));
    const done = items.filter((i) => isBought(i));
    const bySlug = new Map(aisles.map((a) => [a.slug, a]));
    const otherSlug = bySlug.has('other') ? 'other' : aisles[aisles.length - 1]?.slug;
    const groups = aisles
        .map((a) => ({ aisle: a, items: toBuy.filter((i) => (bySlug.has(i.department) ? i.department : otherSlug) === a.slug) }))
        .filter((g) => g.items.length > 0);

    const remaining = toBuy.length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={shop.name} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Link href="/m/shopping/shops" aria-label="Back to shops" className="text-muted-foreground hover:text-primary -ml-1 flex size-8 items-center justify-center">
                        <ChevronLeft className="size-5" />
                    </Link>
                    <span className="bg-accent-subtle text-primary flex size-9 shrink-0 items-center justify-center rounded-xl">
                        <Store className="size-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h1 className="font-heading text-primary truncate text-2xl">{shop.name}</h1>
                        <p className="text-muted-foreground text-sm">{remaining} to buy</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setSettingsOpen(true)}
                        aria-label="Shop settings"
                        className="border-border text-primary hover:bg-surface flex size-10 items-center justify-center rounded-full border"
                    >
                        <Settings className="size-5" />
                    </button>
                </div>

                {items.length === 0 && (
                    <div className="text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        Nothing needed at {shop.name} right now.
                    </div>
                )}

                {groups.map((g) => {
                    const Icon = aisleIcon(g.aisle.icon);
                    return (
                        <div key={g.aisle.slug} className="flex flex-col gap-2">
                            <div className="text-muted-foreground flex items-center gap-1.5 px-1 text-xs font-semibold tracking-widest uppercase">
                                <Icon className="size-4" /> {g.aisle.name}
                            </div>
                            {g.items.map((i) => (
                                <button
                                    key={i.id}
                                    type="button"
                                    onClick={() => toggle(i)}
                                    className="bg-surface-card flex items-center gap-3 rounded-xl border px-3 py-2.5 text-left"
                                >
                                    <span className="border-border flex size-6 shrink-0 items-center justify-center rounded-full border" />
                                    <span className="min-w-0 flex-1">
                                        <span className="text-primary block truncate text-[15px]">{i.name}</span>
                                        <span className="text-muted-foreground block truncate text-xs">
                                            {i.quantity ? `${i.quantity} · ` : ''}
                                            from {i.listName}
                                        </span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    );
                })}

                {done.length > 0 && (
                    <div className="mt-2 flex flex-col gap-2">
                        <div className="text-muted-foreground px-1 text-xs font-semibold tracking-widest uppercase">In the cart</div>
                        {done.map((i) => (
                            <button
                                key={i.id}
                                type="button"
                                onClick={() => toggle(i)}
                                className="bg-surface flex items-center gap-3 rounded-xl border px-3 py-2.5 text-left opacity-70"
                            >
                                <span className="border-primary bg-primary text-primary-foreground flex size-6 shrink-0 items-center justify-center rounded-full">
                                    <Check className="size-3.5" />
                                </span>
                                <span className="text-muted-foreground min-w-0 flex-1 truncate text-[15px] line-through">{i.name}</span>
                            </button>
                        ))}
                    </div>
                )}
            </div>

            <ResponsiveModal open={settingsOpen} onOpenChange={setSettingsOpen} title="Shop settings">
                <div className="flex flex-col gap-4">
                    <div className="grid gap-2">
                        <label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">Name</label>
                        <Input value={name} onChange={(e) => setName(e.target.value)} onBlur={saveName} onKeyDown={(e) => e.key === 'Enter' && saveName()} />
                        <p className="text-text-muted text-xs">Renaming updates every item, list and aisle that uses this shop.</p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            setSettingsOpen(false);
                            setAislesOpen(true);
                        }}
                    >
                        Edit aisles
                    </Button>
                </div>
            </ResponsiveModal>

            <AisleEditor open={aislesOpen} onOpenChange={setAislesOpen} shopId={shop.id} shopName={shop.name} reloadKeys={['items', 'aisles']} />
        </AppLayout>
    );
}
