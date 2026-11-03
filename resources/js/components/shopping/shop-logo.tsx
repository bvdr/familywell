import { Store } from 'lucide-react';

// A shop's tile: its brand logo (built-in or found by the weekly sweep, Modules\Shopping\ShopBrands) on white, else
// its first letter, so a local shop still reads at a glance. Logos are stored by us and cached for a year: no
// third party is asked while a list shows.
export function ShopLogo({ name, logo, className = 'size-10 rounded-xl' }: { name: string; logo?: string | null; className?: string }) {
    if (logo) {
        return (
            <span className={`flex shrink-0 items-center justify-center overflow-hidden border bg-white ${className}`}>
                <img src={logo} alt="" loading="lazy" decoding="async" className="size-full object-contain p-0.5" />
            </span>
        );
    }
    const letter = name.trim().charAt(0).toLocaleUpperCase();
    return (
        <span className={`bg-accent-subtle text-primary flex shrink-0 items-center justify-center font-bold ${className}`} aria-hidden>
            {letter || <Store className="size-5" />}
        </span>
    );
}
