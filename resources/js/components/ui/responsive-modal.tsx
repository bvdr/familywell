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

// Pins the standalone drawer to the visible viewport. `bottom` lifts it onto the top edge of the
// keyboard (the keyboard's height, whether iOS resizes the webview or overlays it — both are covered
// because it's derived from visualViewport), and `maxHeight` caps it so it always fits above.
function useDrawerViewport(active: boolean) {
    const [box, setBox] = React.useState<{ maxHeight: number; bottom: number } | null>(null);
    React.useEffect(() => {
        const vv = window.visualViewport;
        if (!active || !vv) return;
        const update = () =>
            setBox({
                maxHeight: vv.height - 8,
                bottom: Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop)),
            });
        vv.addEventListener('resize', update);
        vv.addEventListener('scroll', update);
        update();
        return () => {
            vv.removeEventListener('resize', update);
            vv.removeEventListener('scroll', update);
        };
    }, [active]);
    return box;
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
    autoFocusRef,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    title: string;
    description?: string;
    children: React.ReactNode;
    className?: string; // extra classes for the content (e.g. a wider desktop max-width)
    autoFocusRef?: React.RefObject<HTMLElement | null>; // field to focus on open (else the container gets focus)
}) {
    const isMobile = useIsMobile();
    const isStandalone = useIsStandalone();
    const box = useDrawerViewport(isStandalone && open);

    // Radix/vaul focus the content container by default, so no field is focused and iOS shows no keyboard.
    // Steering focus to a specific field during the open keeps it inside the user gesture, so the keyboard opens.
    const onOpenAutoFocus = autoFocusRef
        ? (e: Event) => {
              e.preventDefault();
              autoFocusRef.current?.focus();
          }
        : undefined;

    if (isMobile) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange} repositionInputs={!isStandalone}>
                <DrawerContent
                    className={className}
                    onOpenAutoFocus={onOpenAutoFocus}
                    // In a standalone PWA, sit on top of the keyboard and cap to the visible height so
                    // nothing shows through below and the keyboard never hides content.
                    style={box ? { maxHeight: box.maxHeight, bottom: box.bottom } : undefined}
                >
                    {/* min-h-0 lets this region actually scroll inside the capped drawer (and lets vaul
                        tell a scroll from a dismiss drag). overflow-x-hidden keeps scrolling vertical-only.
                        It clears the keyboard because the drawer is capped to the visible height. */}
                    <div className="flex min-h-0 flex-1 flex-col overflow-x-hidden overflow-y-auto overscroll-contain px-5 pt-1 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
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
            <DialogContent className={cn('max-h-[90vh] overflow-y-auto sm:max-w-md', className)} onOpenAutoFocus={onOpenAutoFocus}>
                <DialogHeader>
                    <DialogTitle className="font-heading text-primary text-xl">{title}</DialogTitle>
                    {description && <DialogDescription className="text-muted-foreground text-sm">{description}</DialogDescription>}
                </DialogHeader>
                <div className="mt-1">{children}</div>
            </DialogContent>
        </Dialog>
    );
}
