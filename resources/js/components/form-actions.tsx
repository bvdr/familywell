import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

// Bottom action row (Save / Delete) for FULL-PAGE forms rendered inside AppLayout.
//
// Why sticky: the app layout already pads the page bottom by the floating tab bar's height, but that only
// helps when the page can scroll all the way to its end. On the installed iOS PWA it can't while the
// keyboard overlays the page (`interactive-widget=resizes-content` is not honoured there, see
// responsive-modal.tsx), and the fixed tab bar then sat on top of the Save button, so it was unreachable.
// Pinning the row just above the bar keeps the primary action tappable regardless of scroll position.
//
// The offset is the same "clear of the tab bar" expression add-to-home-screen-hint.tsx uses
// (5rem + safe-area inset). From md: there is no tab bar, so the row goes back to normal flow.
// Drawer forms don't need this: ResponsiveModal already pins its footer.
export function FormActions({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div
            className={cn(
                'bg-background/95 sticky bottom-[calc(5rem+env(safe-area-inset-bottom))] z-10 -mx-4 flex items-center gap-3 px-4 py-3 backdrop-blur',
                'md:static md:mx-0 md:px-0 md:py-0 md:backdrop-blur-none',
                className,
            )}
        >
            {children}
        </div>
    );
}
