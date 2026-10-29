import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

// The settings look (design boards 11 "You & family" and 12 "Me"), shared by every settings page so they all read
// like onboarding: a round back pill, a big display title, a grey line under it, then mono section labels over
// rounded groups of rows. Colours come from the theme tokens (not onboarding's fixed paper/sun), so the family's
// skin and dark mode still apply.

/** The page frame: back pill, title, optional lede, then the content. One column on every width. */
export function SettingsPage({
    back,
    title,
    lede,
    children,
    className,
}: {
    back: { href: string; label: string };
    /** Omitted when the page draws its own heading (Me: the person's name). */
    title?: ReactNode;
    lede?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('mx-auto flex w-full max-w-xl flex-col gap-6 px-4 pt-4 pb-10', className)}>
            <Link
                href={back.href}
                prefetch
                className="bg-surface text-primary inline-flex min-h-11 items-center gap-1.5 self-start rounded-full py-2 pr-4 pl-3 text-base font-semibold"
            >
                <ChevronLeft className="size-5" />
                {back.label}
            </Link>
            {title && (
                <div className="flex flex-col gap-2">
                    <h1 className="font-display text-primary text-[34px] leading-[1.02] font-bold tracking-[-1.2px] text-balance md:text-[40px]">
                        {title}
                    </h1>
                    {lede && <p className="text-muted-foreground text-[15px] leading-normal">{lede}</p>}
                </div>
            )}
            {children}
        </div>
    );
}

/** A labelled group of rows ("YOU", "FAMILY"); `aside` sits right of the label ("Drag to reorder"). */
export function SettingsGroup({ label, aside, children }: { label?: string; aside?: ReactNode; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-2.5">
            {(label || aside) && (
                <div className="flex items-baseline justify-between gap-3 px-1">
                    {label && <h2 className="text-muted-foreground font-mono text-xs font-semibold tracking-[0.12em] uppercase">{label}</h2>}
                    {aside && <span className="text-muted-foreground text-sm">{aside}</span>}
                </div>
            )}
            <div className="bg-surface-card divide-border overflow-hidden rounded-[22px] border">{children}</div>
        </section>
    );
}

type RowProps = {
    icon?: LucideIcon;
    /** A tile behind the icon (module rows); plain icon otherwise. */
    tile?: boolean;
    title: ReactNode;
    sub?: ReactNode;
    /** The current value, right-aligned before the chevron ("English", "8 of 9 on"). */
    value?: ReactNode;
    href?: string;
    onClick?: () => void;
    /** Replaces the chevron (a switch, a button). */
    right?: ReactNode;
    muted?: boolean;
    className?: string;
};

/** One row: icon, title (+ sub line), value, chevron. A link, a button, or plain (with `right`). */
export function SettingsRow({ icon: Icon, tile, title, sub, value, href, onClick, right, muted, className }: RowProps) {
    const body = (
        <>
            {Icon &&
                (tile ? (
                    <span className="bg-surface text-primary flex size-11 shrink-0 items-center justify-center rounded-xl">
                        <Icon className="size-5" />
                    </span>
                ) : (
                    <Icon className="text-primary size-5 shrink-0" />
                ))}
            <span className={cn('min-w-0 flex-1', muted && 'opacity-60')}>
                <span className="text-primary block truncate text-[17px] leading-snug">{title}</span>
                {sub && <span className="text-muted-foreground block text-sm leading-snug">{sub}</span>}
            </span>
            {value !== undefined && value !== null && value !== '' && (
                <span className="text-muted-foreground max-w-[45%] shrink-0 truncate text-right text-base">{value}</span>
            )}
            {right ?? ((href || onClick) && <ChevronRight className="text-muted-foreground size-5 shrink-0" />)}
        </>
    );
    const cls = cn('flex min-h-14 w-full items-center gap-3.5 border-b px-4 py-3 text-left last:border-b-0', className);
    if (href) {
        return (
            <Link href={href} prefetch className={cn(cls, 'hover:bg-muted/50')}>
                {body}
            </Link>
        );
    }
    if (onClick) {
        return (
            <button type="button" onClick={onClick} className={cn(cls, 'hover:bg-muted/50')}>
                {body}
            </button>
        );
    }
    return <div className={cls}>{body}</div>;
}

/** The design's switch: dark track with a sun knob when on, pale when off. */
export function SettingsSwitch({ on, onToggle, label, disabled }: { on: boolean; onToggle: () => void; label: string; disabled?: boolean }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            aria-label={label}
            disabled={disabled}
            onClick={onToggle}
            className={cn('relative h-8 w-[52px] shrink-0 rounded-full transition-colors disabled:opacity-50', on ? 'bg-primary' : 'bg-muted border')}
        >
            <span
                className={cn(
                    'absolute top-1 size-6 rounded-full shadow-sm transition-all',
                    on ? 'bg-accent-strong left-[24px]' : 'bg-surface-card left-1',
                )}
            />
        </button>
    );
}
