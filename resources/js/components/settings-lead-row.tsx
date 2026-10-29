import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

// SettingsRow (settings-ui.tsx) with any leading element instead of a Lucide icon: a member's avatar, or a module
// tile that changes colour with its switch. Same row metrics, so it sits in a SettingsGroup next to SettingsRows.
// ponytail: a copy of SettingsRow's plain (non-link) branch; fold into SettingsRow as a `lead` prop when merging.
export function SettingsLeadRow({
    lead,
    title,
    sub,
    right,
    muted,
    className,
    testId,
}: {
    lead: ReactNode;
    title: ReactNode;
    sub?: ReactNode;
    right?: ReactNode;
    muted?: boolean;
    className?: string;
    testId?: string;
}) {
    return (
        <div data-testid={testId} className={cn('flex min-h-14 w-full items-center gap-3.5 border-b px-4 py-3 text-left last:border-b-0', className)}>
            {lead}
            <span className={cn('min-w-0 flex-1', muted && 'opacity-60')}>
                <span className="text-primary block truncate text-[17px] leading-snug">{title}</span>
                {sub && <span className="text-muted-foreground block text-sm leading-snug">{sub}</span>}
            </span>
            {right}
        </div>
    );
}
