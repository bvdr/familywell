import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, Search } from 'lucide-react';

import { FamilyMark } from '@/components/family-mark';
import { NotificationsBell } from '@/components/notifications-bell';
import { useT } from '@/lib/i18n';
import { type SharedData } from '@/types';

type Family = { name: string; color: string; symbol?: string | null; emoji?: string | null };

// The phones' top bar (the design's Top Bar, on Home and Tasks): the family, then search, alerts and the avatar,
// which opens Me (your settings, the family's settings, Log out). Desktop keeps the sidebar + breadcrumb header.
export function HomeTopBar() {
    const { auth, currentFamily: family } = usePage<SharedData & { currentFamily: Family | null }>().props;
    const t = useT();
    return (
        <div className="flex w-full items-center gap-2">
            <Link href="/family/members" className="flex min-w-0 flex-1 items-center gap-2.5">
                {family && <FamilyMark {...family} className="size-9 rounded-xl text-lg" />}
                <span className="text-primary truncate text-lg font-bold">{family?.name}</span>
                <ChevronDown className="text-muted-foreground size-4 shrink-0" />
            </Link>
            <Link
                href="/search"
                aria-label={t('common.search')}
                className="bg-surface text-primary flex size-10 shrink-0 items-center justify-center rounded-full"
            >
                <Search className="size-5" />
            </Link>
            <NotificationsBell />
            {/* Tap your face (design board 12): the avatar opens Me, where log out and the family's settings live too. */}
            <Link
                href="/settings"
                aria-label={auth.user.name}
                className="bg-primary text-primary-foreground flex size-10 shrink-0 items-center justify-center rounded-full text-base font-bold"
            >
                {auth.user.name.charAt(0).toUpperCase()}
            </Link>
        </div>
    );
}
