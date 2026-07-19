import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Drawer, DrawerContent, DrawerDescription, DrawerTitle } from '@/components/ui/drawer';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import * as React from 'react';

// Installed PWAs (display-mode: standalone) resize the webview when the keyboard opens, so the drawer
// already fits above it. Safari instead overlays the keyboard, so there vaul must reposition the
// drawer itself. Toggling repositionInputs on that distinction avoids the double-compensation that
// pushes the standalone drawer way up.
function useIsStandalone() {
    const [standalone, setStandalone] = React.useState(false);
    React.useEffect(() => {
        const nav = window.navigator as Navigator & { standalone?: boolean };
        setStandalone(window.matchMedia('(display-mode: standalone)').matches || nav.standalone === true);
    }, []);
    return standalone;
}

// Height of the visible viewport (shrinks when the keyboard opens). Used to hard-cap the standalone
// drawer so it always fits above the keyboard, even where dvh doesn't recompute.
function useViewportHeight(active: boolean) {
    const [h, setH] = React.useState<number | null>(null);
    React.useEffect(() => {
        const vv = window.visualViewport;
        if (!active || !vv) return;
        const update = () => setH(vv.height);
        vv.addEventListener('resize', update);
        update();
        return () => vv.removeEventListener('resize', update);
    }, [active]);
    return h;
}

// One overlay, two shapes: a native-feeling bottom drawer on mobile (vaul — drag-to-dismiss, momentum,
// keyboard-aware), a centered dialog on desktop. Same title / description / body API.
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
    const isStandalone = useIsStandalone();
    const vvHeight = useViewportHeight(isStandalone && open);

    if (isMobile) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange} repositionInputs={!isStandalone}>
                <DrawerContent
                    className={className}
                    // In a standalone PWA, hard-cap to the visible height so the keyboard never hides content.
                    style={isStandalone && vvHeight ? { maxHeight: vvHeight - 8 } : undefined}
                >
                    {/* min-h-0 lets this region actually scroll inside the capped drawer (and lets vaul
                        tell a scroll from a dismiss drag). It clears the keyboard because the drawer is
                        capped to the visible height. */}
                    <div className="flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain px-5 pt-1 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
                        <div className="mb-4 flex flex-col gap-1 text-left">
                            <DrawerTitle>{title}</DrawerTitle>
                            {description && <DrawerDescription>{description}</DrawerDescription>}
                        </div>
                        {children}
                    </div>
                </DrawerContent>
            </Drawer>
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
