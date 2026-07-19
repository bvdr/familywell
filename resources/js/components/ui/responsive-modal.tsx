import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import * as React from 'react';

// How much of the layout viewport the software keyboard covers. On iOS a fixed bottom sheet stays
// pinned behind the keyboard, so we lift it by this inset and cap its height to the visible area,
// keeping every field (and the Save button) reachable by scrolling — the way a native sheet behaves.
function useKeyboardInset() {
    const [inset, setInset] = React.useState(0);
    React.useEffect(() => {
        const vv = window.visualViewport;
        if (!vv) return;
        const update = () => setInset(Math.max(0, window.innerHeight - vv.height - vv.offsetTop));
        vv.addEventListener('resize', update);
        vv.addEventListener('scroll', update);
        update();
        return () => {
            vv.removeEventListener('resize', update);
            vv.removeEventListener('scroll', update);
        };
    }, []);
    return inset;
}

// One overlay, two shapes: a bottom drawer on mobile, a centered dialog on desktop. Same title /
// description / body API either way, so callers don't branch on viewport. Tall content scrolls, and
// the mobile drawer stays above the keyboard.
export function ResponsiveModal({
    open,
    onOpenChange,
    title,
    description,
    children,
    className,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    title: string;
    description?: string;
    children: React.ReactNode;
    className?: string; // extra classes for the content (e.g. a wider desktop max-width)
}) {
    const isMobile = useIsMobile();
    const keyboardInset = useKeyboardInset();

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent
                    side="bottom"
                    // Lift above the keyboard and cap to the visible area so the content can scroll.
                    style={{ bottom: keyboardInset, maxHeight: `calc(100dvh - ${keyboardInset}px - 0.5rem)`, transition: 'bottom 0.15s ease' }}
                    className={cn(
                        'touch-pan-y overflow-y-auto overscroll-contain rounded-t-2xl px-5 pt-5',
                        keyboardInset ? 'pb-5' : 'pb-[calc(2rem+env(safe-area-inset-bottom))]',
                        className,
                    )}
                >
                    <SheetHeader className="px-0 text-left">
                        <SheetTitle className="font-heading text-primary text-xl">{title}</SheetTitle>
                        {description && <SheetDescription className="text-muted-foreground text-sm">{description}</SheetDescription>}
                    </SheetHeader>
                    <div className="mt-4">{children}</div>
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={cn('max-h-[90vh] overflow-y-auto sm:max-w-md', className)}>
                <DialogHeader>
                    <DialogTitle className="font-heading text-primary text-xl">{title}</DialogTitle>
                    {description && <DialogDescription className="text-muted-foreground text-sm">{description}</DialogDescription>}
                </DialogHeader>
                <div className="mt-1">{children}</div>
            </DialogContent>
        </Dialog>
    );
}
