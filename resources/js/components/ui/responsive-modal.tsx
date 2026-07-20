import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Drawer, DrawerClose, DrawerContent, DrawerDescription, DrawerTitle } from '@/components/ui/drawer';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { X } from 'lucide-react';
import * as React from 'react';

// Drag stops like the Google Maps sheet: a peek (drag down to glance at what's behind) and full. Opens full.
const SNAP_POINTS = [0.62, 1];

// Height of the keyboard, i.e. how far a bottom-anchored fixed element must lift to clear it.
// innerHeight is the layout viewport (unchanged by an overlaying keyboard); visualViewport.height is
// the part still visible. Their difference is the keyboard. This is self-correcting: where the platform
// already resizes the layout for the keyboard (interactive-widget=resizes-content, iOS 17+), the two
// heights match and the offset is 0, so we never lift twice.
function useKeyboardOffset(active: boolean) {
    const [offset, setOffset] = React.useState(0);
    React.useEffect(() => {
        const vv = window.visualViewport;
        if (!active || !vv) {
            setOffset(0);
            return;
        }
        const update = () => setOffset(Math.max(0, Math.round(window.innerHeight - vv.height)));
        vv.addEventListener('resize', update);
        vv.addEventListener('scroll', update);
        update();
        return () => {
            vv.removeEventListener('resize', update);
            vv.removeEventListener('scroll', update);
        };
    }, [active]);
    return offset;
}

// One overlay, two shapes: a native-feeling bottom drawer on mobile (vaul — drag-to-dismiss, momentum),
// a centered dialog on desktop. Same title / description / body / footer API.
//
// Keyboard-safe sheet layout: the title and the footer (e.g. a Save button) are pinned, and only the
// body between them scrolls. So no matter how the keyboard resizes or overlays the viewport, the header
// and the primary action stay put and the fields scroll under them. interactive-widget=resizes-content
// (viewport meta) shrinks the layout viewport for the keyboard, so the pinned footer lands right above it.
export function ResponsiveModal({
    open,
    onOpenChange,
    title,
    description,
    children,
    footer,
    className,
    autoFocusRef,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    title: string;
    description?: string;
    children: React.ReactNode;
    footer?: React.ReactNode; // pinned to the bottom (stays above the keyboard); e.g. a Save button
    className?: string; // extra classes for the content (e.g. a wider desktop max-width)
    autoFocusRef?: React.RefObject<HTMLElement | null>; // field to focus on open (else the container gets focus)
}) {
    const isMobile = useIsMobile();
    const keyboardOffset = useKeyboardOffset(isMobile && open);

    // Snap to full whenever it opens, so the form is immediately usable; dragging down peeks at the page behind.
    const [snap, setSnap] = React.useState<number | string | null>(1);
    React.useEffect(() => {
        if (open) setSnap(1);
    }, [open]);

    // Radix/vaul focus the content container by default, so no field is focused and iOS shows no keyboard.
    // Steering focus to a specific field during the open keeps it inside the user gesture, so the keyboard
    // opens. preventScroll stops the browser from yanking that field to the top (which scrolls the header off).
    const onOpenAutoFocus = autoFocusRef
        ? (e: Event) => {
              e.preventDefault();
              autoFocusRef.current?.focus({ preventScroll: true });
          }
        : undefined;

    if (isMobile) {
        return (
            <Drawer
                open={open}
                onOpenChange={onOpenChange}
                repositionInputs={false}
                snapPoints={SNAP_POINTS}
                activeSnapPoint={snap}
                setActiveSnapPoint={setSnap}
            >
                <DrawerContent
                    className={className}
                    onOpenAutoFocus={onOpenAutoFocus}
                    // Lift the whole sheet above the keyboard and cap it to the visible height, so the pinned
                    // footer and fields stay in view. Both are no-ops (0 / 94dvh) when no keyboard is open.
                    style={keyboardOffset ? { bottom: keyboardOffset, maxHeight: `calc(100dvh - ${keyboardOffset}px - 1.5rem)` } : undefined}
                >
                    <div className="mb-3 flex shrink-0 items-start justify-between gap-3 px-5 pt-1">
                        <div className="flex flex-col gap-1 text-left">
                            <DrawerTitle>{title}</DrawerTitle>
                            {description && <DrawerDescription>{description}</DrawerDescription>}
                        </div>
                        <DrawerClose
                            aria-label="Close"
                            className="text-muted-foreground hover:bg-surface hover:text-foreground -mr-1.5 -mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full transition-colors"
                        >
                            <X className="size-5" />
                        </DrawerClose>
                    </div>
                    {/* Only this region scrolls. min-h-0 lets it shrink inside the flex column so it actually
                        scrolls (and lets vaul tell a scroll from a dismiss drag); overflow-x-hidden keeps it vertical. */}
                    <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-5 pb-2">{children}</div>
                    {footer && (
                        <div className="border-border/60 shrink-0 border-t px-5 pt-3 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                            {footer}
                        </div>
                    )}
                </DrawerContent>
            </Drawer>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={cn('flex max-h-[90vh] flex-col sm:max-w-md', className)} onOpenAutoFocus={onOpenAutoFocus}>
                <DialogHeader>
                    <DialogTitle className="font-heading text-primary text-xl">{title}</DialogTitle>
                    {description && <DialogDescription className="text-muted-foreground text-sm">{description}</DialogDescription>}
                </DialogHeader>
                <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
                {footer && <div className="mt-2 shrink-0">{footer}</div>}
            </DialogContent>
        </Dialog>
    );
}
