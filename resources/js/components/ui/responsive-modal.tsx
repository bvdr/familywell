import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Drawer, DrawerContent, DrawerDescription, DrawerTitle } from '@/components/ui/drawer';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import * as React from 'react';

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
            <Drawer open={open} onOpenChange={onOpenChange} repositionInputs={false}>
                <DrawerContent className={className} onOpenAutoFocus={onOpenAutoFocus}>
                    <div className="mb-3 flex shrink-0 flex-col gap-1 px-5 pt-1 text-left">
                        <DrawerTitle>{title}</DrawerTitle>
                        {description && <DrawerDescription>{description}</DrawerDescription>}
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
